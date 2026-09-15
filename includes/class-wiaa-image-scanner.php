<?php
/**
 * Media Library image scanner.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Image_Scanner {

	const META_CANDIDATE     = '_wiaa_candidate_alt';
	const META_STATUS        = '_wiaa_generation_status';
	const META_LAST_ERROR    = '_wiaa_last_error';
	const META_MODEL         = '_wiaa_model';
	const META_GENERATED_AT  = '_wiaa_generated_at';
	const META_APPLIED_ALT   = '_wiaa_applied_alt';
	const META_APPLIED_AT    = '_wiaa_applied_at';
	const META_REVIEWED      = '_wiaa_reviewed';
	const META_REVIEWED_AT   = '_wiaa_reviewed_at';
	const META_INTENT        = '_wiaa_alt_intent';
	const META_AI_NOTE       = '_wiaa_ai_note';
	const META_API_DEBUG     = '_wiaa_api_debug';

	/**
	 * MIME types supported by the current DeepSeek Vision model.
	 *
	 * @return array<int,string>
	 */
	public function get_supported_mime_types() {
		return array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
		);
	}

	/**
	 * Query Media Library images by workflow state.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status'   => 'pending',
				'search'   => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$page     = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 10, absint( $args['per_page'] ) ) );
		$status   = sanitize_key( (string) $args['status'] );
		$search   = sanitize_text_field( (string) $args['search'] );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			's'              => $search,
		);

		$meta_query = $this->build_meta_query( $status );
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$items[] = $this->format_item( $attachment );
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Build exclusive workflow-state queries.
	 *
	 * @param string $status Filter status.
	 * @return array<int|string,mixed>
	 */
	private function build_meta_query( $status ) {
		$alt_missing = array(
			'relation' => 'OR',
			array(
				'key'     => '_wp_attachment_image_alt',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wp_attachment_image_alt',
				'value'   => '',
				'compare' => '=',
			),
		);

		switch ( $status ) {
			case 'candidate':
				return array(
					'relation' => 'AND',
					$alt_missing,
					array(
						'key'     => self::META_STATUS,
						'value'   => 'candidate',
						'compare' => '=',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_REVIEWED,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_REVIEWED,
							'value'   => '1',
							'compare' => '!=',
						),
					),
				);

			case 'reviewed':
				return array(
					'relation' => 'AND',
					$alt_missing,
					array(
						'key'     => self::META_STATUS,
						'value'   => 'candidate',
						'compare' => '=',
					),
					array(
						'key'     => self::META_REVIEWED,
						'value'   => '1',
						'compare' => '=',
					),
				);

			case 'failed':
				return array(
					'relation' => 'AND',
					$alt_missing,
					array(
						'key'     => self::META_STATUS,
						'value'   => 'failed',
						'compare' => '=',
					),
				);

			case 'no_alt':
				return array(
					'relation' => 'AND',
					$alt_missing,
					array(
						'key'     => self::META_STATUS,
						'value'   => 'no_alt',
						'compare' => '=',
					),
				);

			case 'complete':
				return array(
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '!=',
					),
				);

			case 'all':
				return array();

			case 'missing': // v1.0.0 URL compatibility.
			case 'pending':
			default:
				return array(
					'relation' => 'AND',
					$alt_missing,
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STATUS,
							'value'   => array( '', 'missing', 'processing' ),
							'compare' => 'IN',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_CANDIDATE,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_CANDIDATE,
							'value'   => '',
							'compare' => '=',
						),
					),
				);
		}
	}


	/**
	 * Return attachment IDs for a workflow state without pagination.
	 *
	 * Used by persistent site-wide tasks. The task stores a snapshot of IDs and
	 * rechecks each item before changing it.
	 *
	 * @param string $status Workflow status.
	 * @return array<int,int>
	 */
	public function get_ids_by_status( $status ) {
		$status = sanitize_key( (string) $status );

		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$meta_query = $this->build_meta_query( $status );
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new WP_Query( $query_args );
		return array_values( array_map( 'absint', $query->posts ) );
	}

	/**
	 * Format a media item for the admin UI.
	 *
	 * @param WP_Post $attachment Attachment.
	 * @return array<string,mixed>
	 */
	public function format_item( WP_Post $attachment ) {
		$attachment_id = (int) $attachment->ID;
		$alt           = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$candidate     = trim( (string) get_post_meta( $attachment_id, self::META_CANDIDATE, true ) );
		$status        = (string) get_post_meta( $attachment_id, self::META_STATUS, true );
		$error         = (string) get_post_meta( $attachment_id, self::META_LAST_ERROR, true );
		$mime          = (string) get_post_mime_type( $attachment_id );
		$file          = get_attached_file( $attachment_id );
		$url           = wp_get_attachment_url( $attachment_id );
		$parent        = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$reviewed      = '1' === (string) get_post_meta( $attachment_id, self::META_REVIEWED, true );
		$intent        = sanitize_key( (string) get_post_meta( $attachment_id, self::META_INTENT, true ) );

		if ( '' !== $alt ) {
			$display_status = 'complete';
		} elseif ( 'no_alt' === $status ) {
			$display_status = 'no_alt';
		} elseif ( 'failed' === $status ) {
			$display_status = 'failed';
		} elseif ( 'candidate' === $status || '' !== $candidate ) {
			$display_status = $reviewed ? 'reviewed' : 'candidate';
		} else {
			$display_status = 'pending';
		}

		return array(
			'id'           => $attachment_id,
			'title'        => get_the_title( $attachment_id ),
			'filename'     => $file ? wp_basename( $file ) : '',
			'url'          => $url ? $url : '',
			'thumb_url'    => wp_get_attachment_image_url( $attachment_id, 'medium' ),
			'mime'         => $mime,
			'supported'    => in_array( $mime, $this->get_supported_mime_types(), true ),
			'alt'          => $alt,
			'candidate'    => $candidate,
			'status'       => $display_status,
			'error'        => $error,
			'model'        => (string) get_post_meta( $attachment_id, self::META_MODEL, true ),
			'generated_at' => (string) get_post_meta( $attachment_id, self::META_GENERATED_AT, true ),
			'reviewed'     => $reviewed,
			'reviewed_at'  => (string) get_post_meta( $attachment_id, self::META_REVIEWED_AT, true ),
			'intent'       => $intent,
			'ai_note'      => (string) get_post_meta( $attachment_id, self::META_AI_NOTE, true ),
			'api_debug'    => (string) get_post_meta( $attachment_id, self::META_API_DEBUG, true ),
			'parent_id'    => $parent ? (int) $parent->ID : 0,
			'parent_title' => $parent ? get_the_title( $parent ) : '',
			'parent_type'  => $parent ? (string) $parent->post_type : '',
			'edit_url'     => get_edit_post_link( $attachment_id, 'raw' ),
		);
	}

	/**
	 * Return exclusive workflow counts.
	 *
	 * @return array<string,int>
	 */
	public function get_counts() {
		global $wpdb;

		$posts    = $wpdb->posts;
		$postmeta = $wpdb->postmeta;

		$base = "FROM {$posts} p
			LEFT JOIN {$postmeta} alt
				ON alt.post_id = p.ID
				AND alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$postmeta} status_meta
				ON status_meta.post_id = p.ID
				AND status_meta.meta_key = '" . esc_sql( self::META_STATUS ) . "'
			LEFT JOIN {$postmeta} candidate_meta
				ON candidate_meta.post_id = p.ID
				AND candidate_meta.meta_key = '" . esc_sql( self::META_CANDIDATE ) . "'
			LEFT JOIN {$postmeta} reviewed_meta
				ON reviewed_meta.post_id = p.ID
				AND reviewed_meta.meta_key = '" . esc_sql( self::META_REVIEWED ) . "'
			WHERE p.post_type = 'attachment'
				AND p.post_status = 'inherit'
				AND p.post_mime_type LIKE 'image/%'";

		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND (alt.meta_value IS NULL OR alt.meta_value = '')
				AND (status_meta.meta_value IS NULL OR status_meta.meta_value IN ('', 'missing', 'processing'))
				AND (candidate_meta.meta_value IS NULL OR candidate_meta.meta_value = '')"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$candidate = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND (alt.meta_value IS NULL OR alt.meta_value = '')
				AND status_meta.meta_value = 'candidate'
				AND (reviewed_meta.meta_value IS NULL OR reviewed_meta.meta_value <> '1')"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$reviewed = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND (alt.meta_value IS NULL OR alt.meta_value = '')
				AND status_meta.meta_value = 'candidate'
				AND reviewed_meta.meta_value = '1'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$complete = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND alt.meta_value IS NOT NULL
				AND alt.meta_value <> ''"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$no_alt = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND (alt.meta_value IS NULL OR alt.meta_value = '')
				AND status_meta.meta_value = 'no_alt'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$failed = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}
				AND (alt.meta_value IS NULL OR alt.meta_value = '')
				AND status_meta.meta_value = 'failed'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		$all = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) {$base}"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'pending'   => $pending,
			'candidate' => $candidate,
			'reviewed'  => $reviewed,
			'complete'  => $complete,
			'no_alt'    => $no_alt,
			'failed'    => $failed,
			'all'       => $all,
		);
	}
}
