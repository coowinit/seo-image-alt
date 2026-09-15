# WEM Image ALT Assistant

> 基于 WordPress 媒体库、页面上下文与 DeepSeek Vision 的图片 ALT 审核工作台。

**当前版本：v1.1.1**

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


## 全站批量任务（v1.1.x）

当媒体库达到数百或数千张图片时，“每页 20 张 → 选择 → 生成 → 审核 → 应用”的页面级流程会产生大量重复操作。

v1.1.0 起新增 **大型媒体库模式**，批量任务不再依赖当前分页。

### 推荐流程

```text
测试生成 20 张
        ↓
确认 Prompt 与 ALT 质量
        ↓
生成全部待处理
        ↓
随机抽查 20–50 张内容图
        ↓
批量审核全部合格内容图
        ↓
人工处理 decorative / uncertain / failed
        ↓
应用全部已审核 ALT
```

### 测试生成 20 张与结果预览

v1.1.1 补全了“测试生成”的闭环：测试完成后不需要再去“待审核”分页寻找图片，当前任务会直接显示本次真正处理的图片与 ALT 候选。

```text
测试生成 20 张
        ↓
直接查看这 20 张图片
        ↓
图片 + AI 类型 + ALT 候选 + 判断依据
        ↓
字符 / 词数与基础质量提示
        ↓
人工编辑 / 逐张审核
        ↓
审核通过合格内容图
        ↓
应用这批已审核 ALT
        ↓
生成全部剩余图片
```

测试结果会显示：

- 图片缩略图，可点击查看大图；
- `content / decorative / uncertain` 判断；
- AI ALT 候选；
- AI 判断依据；
- 字符数与英文词数；
- “长度与结构合适 / ALT 偏长 / 疑似 JSON / AI 不确定 / 生成失败”等提示；
- 人工编辑与“审核通过”；
- 单张应用 ALT。

“审核通过合格内容图”仍使用与全站审核相同的保守质量门槛，并不代表 SEO 自动打分。它只用于快速排除明显异常候选。

测试生成的 20 张是真实任务结果，不是预览模拟数据；生成成功后已经保存到 Attachment Meta，因此后续可以直接审核和应用，不会浪费本次 API 调用。

### 全站生成

“生成全部待处理”会先取得当前符合条件的 Attachment ID 快照，然后：

```text
1 张图片
→ 1 次 AJAX
→ 1 次 DeepSeek Vision
→ 保存结果
→ 继续下一张
```

不会把 1000+ 张图片放入一个 PHP 请求中，因此更适合 SiteGround、Cloudflare 和普通 WordPress 主机环境。JPEG / PNG / GIF / WebP 之外的格式不会进入 Vision 生成任务。

任务进度会持久化保存，支持：

- 刷新页面后继续；
- 暂停；
- 恢复；
- 停止；
- 失败统计；
- 自动重试普通网络 / 5xx / 空响应错误；
- DeepSeek HTTP 429 限流时等待后继续；
- API Key / Model 等全局错误时暂停任务，避免连续制造失败记录。

> 当前版本采用“后台页面驱动”的轻量队列。生成任务需要保持 Image ALT 后台页面打开；关闭页面不会丢失进度，重新打开后会继续。

### 批量审核全部合格内容图

该功能不是把所有 AI 结果无条件审核通过，而是先经过保守质量门槛。

只有同时符合以下条件的候选才进入全站审核任务：

```text
AI type = content
+
原生 ALT 为空
+
候选 ALT 非空
+
候选不是 JSON / JSON-like 文本
+
候选长度处于正常范围
+
英文候选词数处于正常范围
```

以下类型不会自动审核：

```text
decorative
uncertain
failed
JSON 异常
过长 / 过短候选
已有原生 ALT
```

它们继续留给人工检查。

即使候选通过门槛，也建议在执行全站审核前随机检查 20–50 张真实图片。

### 应用全部已审核 ALT

“应用全部已审核 ALT”不受分页限制，会处理整个媒体库中当前已审核的候选。

每次服务器请求最多处理一小批本地数据，不调用 AI。

写入前仍然重新检查：

```text
审核通过
+
候选仍然存在
+
候选内容未变化
+
当前 _wp_attachment_image_alt 仍为空
```

