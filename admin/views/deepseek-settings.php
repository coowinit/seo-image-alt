<?php
/**
 * DeepSeek Vision settings.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_constant_key = 'constant' === $key_source;
?>
<div class="wrap wiaa-wrap wiaa-settings-page">
	<div class="wiaa-header">
		<div>
			<h1>WEM Image ALT Assistant</h1>
			<p>DeepSeek Vision · Site-specific Configuration</p>
		</div>
		<span class="wiaa-version">v<?php echo esc_html( WIAA_VERSION ); ?></span>
	</div>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p>DeepSeek 设置已保存。</p></div>
	<?php endif; ?>

	<?php if ( is_array( $test_result ) ) : ?>
		<?php if ( ! empty( $test_result['ok'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><strong>DeepSeek Vision 连接成功。</strong>测试只发送了 1×1 测试图片，没有发送任何媒体库图片或 WordPress 内容。</p>
			</div>
		<?php else : ?>
			<div class="notice notice-error is-dismissible">
				<p><strong>连接失败：</strong><?php echo esc_html( isset( $test_result['message'] ) ? $test_result['message'] : '未知错误。' ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="wiaa-intro">
		<div>
			<span class="wiaa-eyebrow">DeepSeek Vision</span>
			<h2>每个 WordPress 网站独立配置</h2>
			<p>只有管理员主动生成 ALT 候选时，插件才会发送当前图片和精简的 WordPress 上下文给 DeepSeek。</p>
		</div>
		<span class="wiaa-badge <?php echo $is_configured ? 'is-ready' : 'is-missing'; ?>"><?php echo $is_configured ? '已配置' : '未配置'; ?></span>
	</div>

	<div class="wiaa-settings-grid">
		<section class="wiaa-card">
			<h3>API 配置</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wiaa_save_settings">
				<?php wp_nonce_field( 'wiaa_save_settings' ); ?>

				<div class="wiaa-field">
					<label for="wiaa-deepseek-api-key">API Key</label>
					<?php if ( $is_constant_key ) : ?>
						<input type="password" id="wiaa-deepseek-api-key" class="regular-text" value="••••••••••••" disabled>
						<p class="description">由 <code>wp-config.php</code> 中的 <code>WIAA_DEEPSEEK_API_KEY</code> 提供，优先级高于数据库设置。</p>
					<?php else : ?>
						<input
							type="password"
							id="wiaa-deepseek-api-key"
							name="wiaa_deepseek_api_key"
							class="regular-text"
							value=""
							autocomplete="new-password"
							placeholder="<?php echo $is_configured ? esc_attr( '已保存；留空表示不修改' ) : esc_attr( '粘贴 DeepSeek API Key' ); ?>"
						>
						<p class="description">页面不会回显完整密钥。</p>
						<?php if ( $is_configured ) : ?>
							<label><input type="checkbox" name="wiaa_clear_api_key" value="1"> 清除当前已保存的 API Key</label>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="wiaa-field">
					<label for="wiaa-deepseek-model">视觉模型</label>
					<select id="wiaa-deepseek-model" name="wiaa_deepseek_model">
						<?php foreach ( $models as $model_id => $model_label ) : ?>
							<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $current_model, $model_id ); ?>>
								<?php echo esc_html( $model_label . ' · ' . $model_id ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">v1.0.2 固定使用 DeepSeek 官方视觉模型，避免误选仅支持文本的模型。</p>
				</div>

				<div class="wiaa-field">
					<label for="wiaa-alt-language">ALT 输出语言</label>
					<select id="wiaa-alt-language" name="wiaa_alt_language">
						<?php foreach ( $languages as $language_id => $language_label ) : ?>
							<option value="<?php echo esc_attr( $language_id ); ?>" <?php selected( $current_language, $language_id ); ?>>
								<?php echo esc_html( $language_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">外贸英文站建议固定选择 English；混合语言测试站可以使用“自动”。</p>
				</div>

				<div class="wiaa-field">
					<label>API Endpoint</label>
					<code><?php echo esc_html( WIAA_DeepSeek_Client::API_URL ); ?></code>
				</div>

				<p class="submit"><button type="submit" class="button button-primary">保存设置</button></p>
			</form>
		</section>

		<section class="wiaa-card">
			<h3>Vision 连接测试</h3>
			<p>测试会发送一张内置的 1×1 PNG，用于同时验证 API Key、模型和图片输入是否可用。</p>

			<dl class="wiaa-meta-list">
				<div><dt>配置状态</dt><dd><?php echo $is_configured ? '已配置' : '待配置'; ?></dd></div>
				<div><dt>Key 来源</dt><dd><?php echo 'constant' === $key_source ? '<code>wp-config.php</code>' : ( 'option' === $key_source ? '当前站点设置' : '—' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div>
				<div><dt>当前模型</dt><dd><code><?php echo esc_html( $current_model ); ?></code></dd></div>
				<div><dt>ALT 语言</dt><dd><?php echo esc_html( isset( $languages[ $current_language ] ) ? $languages[ $current_language ] : $current_language ); ?></dd></div>
			</dl>

			<?php if ( is_array( $test_result ) && ! empty( $test_result['ok'] ) && isset( $test_result['data'] ) ) : ?>
				<?php $test_data = $test_result['data']; ?>
				<div class="wiaa-test-result">
					<strong>最近一次测试</strong>
					<p>返回：<code><?php echo esc_html( isset( $test_data['content'] ) ? $test_data['content'] : '' ); ?></code></p>
					<p>模型：<code><?php echo esc_html( isset( $test_data['model'] ) ? $test_data['model'] : $current_model ); ?></code></p>
					<p>耗时：<?php echo esc_html( isset( $test_data['elapsed_ms'] ) ? number_format_i18n( (int) $test_data['elapsed_ms'] ) . ' ms' : '—' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wiaa_test_connection">
				<?php wp_nonce_field( 'wiaa_test_connection' ); ?>
				<p class="submit"><button type="submit" class="button" <?php disabled( ! $is_configured ); ?>>测试 DeepSeek Vision</button></p>
			</form>
		</section>
	</div>

	<div class="wiaa-note">
		<strong>隐私与数据边界：</strong>
		生成候选 ALT 时会把当前图片和精简页面上下文发送给 DeepSeek。插件不会自动扫描后立即上传，也不会把整个媒体库一次性发送给 AI。
	</div>
</div>
