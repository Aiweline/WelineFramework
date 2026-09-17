# 媒体：选图标签 vs 出图标签

> **写模板前必读。** 选图与出图是两条官方能力，禁止混用或手写等价控件。

权威对照：[Taglib 场景映射表](../../Taglib/doc/场景映射表.md)。身份协议见 [media-reference-identity-protocol.md](media-reference-identity-protocol.md)。出图 CLS 尺寸见 [file-image-cls-尺寸与响应式.md](file-image-cls-尺寸与响应式.md)。

## 一句话分工

| 需求 | 官方写法 | 写入 / 输出 | 禁止 |
|------|----------|-------------|------|
| **选图**（配置项、表单隐藏域回填） | `<w:file-manager … />` 或 Block `Weline\MediaManager\Block\WelineMedia`（内部 `w-file-picker`） | 路径字符串，或 **typed `file-image` JSON** | `input type=file`、裸 elFinder、手写 URL 文本框当主链 |
| **出图**（店面/后台把已存媒体渲成 `<img>`） | `<w:file:image usage="…" width height\|aspect_ratio />` | HTML `<img class="w-file-image">` | 无尺寸裸 `<img src>`；用 `file:image` 当选图器 |

`<w:file:image>` **不是**选择器；场景映射里若只写「图片」请先分清选图还是出图。

## 选图：`<w:file-manager />`

模板自闭合标签，源码 `Weline\FileManager\Taglib\FileManager`。选中后按 `target` 回填到对应隐藏域 / 输入框。

### 路径模式（默认，配置中心 / 后台 Logo 等）

```html
<input type="hidden" id="logo_light" name="logo_light" value="">
<w:file-manager
    target="#logo_light"
    title="选择浅色 Logo"
    path="backend/logo/"
    value=""
    preview="1"
    multi="0"
    ext="jpg,jpeg,png,gif,webp,svg"
    size="2048000"
    w="70"
    h="70"
    identity_root="config"
    identity_code="logo_light"
    identity_scope="default.default.default"
    identity_kind="media"
    identity_field="logo_light"
    identity_component="backend"
    ref_mode="single"
    strong_ref="1"
/>
```

- `target`：必填，目标元素 id（选择结果写入该元素的 value）。
- `path` / `lockPath` / `lockRoot`：默认目录与路径锁。
- `ext` / `size` / `multi` / `w` / `h` / `preview`：类型、大小、多选、预览尺寸。
- MediaReferenceIdentity：`identity_*` / `ref_mode` / `owner_*` / `strong_ref` — 强引用须有身份（见身份协议）；**有 `identity_code` 时务必带 `identity_scope`（三点分）**，禁止手拼 `identity_path`，用 `w_scope` / Ambient。

### typed `file-image` 模式（主题部件 `media_image` 主链）

主题部件参数、布局图片等需要 **结构化节点**（校验器拒绝旧 URL 字符串）时，不要只依赖路径模式。当前主链是 PHP 渲染 `WelineMedia` Block，并显式传入：

| Block 参数 | 含义 |
|------------|------|
| `value_mode` = `file-image` | `w-file-picker` 写入 `{ "type":"file-image", "usage":{…} }` JSON，而不是裸路径 |
| `usage` = `1` | 打开媒体库时带 usage，生成可校验的 ImageUsage |
| `locale_code` | 可选；选图戳记 locale（全语言编辑器用站点默认语） |
| `path` / `lockPath` / `lockRoot` / `aspect_ratio` / `recommend_*` | 与路径模式相同的目录与比例约束 |
| `identity_*` … | 同引用身份协议（部件字段走 Ambient / `w_scope`） |

权威实现：`Weline\Widget\Ui\ParamType\AbstractParamType::renderMediaLibraryPickerHtml`（`value_mode => file-image` + `usage => 1`）。失败时可回退旧 `.w-param-media-image-select`，新代码禁止以回退为主链。

SystemConfig / 部分后台配置仍用 `WelineMedia` **未开** `value_mode=file-image`（存路径字符串）——属于配置域值模式，与 Theme 布局 `file-image` 契约分开；改配置域须连读写与解析一起迁。

## 出图：`<w:file:image />`

模板自闭合标签，源码 `Weline\FileManager\Taglib\Image`。把已持久化的 `ImageUsage` / asset 渲成带 CLS 占位的 `<img>`。

```html
<w:file:image usage="heroUsage" width="16" height="9" class="hero-media" />
<!-- 或 -->
<w:file:image asset="assetId" alt="已确认的替代文本" aspect_ratio="16/9" complement="true" />
```

| 属性 | 说明 |
|------|------|
| `usage` | 运行时 `ImageUsage`（或兼容结构） |
| `asset` | 无 usage 时按 asset id + `alt` 渲染 |
| `width` + `height` **或** `aspect_ratio` | **必填其一**：输出 HTML width/height 防 CLS |
| `alt` / `decorative` / `locale` / `class` / `complement` | 替代文本、装饰图、语种、样式、补全策略 |

主题 CSS 须保证 `.w-file-image` / foundation `img` 有 `max-width:100%; height:auto`（见 CLS 文档）。

动态业务图禁止落盘裸 `<img src="…">`；保存侧用 file-image 节点，展示侧用本标签或经 Hydration 的 `*_file_html` 伴生字段。

## 决策流

```text
要让用户从图库选一张图？
  → <w:file-manager> 或 WelineMedia
     · Theme 部件 / 布局校验 → value_mode=file-image + usage=1
     · 配置中心路径字段 → 默认路径模式（直至配置域迁移）

要把已存媒体显示出来？
  → <w:file:image>，并设 width+height 或 aspect_ratio
  → 禁止用 file:image「代替」选图按钮
```

## 相关源码（参考）

- 选图 Taglib：`app/code/Weline/FileManager/Taglib/FileManager.php`
- 出图 Taglib：`app/code/Weline/FileManager/Taglib/Image.php`
- 选图 UI：`Weline\MediaManager\Block\WelineMedia` → `view/blocks/weline-media.phtml`（`data-w-value-mode`）
- 部件选图：`Weline\Widget\Ui\ParamType\AbstractParamType::renderMediaLibraryPickerHtml`
