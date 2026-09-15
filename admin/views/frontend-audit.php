<?php
/**
 * Frontend ALT audit page.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'synced'           => array( '前台已同步', 'success' ),
	'frontend_missing' => array( '前台仍为空', 'warning' ),
	'different'        => array( '前台与媒体库不同', 'warning' ),
	'both_empty'       => array( '两边都为空', 'neutral' ),
	'frontend_only'    => array( '仅前台有 ALT', 'neutral' ),
	'unmapped'         => array( '无法映射媒体库', 'neutral' ),
);
?>
<div class="wrap wiaa-wrap wiaa-audit-page">
	<div class="wiaa-header">
		<div>
			<h1>前台 ALT 验证</h1>
			<p>Read-only Frontend Audit · Media ALT ≠ 一定等于 Frontend HTML ALT</p>
		</div>
		<span class="wiaa-version">v<?php echo esc_html( WIAA_VERSION ); ?></span>
	</div>

	<div class="wiaa-intro">
		<div>
			<span class="wiaa-eyebrow">Frontend ALT Audit</span>
			<h2>验证媒体库 ALT 是否真正出现在前台 HTML</h2>
			<p>输入当前网站的一篇文章、页面或产品 URL。插件只读取最终前台 HTML 并与媒体库 ALT 对比，不修改文章正文、Elementor 数据或媒体库。</p>
		</div>
		<span class="wiaa-badge is-ready">只读检查</span>
	</div>

	<section class="wiaa-card wiaa-audit-form-card">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wem-image-alt-assistant-audit">
			<?php wp_nonce_field( 'wiaa_frontend_audit', '_wiaa_nonce', false ); ?>
			<div class="wiaa-audit-form-row">
				<label for="wiaa-audit-target">本站页面 URL 或文章 ID</label>
				<input type="text" id="wiaa-audit-target" name="wiaa_audit_target" value="<?php echo esc_attr( $target ); ?>" placeholder="https://example.com/sample-page/ 或 123">
				<button type="submit" class="button button-primary">开始验证</button>
			</div>
			<p class="description">建议分别测试：普通文章、Gutenberg、Elementor、产品 / 动态模板页面。当前只解析可对应到具体 WordPress 内容的本站 URL。</p>
		</form>
	</section>

	<?php if ( is_wp_error( $audit ) ) : ?>
		<div class="notice notice-error"><p><strong>验证失败：</strong><?php echo esc_html( $audit->get_error_message() ); ?></p></div>
	<?php elseif ( is_array( $audit ) ) : ?>
		<?php $counts = $audit['counts']; ?>

		<div class="wiaa-audit-target">
			<div>
				<strong><?php echo esc_html( $audit['post_title'] ); ?></strong>
				<span><?php echo esc_html( $audit['post_type'] ); ?> · ID <?php echo esc_html( $audit['post_id'] ); ?></span>
			</div>
			<a href="<?php echo esc_url( $audit['url'] ); ?>" target="_blank" rel="noopener noreferrer">打开前台 ↗</a>
		</div>

		<div class="wiaa-audit-summary">
			<div><strong><?php echo esc_html( $counts['total'] ); ?></strong><span>前台图片</span></div>
			<div><strong><?php echo esc_html( $counts['mapped'] ); ?></strong><span>映射到媒体库</span></div>
			<div><strong><?php echo esc_html( $counts['synced'] ); ?></strong><span>前台已同步</span></div>
			<div class="<?php echo $counts['frontend_missing'] ? 'is-warning' : ''; ?>"><strong><?php echo esc_html( $counts['frontend_missing'] ); ?></strong><span>媒体有 / 前台空</span></div>
			<div><strong><?php echo esc_html( $counts['different'] ); ?></strong><span>ALT 不一致</span></div>
			<div><strong><?php echo esc_html( $counts['unmapped'] ); ?></strong><span>无法映射</span></div>
		</div>

		<?php $conclusion = $audit['conclusion']; ?>
		<div class="wiaa-audit-conclusion is-<?php echo esc_attr( $conclusion['type'] ); ?>">
			<strong><?php echo esc_html( $conclusion['title'] ); ?></strong>
			<p><?php echo esc_html( $conclusion['message'] ); ?></p>
		</div>

		<?php if ( empty( $audit['rows'] ) ) : ?>
			<div class="wiaa-empty"><h3>本页没有检测到 &lt;img&gt;</h3></div>
		<?php else : ?>
			<div class="wiaa-table-wrap">
				<table class="widefat striped wiaa-table wiaa-audit-table">
					<thead>
					<tr>
						<th>图片</th>
						<th>Attachment</th>
						<th>媒体库 ALT</th>
						<th>前台 HTML ALT</th>
						<th>结论</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $audit['rows'] as $row ) : ?>
						<?php $label = isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : array( $row['status'], 'neutral' ); ?>
						<tr>
							<td class="wiaa-audit-image">
								<?php if ( $row['thumb_url'] ) : ?>
									<img src="<?php echo esc_url( $row['thumb_url'] ); ?>" alt="">
								<?php endif; ?>
								<code title="<?php echo esc_attr( $row['src'] ); ?>"><?php echo esc_html( wp_basename( (string) wp_parse_url( $row['src'], PHP_URL_PATH ) ) ); ?></code>
							</td>
							<td>
								<?php if ( $row['attachment_id'] ) : ?>
									<strong>#<?php echo esc_html( $row['attachment_id'] ); ?></strong>
									<span><?php echo esc_html( $row['title'] ); ?></span>
									<?php if ( $row['edit_url'] ) : ?><a href="<?php echo esc_url( $row['edit_url'] ); ?>">媒体库 ↗</a><?php endif; ?>
								<?php else : ?>
									<span class="wiaa-muted">未识别</span>
								<?php endif; ?>
							</td>
							<td><?php echo '' !== $row['media_alt'] ? esc_html( $row['media_alt'] ) : '<span class="wiaa-muted">空</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td>
								<?php if ( '' !== $row['frontend_alt'] ) : ?>
									<?php echo esc_html( $row['frontend_alt'] ); ?>
								<?php else : ?>
									<span class="wiaa-muted"><?php echo $row['alt_present'] ? 'alt=""' : '无 alt 属性'; ?></span>
								<?php endif; ?>
							</td>
							<td><span class="wiaa-audit-status is-<?php echo esc_attr( $label[1] ); ?>"><?php echo esc_html( $label[0] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wiaa-note">
		<strong>如何解释结果：</strong>
		如果出现“媒体有 / 前台空”，说明当前页面至少有一部分旧图片不会自动同步媒体库 ALT；这时再考虑开发 Frontend ALT Fallback。若“前台已同步”为主，则没有必要为该渲染路径增加额外补丁。
	</div>
</div>