已有 ALT 永远不会被覆盖。

### 与原页面级操作的关系

v1.1.x 没有移除原来的页面级流程。

```text
少量图片 / 精细人工处理
→ 继续使用每页批量操作

数百 / 数千张历史图片
→ 使用全站批量任务
```

两套工作流共用同一套 Attachment Meta 和审核状态，可以混合使用。

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
→ 上传 seo-image-alt-v1.1.1.zip
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
10. 小批量站点继续下一批；大型媒体库可改用“全站批量任务”
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


## 主题模板中如何读取 ALT

WEM Image ALT Assistant 最终把人工审核通过的 ALT 写入 WordPress 原生字段：

```text
_wp_attachment_image_alt
```

因此，主题模板不需要调用插件私有接口，只要按照 WordPress 原生方式读取图片 ALT 即可。

> **ALT 数据属于 WordPress 媒体库，而不是只属于本插件。**

即使以后停用 WEM Image ALT Assistant，只要媒体附件仍然存在，已经写入的 ALT 仍然可以被主题模板正常读取。

### 方式一：已知 Attachment ID 时，优先使用 WordPress 原生图片函数

如果自定义字段保存的是媒体附件 ID，推荐直接使用：

```php
$image_id = 123;

echo wp_get_attachment_image(
    $image_id,
    'full'
);
```

WordPress 会自动生成 `<img>`，并读取 `_wp_attachment_image_alt`，同时处理：

- `src`；
- `width` / `height`；
- `srcset`；
- `sizes`；
- `loading`；
- `decoding`。

如果只需要单独读取 ALT：

```php
$image_alt = get_post_meta(
    $image_id,
    '_wp_attachment_image_alt',
    true
);
```

输出时使用：

```php
alt="<?php echo esc_attr( $image_alt ); ?>"
```

### 方式二：只有图片 URL 时，通过 URL 找到 Attachment ID

一些旧主题或自定义字段保存的是图片 URL，而不是 Attachment ID。

可以在主题 `functions.php` 中增加一个辅助函数：

```php
/**
 * 根据 WordPress 媒体库图片 URL 获取原生 ALT。
 *
 * @param string $image_url 图片 URL。
 * @param string $fallback  找不到媒体附件时的备用 ALT。
 * @return string
 */
function wem_get_image_alt_by_url( $image_url, $fallback = '' ) {

    if ( empty( $image_url ) ) {
        return $fallback;
    }

    // 去掉 ?ver= 等查询参数。
    $clean_url = strtok( $image_url, '?' );

    // 根据上传图片 URL 获取 Attachment ID。
    $attachment_id = attachment_url_to_postid( $clean_url );

    if ( $attachment_id ) {
        return trim(
            (string) get_post_meta(
                $attachment_id,
                '_wp_attachment_image_alt',
                true
            )
        );
    }

    return $fallback;
}
```

然后在模板中：

```php
$pic01 = get_post_meta(
    get_the_ID(),
    'product_mainimg01',
    true
);

$alt01 = wem_get_image_alt_by_url( $pic01 );
```

HTML：

```php
<img
    src="<?php echo esc_url( $pic01 ); ?>"
    alt="<?php echo esc_attr( $alt01 ); ?>"
>
```

这样，插件后台审核并应用的 ALT 会自动进入主题前台输出。

### 产品双图切换示例

旧主题中常见：

```php
$pic01 = get_post_meta( get_the_ID(), 'product_mainimg01', true );
$pic02 = get_post_meta( get_the_ID(), 'product_mainimg02', true );
```

可以改成：

```php
<?php
$pic01 = get_post_meta( get_the_ID(), 'product_mainimg01', true );
$pic02 = get_post_meta( get_the_ID(), 'product_mainimg02', true );

$alt01 = wem_get_image_alt_by_url( $pic01 );
$alt02 = wem_get_image_alt_by_url( $pic02 );
?>

<a class="hover-switch" href="<?php the_permalink(); ?>">
    <img
        width="770"
        height="500"
        src="<?php echo esc_url( $pic01 ); ?>"
        alt="<?php echo esc_attr( $alt01 ); ?>"
    >

    <img
        width="770"
        height="500"
        src="<?php echo esc_url( $pic02 ); ?>"
        alt="<?php echo esc_attr( $alt02 ); ?>"
    >
</a>
```

