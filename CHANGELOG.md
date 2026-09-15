# Changelog

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
