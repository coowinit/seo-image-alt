<?php
/**
 * Build concise WordPress context for one image.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Image_Context {

	/**
	 * Build context text.
	 *
	 * The filter allows a product/content-model plugin to append reliable
	 * domain fields without coupling this plugin to one specific schema.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function build( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return '';
		}

		$file    = get_attached_file( $attachment_id );
		$parent  = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$context = array();

		$context['image_filename'] = $file ? wp_basename( $file ) : '';
		$context['attachment_title'] = get_the_title( $attachment_id );

		if ( '' !== trim( (string) $attachment->post_excerpt ) ) {
			$context['attachment_caption'] = wp_strip_all_tags( $attachment->post_excerpt );
		}

		if ( $parent ) {
			$context['parent_post_type'] = $parent->post_type;
			$context['parent_title']     = get_the_title( $parent );

			$excerpt = has_excerpt( $parent )
				? get_the_excerpt( $parent )
				: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $parent->post_content ) ), 45, '…' );

			if ( '' !== trim( $excerpt ) ) {
				$context['parent_summary'] = $excerpt;
			}

			$terms = array();

			foreach ( get_object_taxonomies( $parent->post_type, 'names' ) as $taxonomy ) {
				if ( in_array( $taxonomy, array( 'post_format' ), true ) ) {
					continue;
				}

				$post_terms = wp_get_post_terms( $parent->ID, $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
					$terms[ $taxonomy ] = array_slice( array_values( $post_terms ), 0, 8 );
				}
			}

			if ( ! empty( $terms ) ) {
				$context['parent_terms'] = $terms;
			}

			$seo_keywords = array_filter(
				array(
					'yoast_focus_keyword' => get_post_meta( $parent->ID, '_yoast_wpseo_focuskw', true ),
					'rank_math_focus_keyword' => get_post_meta( $parent->ID, 'rank_math_focus_keyword', true ),
				)
			);

			if ( ! empty( $seo_keywords ) ) {
				$context['seo_context'] = $seo_keywords;
			}
		}

		/**
		 * Filter the structured image context.
		 *
		 * Example:
		 * add_filter( 'wiaa_image_context', function( $context, $attachment_id, $parent ) {
		 *     $context['product_model'] = 'CPD-03';
		 *     return $context;
		 * }, 10, 3 );
		 */
		$context = apply_filters( 'wiaa_image_context', $context, $attachment_id, $parent );

		return wp_json_encode(
			$context,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}
}
