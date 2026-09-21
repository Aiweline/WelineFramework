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
- **开选按钮默认内容宽**：`WelineMedia` 动作区 `w-file-picker__actions` + `data-align="start"`；禁止被外层 `w-stack` 默认 stretch 拉成满宽条。CSS：`.w-file-picker … [data-w-file-picker-open] { inline-size:auto }`（主题编辑器窄栏另有 `width:100%` 覆盖，仅限该上下文）。
- MediaReferenceIdentity：`identity_*` / `ref_mode` / `owner_*` / `strong_ref` — 强引用须有身份（见身份协议）；**有 `identity_code` 时务必带 `identity_scope`（三点分）**，禁止手拼 `identity_path`，用 `w_scope` / Ambient。

### 强引用（`strong_ref="1"`）必做清单

选图弹 toast「强引用选图缺少媒体身份…」几乎都是这一条没齐：

| 项 | 要求 |
|----|------|
| 页首脚本 | 同步加载 `@static(Weline_FileManager::js/w-scope.js)`（仅有 `identity_*` 碎片时前端要调 `window.w_scope`） |
| 显式 path（推荐） | PHP 用 `w_scope(...)` / `ConfigMediaReferenceTemplates::config(...)` 算出 path，传入 Block/Tag 的 **`identity`**（写成 `data-w-identity`），避免 Theme 异步竞态 |
| 碎片字段 | 仍带 `identity_root` + `identity_code` + `identity_scope`（三点分）+ slot（`kind`/`field`/`component`…） |
| 域 | 配置/后台自助字段优先 `identity_root="config"`（`sc.config.media`）；不要用未登记域拼碎片却不传 `identity` |

**WelineMedia Block 示例（后台个人头像）**：

```php
<script src="@static(Weline_FileManager::js/w-scope.js)"></script>
<?php
$identity = \Weline\FileManager\Service\MediaReference\ConfigMediaReferenceTemplates::config(
    'default.default.default',
    'backend_profile_avatar/' . $safeUser,
    ['kind' => 'media', 'field' => 'avatar', 'component' => 'backend', 'ns' => 'backend']
);
echo framework_view_process_block([
    'class' => \Weline\MediaManager\Block\WelineMedia::class,
    'target' => 'profile-avatar',
    'picker_title' => (string)__('选择头像'),
    'path' => 'backend/avatar/',
    'identity' => $identity->path,          // 显式 path，勿手拼
    'identity_root' => 'config',
    'identity_code' => 'backend_profile_avatar/' . $safeUser,
    'identity_scope' => 'default.default.default',
    'identity_kind' => 'media',
    'identity_field' => 'avatar',
    'identity_component' => 'backend',
    'ref_mode' => 'single',
    'strong_ref' => '1',
    // …
]);
?>
```

对照实现：`Weline_Backend` 个人中心头像页、`Weline_StoreMusic` Config、`Weline_Backend` Logo、`Weline_Blog` 封面。

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

模板自闭合标签，源码 `Weline\FileManager\Taglib\Image`。经 `FileImageRenderer` → `FileImageReferenceNormalizer` 把已持久化引用渲成带 CLS 占位的 `<img>`。

```html
<w:file:image usage="heroUsage" width="16" height="9" class="hero-media" />
<!-- 或 -->
<w:file:image asset="assetId" alt="已确认的替代文本" aspect_ratio="16/9" complement="true" />
```

| 属性 | 说明 |
|------|------|
| `usage` | 运行时 `ImageUsage` / file-image JSON / 相对 path / `asset://`（读侧归一） |
| `asset` | 无 usage 时按 asset id（或 path / `asset://`）+ `alt` 渲染 |
| `width` + `height` **或** `aspect_ratio` | **必填其一**：输出 HTML width/height 防 CLS |
| `alt` / `decorative` / `locale` / `class` / `complement` | 替代文本、装饰图、语种、样式、补全策略 |

### 读侧兼容（写侧分域不变）

| 输入形态 | 行为 |
|----------|------|
| `ImageUsage` / usage 数组 | 直接使用 |
| `{type:"file-image", usage:{…}}`（含 JSON 字符串） | unwrap 后使用 |
| 裸 UUID / `asset://{uuid}` | 构 `ImageUsage` |
| 相对媒体 path | 按 `object_key`（默认盘 `local::filesystem::media`）查 `FileAsset`；未命中 → 空串，**禁止** path 当 `img.src` |

**写契约仍分域**：Theme / 布局继续只写 typed `file-image`；SystemConfig / Logo / 头像等继续 path。禁止为「兼容」静默把配置域改成只存 asset。

**编译期静态镜像**：当全部属性为字面量、已设 layout，且当前请求具备 ScopeIdentity/locale 可解析时，`callback` 直接烘焙最终 `<img>`；否则仍吐运行期 `FileImageRenderer` PHP（见 Taglib `如何自定义Tag.md`「编译期静态镜像」）。

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
