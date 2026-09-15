# WEM Image ALT Assistant

> 基于 WordPress 媒体库、页面上下文与 DeepSeek Vision 的图片 ALT 审核工作台。

**当前版本：v1.0.0**

WEM Image ALT Assistant 用于帮助 WordPress 网站发现缺失或需要复核的图片 ALT，并让 AI 在“看懂图片 + 理解页面上下文”的基础上生成候选文本，再由人工审核后安全写入 WordPress 原生 ALT 字段。

它不是“一键把所有空 ALT 填满”的自动化工具，而是一套：

> **Image Audit → AI Candidate → Human Review → Safe Apply**

的图片 ALT 管理流程。

---

## 项目定位

很多 WordPress 网站积累了大量历史图片，常见问题包括：

- 图片没有 ALT；
- 图片已经有 ALT，但描述过于机械；
- 只根据文件名生成 ALT，缺少真实图片语义；
- 图片所在页面与媒体库 `post_parent` 并不一致；
- 同一产品页面包含多张图片，需要不同的描述；
- 装饰性图片本来就应该保持 `alt=""`；
- 媒体库 ALT 已更新，但历史页面前台 HTML 可能仍未同步。

WEM Image ALT Assistant 不试图替代媒体库、SEO 插件或页面编辑器，而是补上 WordPress 原生后台缺少的：

```text
图片发现
    ↓
上下文理解
    ↓
AI 视觉分析
    ↓
候选 ALT
    ↓
人工审核
    ↓
安全应用
    ↓
前台验证
```

---

## 核心原则

### 1. AI 只做助手，不直接替人决定

默认流程：

```text
AI Generate
    ↓
Candidate
    ↓
Human Review
    ↓
Approved
    ↓
Apply ALT
```

AI 返回结果后不会直接写入正式 ALT。

---

### 2. 不覆盖已有 ALT

插件默认拒绝覆盖已经存在的 WordPress 原生 ALT。

这意味着：

- 原人工 ALT 不会被批量覆盖；
- AI 候选只处理当前仍为空的 ALT；
- 批量应用前仍会再次检查原生 ALT 状态。

---

### 3. 不是所有空 ALT 都应该填文字

图片可能属于：

```text
content
→ 内容图片，需要描述性 ALT

decorative
→ 装饰性 / 占位图片，可能应该保持 alt=""

uncertain
→ 当前上下文不足，需要人工判断
```

AI 的 `decorative` 判断只是建议，最终仍由人工确认。

---

## 核心工作流

```text
WordPress Media Library
        ↓
Image Scanner
        ↓
WordPress Context
        ↓
DeepSeek Vision
        ↓
Structured AI Result
        ├── type
        ├── alt
        └── reason
        ↓
Human Review
        ↓
Approved
        ↓
Apply to _wp_attachment_image_alt
        ↓
Frontend ALT Audit
```

---

## 主要功能

### 图片扫描

默认扫描 WordPress 媒体库中的图片，并按工作状态分类：

- 待处理；
- 待审核；
- 已审核；
- 已有 ALT；
- 无需 ALT；
- 失败；
- 全部图片。

支持：

- 搜索；
- 分页；
- 图片缩略图；
- 点击查看大图；
- 单张生成；
- 本页批量生成；
- 失败重试。

---

### AI 视觉生成

当前使用 DeepSeek Vision 对图片进行视觉理解，并结合 WordPress 上下文生成结构化结果：

```json
{
  "type": "content",
  "alt": "Dark wood-grain WPC wall panel with hollow slat profile",
  "reason": "The image shows the product profile and visible hollow structure."
}
```

后台会把结果拆分显示：

- `type`：图片类型判断；
- `alt`：真正的 ALT 候选；
- `reason`：AI 判断依据。

完整 JSON 不会直接作为 ALT 写入。

---

### ALT 输出语言

DeepSeek 设置中支持：

```text
自动
English
简体中文
```

外贸英文网站通常建议固定为 `English`，避免同一媒体库出现中英文 ALT 混用。

---

### WordPress Context

生成 ALT 时，插件会向 AI 提供精简且与图片相关的上下文，包括：

- 图片文件名；
- 媒体标题；
- Caption；
- 父级 Post / Page / Product 标题；
- 父级文章类型；
- 页面摘要；
- Taxonomy Terms；
- Yoast Focus Keyword；
- Rank Math Focus Keyword。

