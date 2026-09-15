<?php
/**
 * Media ALT workbench.
 *
 * @package WEM_Image_ALT_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'pending'   => '待处理',
	'candidate' => '待审核',
	'reviewed'  => '已审核',
	'complete'  => '已有 ALT',
	'no_alt'    => '无需 ALT',
	'failed'    => '失败',
	'all'       => '全部图片',
);

$show_current_alt = in_array( $status, array( 'complete', 'all' ), true );
$colspan          = $show_current_alt ? 7 : 6;
?>
<div class="wrap wiaa-wrap">
	<div class="wiaa-header">
		<div>
			<h1>WEM Image ALT Assistant</h1>
			<p>Image Scanner → WordPress Context → DeepSeek Vision → Review → Save</p>
		</div>
		<span class="wiaa-version">v<?php echo esc_html( WIAA_VERSION ); ?></span>
	</div>

	<div class="wiaa-intro">
		<div>
			<span class="wiaa-eyebrow">AI Image ALT</span>
			<h2>先生成候选，再人工确认</h2>
			<p>AI 会先判断图片是内容图片、装饰性图片还是不确定；只有人工勾选“审核通过”的候选，才允许写入 WordPress 原生 <code>_wp_attachment_image_alt</code>。</p>
		</div>
		<?php if ( $is_configured ) : ?>
			<span class="wiaa-badge is-ready">DeepSeek 已配置</span>
		<?php else : ?>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wem-image-alt-assistant-settings' ) ); ?>">配置 DeepSeek</a>
		<?php endif; ?>
	</div>

	<div class="wiaa-stats">
		<div><strong data-count-key="pending"><?php echo esc_html( number_format_i18n( $counts['pending'] ) ); ?></strong><span>待处理</span></div>
		<div><strong data-count-key="candidate"><?php echo esc_html( number_format_i18n( $counts['candidate'] ) ); ?></strong><span>待审核</span></div>
		<div><strong data-count-key="reviewed"><?php echo esc_html( number_format_i18n( $counts['reviewed'] ) ); ?></strong><span>已审核</span></div>
		<div><strong data-count-key="complete"><?php echo esc_html( number_format_i18n( $counts['complete'] ) ); ?></strong><span>已有 ALT</span></div>
		<div><strong data-count-key="no_alt"><?php echo esc_html( number_format_i18n( $counts['no_alt'] ) ); ?></strong><span>无需 ALT</span></div>
		<div><strong data-count-key="failed"><?php echo esc_html( number_format_i18n( $counts['failed'] ) ); ?></strong><span>失败</span></div>
	</div>


	<section class="wiaa-global-bulk" id="wiaa-global-bulk">
		<div class="wiaa-global-bulk-head">
			<div>
				<span class="wiaa-eyebrow">v1.1 全站批量任务</span>
				<h2>大型媒体库模式</h2>
				<p>不再受每页 20 张限制。AI 生成按图片顺序逐张执行并持久化进度；审核与应用使用服务器小批次处理。关闭或刷新页面后，重新打开即可继续。</p>
			</div>
			<span class="wiaa-badge is-ready" data-bulk-state-label>暂无任务</span>
		</div>

		<div class="wiaa-global-actions">
			<button type="button" class="button" data-bulk-create data-requires-api="1" data-operation="generate" data-scope="pending" data-limit="20" <?php disabled( ! $is_configured ); ?>>测试生成 20 张</button>
			<button type="button" class="button button-primary" data-bulk-create data-requires-api="1" data-operation="generate" data-scope="pending" data-limit="0" <?php disabled( ! $is_configured ); ?>>生成全部待处理</button>
			<button type="button" class="button" data-bulk-create data-requires-api="1" data-operation="generate" data-scope="failed" data-limit="0" <?php disabled( ! $is_configured ); ?>>重试全部失败</button>
			<button type="button" class="button" data-bulk-create data-operation="review" data-scope="eligible_content" data-limit="0">批量审核全部合格内容图</button>
			<button type="button" class="button button-primary" data-bulk-create data-operation="apply" data-scope="reviewed" data-limit="0">应用全部已审核 ALT</button>
		</div>

		<div class="wiaa-global-task" data-bulk-task <?php echo empty( $bulk_task ) ? 'hidden' : ''; ?>>
			<div class="wiaa-global-task-row">
				<strong data-bulk-label><?php echo ! empty( $bulk_task['label'] ) ? esc_html( $bulk_task['label'] ) : '全站任务'; ?></strong>
				<span data-bulk-progress-text></span>
			</div>
			<div class="wiaa-progress-track wiaa-global-progress"><span data-bulk-progress-bar></span></div>
			<div class="wiaa-global-numbers">
				<span>总数 <strong data-bulk-total>0</strong></span>
				<span>已处理 <strong data-bulk-processed>0</strong></span>
				<span>成功 <strong data-bulk-success>0</strong></span>
				<span>失败 <strong data-bulk-failed>0</strong></span>
				<span>跳过 <strong data-bulk-skipped>0</strong></span>
				<span data-bulk-excluded-wrap hidden>未进入任务 <strong data-bulk-excluded>0</strong></span>
			</div>
			<p class="wiaa-global-message" data-bulk-message></p>
			<div class="wiaa-global-controls">
				<button type="button" class="button button-primary" data-test-results-toggle hidden>查看测试结果</button>
				<button type="button" class="button" data-bulk-control="pause">暂停</button>
				<button type="button" class="button button-primary" data-bulk-control="resume">继续</button>
				<button type="button" class="button" data-bulk-control="stop">停止任务</button>
			</div>
		</div>

		<div class="wiaa-test-results" id="wiaa-test-results" hidden>
			<div class="wiaa-test-results-head">
				<div>
					<span class="wiaa-eyebrow">测试结果</span>
					<h3>检查本次生成的 <span data-test-result-count>0</span> 张图片</h3>
					<p>这里显示本次测试真正生成的 ALT。可以直接编辑、逐张审核，也可以只批量审核通过安全质量门槛的内容图。</p>
				</div>
				<div class="wiaa-test-actions">
					<button type="button" class="button" data-test-review-good>审核通过合格内容图</button>
					<button type="button" class="button" data-test-apply-reviewed>应用这批已审核 ALT</button>
					<button type="button" class="button button-primary" data-test-generate-all <?php disabled( ! $is_configured ); ?>>生成全部剩余图片</button>
				</div>
			</div>
			<div class="wiaa-table-wrap wiaa-test-table-wrap">
				<table class="widefat striped wiaa-table wiaa-test-table">
					<thead>
						<tr>
							<th class="wiaa-col-image">图片</th>
							<th>AI 候选 / 审核</th>
							<th class="wiaa-test-quality-col">质量提示</th>
							<th class="wiaa-col-action">操作</th>
						</tr>
					</thead>
					<tbody data-test-results-body></tbody>
				</table>
			</div>
		</div>

		<p class="wiaa-global-help"><strong>安全门槛：</strong>“批量审核全部合格内容图”只处理 AI 明确判断为 <code>content</code>、候选非空、不是 JSON、长度正常且当前原生 ALT 仍为空的图片；<code>decorative</code>、<code>uncertain</code>、异常候选继续保留人工处理。</p>
	</section>

	<nav class="nav-tab-wrapper wiaa-tabs">
		<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
			<?php
			$url = add_query_arg(
				array(
					'page'   => 'wem-image-alt-assistant',
					'status' => $tab_key,
				),
				admin_url( 'admin.php' )
			);
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $status === $tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
				<?php if ( isset( $counts[ $tab_key ] ) ) : ?>
					<span class="count">(<span data-tab-count-key="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $tab_key ] ) ); ?></span>)</span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="wiaa-toolbar">
		<form method="get">
			<input type="hidden" name="page" value="wem-image-alt-assistant">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索图片标题">
			<button type="submit" class="button">搜索</button>
		</form>

		<?php if ( in_array( $status, array( 'pending', 'candidate', 'reviewed', 'failed' ), true ) ) : ?>
			<div class="wiaa-bulk-actions">
				<button type="button" class="button" id="wiaa-select-all">选择本页</button>

				<?php if ( in_array( $status, array( 'pending', 'failed' ), true ) ) : ?>
					<button type="button" class="button button-primary" id="wiaa-batch-generate" <?php disabled( ! $is_configured ); ?>>批量生成候选 ALT</button>
				<?php endif; ?>

				<?php if ( in_array( $status, array( 'pending', 'candidate', 'failed' ), true ) ) : ?>
					<button type="button" class="button" id="wiaa-batch-review">批量审核通过</button>
					<button type="button" class="button button-primary" id="wiaa-batch-apply">批量应用已审核 ALT</button>
				<?php endif; ?>

				<?php if ( 'reviewed' === $status ) : ?>
					<button type="button" class="button" id="wiaa-batch-unreview">取消批量审核</button>
					<button type="button" class="button button-primary" id="wiaa-batch-apply">批量应用已审核 ALT</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div id="wiaa-progress" class="wiaa-progress" hidden>
		<div class="wiaa-progress-text">准备中…</div>
		<div class="wiaa-progress-track"><span></span></div>
	</div>

	<div id="wiaa-batch-summary" class="wiaa-batch-summary" hidden>
		<div>
			<strong>本次处理结果</strong>
			<span data-summary-text></span>
		</div>
		<button type="button" class="button" id="wiaa-retry-failed" hidden>重试失败项</button>
	</div>

	<?php if ( empty( $result['items'] ) ) : ?>
		<div class="wiaa-empty">
			<h3>当前没有匹配的图片</h3>
			<p>可以切换状态标签，或修改搜索关键词。</p>
		</div>
	<?php else : ?>
		<div class="wiaa-table-wrap">
			<table class="widefat fixed striped wiaa-table">
				<thead>
					<tr>
						<td class="check-column"></td>
						<th class="wiaa-col-image">图片</th>
						<th class="wiaa-col-context">上下文</th>
						<?php if ( $show_current_alt ) : ?>
							<th class="wiaa-col-current-alt">当前 ALT</th>
						<?php endif; ?>
						<th class="wiaa-col-candidate">AI 候选 / 审核</th>
						<th class="wiaa-col-status">状态</th>
						<th class="wiaa-col-action">操作</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $result['items'] as $item ) : ?>
					<tr
						data-id="<?php echo esc_attr( $item['id'] ); ?>"
						data-status="<?php echo esc_attr( $item['status'] ); ?>"
						data-intent="<?php echo esc_attr( $item['intent'] ); ?>"
						data-reviewed="<?php echo $item['reviewed'] ? '1' : '0'; ?>"
					>
						<th scope="row" class="check-column">
							<?php if ( ! in_array( $item['status'], array( 'complete', 'no_alt' ), true ) ) : ?>
								<input type="checkbox" class="wiaa-row-check" value="<?php echo esc_attr( $item['id'] ); ?>">
							<?php endif; ?>
						</th>
						<td class="wiaa-image-cell">
							<?php if ( $item['thumb_url'] ) : ?>
								<button type="button" class="wiaa-thumb-button" data-full="<?php echo esc_url( $item['url'] ); ?>" aria-label="查看大图">
									<img src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="">
								</button>
							<?php endif; ?>
							<div>
								<strong><?php echo esc_html( $item['title'] ? $item['title'] : $item['filename'] ); ?></strong>
								<code title="<?php echo esc_attr( $item['filename'] ); ?>"><?php echo esc_html( $item['filename'] ); ?></code>
								<small><?php echo esc_html( $item['mime'] ); ?></small>
							</div>
						</td>
						<td>
							<?php if ( $item['parent_id'] ) : ?>
								<strong><?php echo esc_html( $item['parent_title'] ); ?></strong>
								<small><?php echo esc_html( $item['parent_type'] ); ?> · ID <?php echo esc_html( $item['parent_id'] ); ?></small>
							<?php else : ?>
								<span class="wiaa-muted">未关联父级内容</span>
							<?php endif; ?>
						</td>

						<?php if ( $show_current_alt ) : ?>
							<td>
								<?php if ( $item['alt'] ) : ?>
									<div class="wiaa-existing-alt"><?php echo esc_html( $item['alt'] ); ?></div>
								<?php else : ?>
									<span class="wiaa-muted">—</span>
								<?php endif; ?>
							</td>
						<?php endif; ?>

						<td class="wiaa-candidate-cell">
							<?php if ( ! in_array( $item['status'], array( 'complete', 'no_alt' ), true ) ) : ?>
								<div class="wiaa-ai-judgement" <?php echo $item['intent'] ? '' : 'hidden'; ?>>
									<span class="wiaa-intent is-<?php echo esc_attr( $item['intent'] ? $item['intent'] : 'unknown' ); ?>" data-intent-label>
										<?php
										$intent_labels = array(
											'content'    => 'AI：内容图片',
											'decorative' => 'AI：可能无需 ALT',
											'uncertain'  => 'AI：不确定',
										);
										echo esc_html( isset( $intent_labels[ $item['intent'] ] ) ? $intent_labels[ $item['intent'] ] : 'AI：待判断' );
										?>
									</span>
									<span class="wiaa-ai-note" data-ai-note><?php echo esc_html( $item['ai_note'] ); ?></span>
								</div>

								<textarea class="wiaa-candidate-input" rows="3" placeholder="AI 候选 ALT，可人工编辑；停止输入后自动保存"><?php echo esc_textarea( $item['candidate'] ); ?></textarea>

								<div class="wiaa-review-row">
									<label>
										<input type="checkbox" class="wiaa-review-check" <?php checked( $item['reviewed'] ); ?> <?php disabled( '' === $item['candidate'] ); ?>>
										审核通过
									</label>
									<span class="wiaa-review-state"><?php echo $item['reviewed'] ? '已审核' : '未审核'; ?></span>
								</div>

								<?php if ( $item['model'] ) : ?>
									<small class="wiaa-meta"><?php echo esc_html( $item['model'] ); ?><?php echo $item['generated_at'] ? ' · ' . esc_html( $item['generated_at'] ) : ''; ?></small>
								<?php endif; ?>

								<div class="wiaa-feedback">
									<?php if ( $item['error'] ) : ?>
										<small class="wiaa-row-error"><?php echo esc_html( $item['error'] ); ?></small>
									<?php endif; ?>
									<?php if ( $item['api_debug'] ) : ?>
										<details class="wiaa-api-debug">
											<summary>响应诊断</summary>
											<code><?php echo esc_html( $item['api_debug'] ); ?></code>
										</details>
									<?php endif; ?>
									<small class="wiaa-runtime-message"></small>
								</div>
							<?php elseif ( 'no_alt' === $item['status'] ) : ?>
								<div class="wiaa-no-alt-decision">
									<strong><?php echo 'ignored' === $item['intent'] ? '已忽略' : '已确认无需描述性 ALT'; ?></strong>
									<p><?php echo 'ignored' === $item['intent'] ? '该媒体已从默认处理队列中排除。' : '空 ALT 被视为有意保留，而不是遗漏。'; ?></p>
								</div>
							<?php else : ?>
								<span class="wiaa-muted">已使用 WordPress 原生 ALT。</span>
							<?php endif; ?>
						</td>

						<td>
							<?php
							$status_label = '待处理';
							if ( 'candidate' === $item['status'] ) {
								$status_label = '待审核';
							} elseif ( 'reviewed' === $item['status'] ) {
								$status_label = '已审核';
							} elseif ( 'failed' === $item['status'] ) {
								$status_label = '失败';
							} elseif ( 'complete' === $item['status'] ) {
								$status_label = '已有 ALT';
							} elseif ( 'no_alt' === $item['status'] ) {
								$status_label = '无需 ALT';
							}
							?>
							<span class="wiaa-status is-<?php echo esc_attr( $item['status'] ); ?>" data-status-label><?php echo esc_html( $status_label ); ?></span>
							<?php if ( ! $item['supported'] && ! in_array( $item['status'], array( 'complete', 'no_alt' ), true ) ) : ?>
								<small class="wiaa-row-error">当前视觉模型不支持该格式</small>
							<?php endif; ?>
						</td>

						<td class="wiaa-actions">
							<?php if ( 'complete' === $item['status'] ) : ?>
								<?php if ( $item['edit_url'] ) : ?>
									<a class="button" href="<?php echo esc_url( $item['edit_url'] ); ?>">查看媒体</a>
								<?php endif; ?>
							<?php elseif ( 'no_alt' === $item['status'] ) : ?>
								<button type="button" class="button wiaa-restore">恢复待处理</button>
							<?php else : ?>
								<button type="button" class="button wiaa-generate" <?php disabled( ! $is_configured || ! $item['supported'] ); ?>><?php echo in_array( $item['status'], array( 'candidate', 'reviewed' ), true ) ? '重新生成' : ( 'failed' === $item['status'] ? '重试生成' : '生成候选' ); ?></button>
								<button type="button" class="button button-primary wiaa-apply" <?php disabled( ! $item['reviewed'] || '' === $item['candidate'] ); ?>>应用 ALT</button>
								<div class="wiaa-secondary-actions">
									<button type="button" class="button-link wiaa-no-alt" data-mode="decorative">无需 ALT</button>
									<span>·</span>
									<button type="button" class="button-link wiaa-no-alt" data-mode="ignored">忽略</button>
								</div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( $result['total_pages'] > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page'   => 'wem-image-alt-assistant',
									'status' => $status,
									's'      => $search,
									'paged'  => '%#%',
								),
								admin_url( 'admin.php' )
							),
							'format'    => '',
							'current'   => $result['page'],
							'total'     => $result['total_pages'],
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="wiaa-note">
		<strong>安全边界：</strong>
		AI 只生成候选；正式写入前必须人工审核。批量应用仅处理已审核且当前原生 ALT 为空的图片，不会覆盖已有 ALT。
	</div>
</div>

<div class="wiaa-image-modal" id="wiaa-image-modal" hidden aria-hidden="true">
	<button type="button" class="wiaa-modal-close" aria-label="关闭大图">×</button>
	<div class="wiaa-modal-backdrop" data-modal-close></div>
	<div class="wiaa-modal-content">
		<img src="" alt="媒体大图预览">
	</div>
</div>
