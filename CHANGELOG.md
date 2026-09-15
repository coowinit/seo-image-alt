# Changelog

## v1.1.1

### Test-generation review workflow

- “测试生成 20 张”完成后直接显示本次真实生成结果。
- 新增测试结果图片缩略图、ALT 候选、AI 类型与判断依据展示。
- 新增字符数 / 英文词数与基础质量提示。
- 测试结果支持直接编辑候选、逐张审核与单张应用。
- 新增“审核通过合格内容图”，仅处理通过既有安全质量门槛的测试候选。
- 新增“应用这批已审核 ALT”。
- 新增“生成全部剩余图片”，测试满意后可直接进入全站任务。
- 测试结果在任务完成、暂停、停止或错误状态下可恢复查看。

## v1.1.0

### Site-wide bulk workflow

- 新增大型媒体库“全站批量任务”，不再受每页 20 张限制。
- 新增“测试生成 20 张”。
- 新增“生成全部待处理”。
- 新增“重试全部失败”。
- 新增“批量审核全部合格内容图”。
- 新增“应用全部已审核 ALT”。
- 任务进度持久化到当前用户，可暂停、继续、停止并在刷新后恢复。
- AI 生成按一张图片一个请求顺序推进，避免一次 PHP 请求处理大量图片。
- 本地审核 / 应用按小批次执行，提高数千张媒体库的处理效率。

### Reliability

- 普通网络错误、5xx 和空响应支持自动重试。
- DeepSeek HTTP 429 支持 Retry-After / 冷却等待后继续。
- API Key、模型或请求级错误会暂停整个任务，避免连续失败。
- 中断时遗留的 `processing` 状态可重新进入待处理队列。

### Safety

- 全站审核仅处理 `content` 类型且通过保守质量门槛的候选。
- `decorative`、`uncertain`、JSON 异常、过长 / 过短候选仍保留人工审核。
- 全站应用只处理已审核候选，并在写入前重新确认原生 ALT 为空。
- 继续保持“不覆盖已有 WordPress ALT”的安全边界。

## v1.0.0

First public release of WEM Image ALT Assistant.

### Core

- WordPress Media Library image scanner.
- DeepSeek Vision based ALT candidate generation.
- WordPress page / product context builder.
- Structured AI result: `type` / `alt` / `reason`.
- ALT output language setting: Auto / English / 简体中文.
- Large image preview for manual review.

### Review workflow

- Pending / Review / Reviewed / Existing ALT / No ALT / Failed states.
- Review-first workflow before writing native ALT.
- Candidate editing with automatic review invalidation.
- Batch candidate generation.
- Batch review approval.
- Batch application of reviewed ALT.
- Retry failed items.
- No overwrite of existing native ALT.

### Accessibility

- AI classification for `content` / `decorative` / `uncertain`.
- Manual “No ALT” handling for decorative images.
- Ignore / restore workflow for non-content media.

### Frontend audit

- Read-only frontend ALT audit.
- Compare Media Library ALT with rendered frontend `<img>` ALT.
- Attachment mapping by WordPress image class, data attributes and attachment URL.
- No modification of post content, Gutenberg blocks or Elementor data.

### Developer

- `wiaa_image_context` filter for extending AI context.
- Administrator / Editor capability separation.
- Native WordPress Attachment Meta storage.
- No custom database tables.

### Compatibility

- Includes a local one-time compatibility repair for legacy JSON-shaped AI candidates from pre-release development builds.
- Compatibility repair does not call the AI API.