目标不是让 AI 堆砌 SEO 关键词，而是减少误判。

上下文优先级应理解为：

```text
图片本身
>
实际页面 / 产品信息
>
分类与页面摘要
>
SEO 关键词
```

---

## 人工审核

AI 生成后进入“待审核”。

人工可以：

- 查看大图；
- 阅读 AI 判断依据；
- 修改候选 ALT；
- 勾选“审核通过”；
- 标记“无需 ALT”；
- 忽略不需要处理的测试 / 后台素材。

如果已审核的 ALT 再次被编辑：

```text
已审核
↓
候选内容变化
↓
自动取消审核
↓
重新确认
```

服务器端也会校验审核时的候选内容，避免界面状态与实际保存内容不一致。

---

## 批量审核与批量应用

插件将“生成”“审核”“应用”明确分成三个阶段：

```text
批量生成候选
        ↓
快速人工浏览
        ↓
批量审核通过
        ↓
批量应用已审核 ALT
```

### 批量审核

只改变审核状态，不写入正式 ALT。

### 批量应用

只有同时满足以下条件才会写入：

```text
已选择
+
候选 ALT 非空
+
审核通过
+
候选内容未变化
+
当前 WordPress 原生 ALT 仍为空
```

否则自动跳过。

批量处理完成后会显示：

```text
成功
失败
跳过
```

---

## 无需 ALT 与忽略

### 无需 ALT

用于人工确认的装饰性图片。

WordPress 原生 ALT 保持为空：

```html
alt=""
```

插件只记录该图片已经确认过，不再反复出现在待处理队列。

### 忽略

适合：

- 测试图片；
- 后台素材；
- 临时媒体；
- 不参与前台内容表达的图片。

两种状态都可以恢复为“待处理”。

---

## 前台 ALT 验证

媒体库中的：

```text
_wp_attachment_image_alt
```

已经更新，并不代表历史页面最终输出的 `<img>` 一定同步。

插件提供只读工具：

```text
Image ALT
→ 前台 ALT 验证
```

输入当前 WordPress 网站的一篇文章、页面或产品 URL 后，插件会：

```text
Frontend HTML
        ↓
扫描 <img>
        ↓
尝试映射 Attachment ID
        ↓
读取 Media Library ALT
        ↓
对比实际 Frontend ALT
```

结果包括：

- 前台已同步；
- 前台仍为空；
- 前台与媒体库不同；
- 两边都为空；
- 仅前台有 ALT；
- 无法映射媒体库。

该功能只做诊断，不会修改：

- `post_content`；
- Gutenberg Block；
- Elementor 数据；
- 前台 HTML 数据库内容。

---

## 图片发送方式

插件优先读取 WordPress 本地文件，并以 Base64 方式发送给 Vision API。

优点：

- staging 站点可用；
- 私有测试站不依赖外部访问图片 URL；
- Cloudflare / Basic Auth 环境下更稳定。

当前策略：

```text
本地图片 ≤ 8 MiB
→ Base64

更大图片
→ Attachment URL fallback
```

使用 URL fallback 时，图片 URL 需要能被 DeepSeek 访问。

---

## 支持的图片格式

当前视觉分析支持：

- JPEG；
- PNG；
- GIF；
- WebP。

当前不发送：

- SVG；
- AVIF。

不支持的图片不会被强行提交给 AI。

---

## DeepSeek 配置

后台：

```text
Image ALT
→ DeepSeek 设置
```

配置：

- API Key；
- Vision Model；
- ALT 输出语言；
- 连接测试。

也可以在 `wp-config.php` 中配置：

```php
define( 'WIAA_DEEPSEEK_API_KEY', 'YOUR_API_KEY' );
```

`wp-config.php` 常量优先于后台数据库设置。

当前插件使用：

```text
deepseek-v4-flash-vision-exp
```

---

## 安装

WordPress 后台：

```text
插件
→ 安装插件
→ 上传 wem-image-alt-assistant-v1.0.0.zip
→ 激活
```

激活后进入：

```text
Image ALT
├── 图片 ALT
├── 前台 ALT 验证
└── DeepSeek 设置
```

---

## 推荐使用流程

第一次在真实网站使用时，建议不要直接处理全部图片。

推荐：

```text
1. 配置 DeepSeek
2. 测试连接
3. 先选择 5–20 张图片
4. 批量生成候选
5. 检查 AI 分类与 ALT 质量
6. 人工修改
7. 批量审核通过
8. 批量应用已审核 ALT
9. 使用“前台 ALT 验证”检查实际输出
10. 再继续下一批
```

