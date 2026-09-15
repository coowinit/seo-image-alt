<?php
/**
 * One-time migration for legacy JSON-shaped ALT candidates.
 *
 * Older plugin versions could store the entire AI JSON payload inside
 * _wiaa_candidate_alt when a response was treated as plain text. This
 * migrator repairs those records locally without calling any AI API.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIAA_Legacy_Candidate_Migrator {

	const MIGRATION_VERSION = 'legacy-json-v1';
	const OPTION_VERSION    = 'wiaa_legacy_candidate_migration_version';
	const BATCH_SIZE        = 500;

	/** @var WIAA_Alt_Generator */
	private $generator;

	public function __construct( WIAA_Alt_Generator $generator ) {
		$this->generator = $generator;
	}

	/**
	 * Register the one-time migration and its admin notice.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_migrate' ), 20 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Run once for an administrator when legacy candidates need compatibility repair.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		if ( ! current_user_can( WIAA_CAP_MANAGE ) ) {
			return;
		}

		$current = (string) get_option( self::OPTION_VERSION, '' );
		if ( self::MIGRATION_VERSION === $current ) {
			return;
		}

		$result = $this->migrate();

		update_option( self::OPTION_VERSION, self::MIGRATION_VERSION, false );

		if ( $result['repaired'] > 0 || $result['failed'] > 0 ) {
			set_transient(
				'wiaa_legacy_migration_notice_' . get_current_user_id(),
				$result,
				10 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Repair legacy candidates in small database batches.
	 *
	 * @return array{scanned:int,legacy:int,repaired:int,reset_reviewed:int,failed:int}
	 */
	private function migrate() {
		global $wpdb;

		$result = array(
			'scanned'        => 0,
			'legacy'         => 0,
			'repaired'       => 0,
			'reset_reviewed' => 0,
			'failed'         => 0,
		);

		$last_meta_id = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_value
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
						AND meta_id > %d
					ORDER BY meta_id ASC
					LIMIT %d",
					WIAA_Image_Scanner::META_CANDIDATE,
					$last_meta_id,
					self::BATCH_SIZE
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_meta_id = max( $last_meta_id, (int) $row['meta_id'] );
				$result['scanned']++;

				$attachment_id = (int) $row['post_id'];
				$raw           = is_string( $row['meta_value'] ) ? trim( $row['meta_value'] ) : '';

				if ( '' === $raw || ! $this->looks_like_legacy_json( $raw ) ) {
					continue;
				}

				$attachment = get_post( $attachment_id );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					continue;
				}

				// Do not touch records that already have a live native ALT.
				if ( '' !== trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
					continue;
				}

				$result['legacy']++;
				$analysis = $this->generator->normalize_ai_payload( $raw );

				$intent = isset( $analysis['type'] ) ? sanitize_key( (string) $analysis['type'] ) : 'content';
				$alt    = isset( $analysis['alt'] ) ? trim( (string) $analysis['alt'] ) : '';
				$reason = isset( $analysis['reason'] ) ? sanitize_text_field( (string) $analysis['reason'] ) : '';

				if ( ! in_array( $intent, array( 'content', 'decorative', 'uncertain' ), true ) ) {
					$intent = 'content';
				}

				// A content image must have a usable extracted ALT. If parsing could not
				// improve the raw JSON-like payload, leave the record untouched.
				if ( 'content' === $intent && ( '' === $alt || $alt === $raw ) ) {
					$result['failed']++;
					continue;
				}

				$was_reviewed = '1' === (string) get_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, true );

				if ( '' !== $alt ) {
					update_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE, $alt );
				} else {
					delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_CANDIDATE );
				}

				update_post_meta( $attachment_id, WIAA_Image_Scanner::META_STATUS, 'candidate' );
				update_post_meta( $attachment_id, WIAA_Image_Scanner::META_INTENT, $intent );
				update_post_meta(
					$attachment_id,
					WIAA_Image_Scanner::META_AI_NOTE,
					'' !== $reason ? $reason : '历史 JSON 候选已本地修复，请重新审核。'
				);

				// The visible candidate changed, so any previous approval is invalid.
				update_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED, '0' );
				delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_REVIEWED_AT );
				delete_post_meta( $attachment_id, WIAA_Image_Scanner::META_LAST_ERROR );

				$result['repaired']++;
				if ( $was_reviewed ) {
					$result['reset_reviewed']++;
				}
			}
		} while ( count( $rows ) === self::BATCH_SIZE );

		return $result;
	}

	/**
	 * Only target payloads that clearly look like the old structured response.
	 * Normal human-written ALT strings are never migrated.
	 *
	 * @param string $raw Candidate value.
	 * @return bool
	 */
	private function looks_like_legacy_json( $raw ) {
		return (bool) preg_match(
			'/^\s*\{|"(?:type|alt|reason)"\s*:/iu',
			$raw
		);
	}

	/**
	 * Show a one-time migration result to the administrator who triggered it.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( WIAA_CAP_MANAGE ) ) {
			return;
		}

		$key    = 'wiaa_legacy_migration_notice_' . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( $key );

		$class = ! empty( $result['failed'] ) ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p>
				<strong>WEM Image ALT Assistant：</strong>
				<?php
				echo esc_html(
					sprintf(
						'发现旧格式候选 %1$d 条，成功本地修复 %2$d 条；其中 %3$d 条原已审核记录已重置为“待审核”。失败 %4$d 条。本次迁移不会调用 DeepSeek API。',
						(int) $result['legacy'],
						(int) $result['repaired'],
						(int) $result['reset_reviewed'],
						(int) $result['failed']
					)
				);
				?>
			</p>
		</div>
		<?php
	}
}