不要继续使用：

```html
alt="The first image"
alt="The second image"
```

这类占位式 ALT。

### WordPress 特色图片

对于 Post / Page 的特色图片，优先使用：

```php
<?php the_post_thumbnail( 'full' ); ?>
```

而不是自己获取 `the_post_thumbnail_url()` 后再拼接一个空 `alt`。

例如：

```php
<div class="picbox">
    <a href="<?php the_permalink(); ?>">
        <?php the_post_thumbnail( 'full' ); ?>
    </a>
</div>
```

`the_post_thumbnail()` 会基于 Attachment ID 生成 WordPress 原生图片 HTML，并读取媒体库 ALT。

### 固定上传目录图片

如果模板中直接写了：

```html
<img
    src="/wp-content/uploads/2024/12/free-samples01.webp"
    alt=""
>
```

也可以先转换成完整 URL：

```php
$image_url = home_url(
    '/wp-content/uploads/2024/12/free-samples01.webp'
);

$image_alt = wem_get_image_alt_by_url(
    $image_url,
    'Composite decking free samples'
);
```

再输出：

```php
<img
    src="<?php echo esc_url( $image_url ); ?>"
    alt="<?php echo esc_attr( $image_alt ); ?>"
>
```

第二个参数只是在无法找到 Attachment 时使用的备用 ALT。

### 装饰性图片不要强行读取文字 ALT

不是所有图片都应该有描述性 ALT。

例如：

- 数字装饰图；
- 分隔元素；
- 已经有相邻文字说明的纯装饰图标；
- 不承担独立信息的视觉元素。

这类图片可以继续使用：

```html
alt=""
```

例如图标旁边已经有 “Free Samples / About Us / Contact Us”，图标本身通常保持空 ALT 更合适，避免屏幕阅读器重复朗读。

### CSS `background-image` 没有 ALT

例如：

```html
<div
    class="swiper-slide"
    style="background-image: url(...);"
>
```

CSS 背景图片本身没有 HTML `alt` 属性。

如果图片只是视觉背景，可以保持这种实现；如果图片本身承担重要信息，应考虑改成真正的 `<img>`，再使用 WordPress 原生 ALT。

### 推荐的数据存储方式

对于新的 WordPress 主题或内容模型，优先保存：

```text
Attachment ID
```

而不是只保存：

```text
Image URL
```

推荐：

```php
$image_id = get_post_meta(
    get_the_ID(),
    'product_image_id',
    true
);

echo wp_get_attachment_image(
    $image_id,
    'full'
);
```

Attachment ID 可以直接关联：

- ALT；
- Caption；
- Title；
- 图片尺寸；
- `srcset`；
- `sizes`；
- Attachment Metadata。

如果旧项目已经保存 URL，则使用 `attachment_url_to_postid()` 作为兼容方案即可。

### 模板开发建议

推荐遵循：

```text
Attachment ID
→ wp_get_attachment_image()
→ 最优先

Image URL
→ attachment_url_to_postid()
→ 兼容旧主题

Featured Image
→ the_post_thumbnail()

Decorative Image
→ alt=""

CSS Background
→ 不存在 ALT
```

输出自定义 `<img>` 时至少使用：

```php
esc_url()
esc_attr()
```

分别处理 `src` 和 `alt`。

### 与插件的关系

WEM Image ALT Assistant 负责：

```text
扫描
↓
AI 生成候选
↓
人工审核
↓
安全写入 WordPress 原生 ALT
```

主题模板负责：

```text
Attachment ID / Image URL
↓
读取 _wp_attachment_image_alt
↓
输出到 <img alt="">
```

两者通过 WordPress 原生媒体数据连接，不需要让主题依赖插件内部类或函数：

```text
WEM Image ALT Assistant
        ↓
_wp_attachment_image_alt
        ↓
WordPress Theme
        ↓
Frontend HTML
```

这也是本项目坚持 WordPress Native 的重要原因之一。

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

v1.1.x 仍明确不做：

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