---

## 权限

### Administrator

拥有：

```text
wiaa_use_image_alt_assistant
wiaa_manage_image_alt_assistant
```

可以：

- 使用图片 ALT 工具；
- 生成 / 审核 / 应用 ALT；
- 使用前台 ALT 验证；
- 管理 DeepSeek API 设置。

### Editor

拥有：

```text
wiaa_use_image_alt_assistant
```

可以使用图片工作台，但不能查看或修改 API Key。

---

## 数据保存

最终 ALT 使用 WordPress 原生字段：

```text
_wp_attachment_image_alt
```

插件自己的流程状态保存在 Attachment Post Meta：

```text
_wiaa_candidate_alt
_wiaa_generation_status
_wiaa_last_error
_wiaa_model
_wiaa_generated_at
_wiaa_reviewed
_wiaa_reviewed_at
_wiaa_alt_intent
_wiaa_ai_note
_wiaa_applied_alt
_wiaa_applied_at
```

没有新增自定义数据库表。

---

## 开发者扩展

插件提供：

```php
wiaa_image_context
```

Filter，用于向 AI Context 追加可靠的业务数据。

例如 WEM Content Model 可以增加：

```text
Product Name
Product Model
Product Family
Color
Dimensions
Application
```

示例：

```php
add_filter(
    'wiaa_image_context',
    function ( $context, $attachment_id, $parent ) {
        if ( $parent && 'product' === $parent->post_type ) {
            $context['product_model'] = get_post_meta(
                $parent->ID,
                'product_model',
                true
            );
        }

        return $context;
    },
    10,
    3
);
```

核心插件不写死某一种产品模型，保持独立和可复用。

---

## AI ALT 写作原则

插件 Prompt 强调：

- 准确描述图片；
- 优先可访问性语义；
- 结合可靠上下文；
- 不臆造产品参数；
- 不堆砌关键词；
- 不使用无意义的 `image of` / `photo of`；
- 只在自然情况下使用产品关键词；
- ALT 保持简洁、可读。

对于产品图片，比固定字符数更重要的是：

> **准确描述这张图片与同页其他图片的区别。**

---

## 安全与隐私

只有管理员或编辑者主动执行 AI 生成时，当前图片和精简上下文才会发送到 DeepSeek。

插件不会：

- 扫描后自动上传整个媒体库；
- 自动覆盖人工 ALT；
- 自动修改历史文章正文；
- 自动重写 Gutenberg / Elementor 数据；
- 自动发布 AI 结果。

DeepSeek API Key 不会在后台页面完整回显。

---

## 当前边界

v1.0.0 明确不做：

- 自动覆盖已有 ALT；
- 上传图片后自动调用 AI；
- 前台实时 AI 生成；
- ALT SEO 打分；
- 图片压缩；
- 图片重命名；
- Title / Caption / Description 批量生成；
- OCR 管理；
- 自动翻译全部 ALT；
- 多 AI Provider；
- 自动修改历史 `post_content`；
- 自动修复 Elementor / Gutenberg 中已保存的 ALT；
- 复杂图片使用关系图。

这些功能只有在真实使用证明有价值时才考虑增加。

---

## 已知限制

### Attachment Parent 不一定是真实使用页面

WordPress 图片可能：

- 被多个页面使用；
- 上传时没有父级；
- 后来被 Elementor / Gutenberg 插入其他页面。

因此当前页面上下文是可靠但保守的起点。

---

### AI 无法替代页面语义判断

同一张图片放在不同页面中，ALT 可能应该不同。

所以插件始终坚持：

> **AI generates. Human decides.**

---

## 架构

当前项目保持轻量：

```text
Image Scanner
      ↓
Image Context
      ↓
DeepSeek Client
      ↓
ALT Generator
      ↓
Review Workflow
      ↓
Native WordPress ALT
      ↓
Frontend Auditor
```

没有引入：

- Vector Database；
- RAG；
- 自定义数据库；
- 复杂任务框架；
- 大型 AI SDK。

目标是保持：

> **WordPress Native · Review First · AI as Assistant · Simple · Useful · Maintainable**

---

## 运行要求

- WordPress 6.0+
- PHP 7.4+
- 可用的 DeepSeek API Key

---

## License

GPL-2.0-or-later
