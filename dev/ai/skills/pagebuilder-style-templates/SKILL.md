---
name: pagebuilder-style-templates
description: PageBuilder 主题 style 模板开发规约。目录结构、@fields 配置、组件 PHP/CSS 约定、head-common/footer-common、下载 data-glr-*、禁止 header 重复 pixel。生成或修改 style 下主题/组件时必读。
globs: ["**/GuoLaiRen/PageBuilder/view/templates/style/**"]
alwaysApply: false
---

# pagebuilder-style-templates

生成或修改 `app/code/GuoLaiRen/PageBuilder/view/templates/style/` 下主题、layout、header、footer、组件时**必读**本技能，以保证新模板符合现有规约、可被 PageBuilder 正确加载与配置。

---

## 1. 目录与文件结构

```
style/
├── readme.md                    # 规约摘要（见本技能）
├── _shared/partials/
│   ├── head-common.phtml        # 全主题公用：统计 + <pixel>，勿改
│   └── footer-common.phtml      # 全主题公用：下载委托，勿改
└── {theme}/                     # 主题名：sattaking, poker-arena, ludo-empire, rummy-royal, fitness-pro, saas-starter, fintech-hub, tpmst, default
    ├── readme.md                # 主题说明（可选）
    ├── layout.phtml             # 有 layout 的主题：整页骨架，含 head/body/header/main/footer
    ├── header.phtml             # 独立头部（default 或 被 layout 引用时的配置入口）
    ├── footer.phtml
    ├── content.phtml            # 可选，部分主题用
    ├── colors/
    │   ├── default.phtml        # 必须：$colors 数组 + SCHEME_NAME/SCHEME_DISPLAY_NAME
    │   └── *.phtml              # 其他色系
    ├── components/
    │   ├── component.json       # 必须：regions、components、file 路径、default_config
    │   ├── header/              # 如 nav.phtml
    │   ├── content/             # 如 hero.phtml, app-download.phtml
    │   └── footer/              # 如 links.phtml
    └── layouts/                 # 可选：home_page.json 等
```

- **模块模板路径**：一律使用 `GuoLaiRen_PageBuilder::templates/...` 或 `GuoLaiRen_PageBuilder::style/{theme}/...`（与 `register.php` 一致）。
- **default 主题**无 `layout.phtml`，整页由 header.phtml + footer.phtml 等拼成，也需接入 head-common / footer-common。

---

## 2. 主题前缀与命名

- 每个主题使用**固定 CSS/ID 前缀**，避免多主题同页时类名冲突：
  - sattaking → `sk-`
  - poker-arena → `pa-`
  - ludo-empire → `le-`
  - rummy-royal → `rr-`
  - fitness-pro → `fp-`
  - saas-starter → `ss-`
  - fintech-hub → `fh-`
  - tpmst → `tpmst-`
- **组件根元素**：必须有唯一 `id`，用于作用域样式与避免重复绑定。格式：`$instanceId = '{prefix}-{组件简短名}-' . uniqid();`，例如 `sk-download-' . uniqid()`、`pa-cta-' . uniqid()`。
- **CSS 类名**：组件内类名以主题前缀开头，如 `.sk-download-container`、`.pa-cta-btn-primary`。

---

## 3. 组件配置：@fields_start / @fields_end

- 组件 phtml **顶部**用块注释声明可配置项，供可视化编辑器解析：
  - `@fields_start` 与 `@fields_end` 包裹。
  - **分组**：`group:分组名 => 分组标题`（分组标题为后台分组名）。
  - **字段**：`config.key => 标签:类型:默认值`。
- **常用类型**：`text`、`textarea`、`number`、`color`、`select`。
  - `select` 格式：`select:选项1|选项2,选项3`（竖线前为默认选中，逗号分隔多选时可依实现而定）。
  - 带单位：`number:80|px`、`responsive:60/80|px`（移动/PC）。
  - 格式说明可写在末尾，如 `|格式：名称=>链接，一行一个`。
- **示例**：

```php
/**
 * @fields_start
 * group:content => 内容设置
 * content.title => 标题:text:Welcome
 * content.description => 描述:textarea:Description here.
 * group:buttons => 按钮设置
 * buttons.primary_text => 主按钮:text:Download
 * buttons.primary_url => 主按钮链接:text:#download
 * buttons.show_secondary => 显示次按钮:select:yes|yes,no
 * @fields_end
 */
```

- 组件内读取：`$config = $component_config ?? [];`，再用 `$config['content.title'] ?? '默认'` 等形式，键名与 @fields 中 `config.key` 一致。

---

## 4. 组件 PHP 约定

- **传入变量**：layout 通过 `assign` 传入 `page`、`style`、`style_settings`、`component_config`。组件内应兼容 `$component_config` 未设置：`$config = $component_config ?? [];`。
- **颜色**：`$colors` 由 layout/header 通过 `base/colors.phtml`（或 `<w:template>GuoLaiRen_PageBuilder::base/colors.phtml</w:template>`）注入，组件内先做防护：`if (!isset($colors) || !is_array($colors)) { $colors = []; }`，再 `$colors['text_primary'] ?? '#fff'` 等。
- **主题专用辅助函数**：若在 layout 或 header 中定义（如 `sk_getConfig`、`fp_getConfig`），名字带主题前缀，且用 `function_exists` 包裹避免重复定义；组件内直接使用即可。
- **输出转义**：所有来自配置或用户的输出必须 `htmlspecialchars()`（或等价），属性内用 `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`。
- **链接/锚点**：配置中的 URL 若可能为锚点或相对路径，可用 `\GuoLaiRen\PageBuilder\Helper\PageHelper::normalizeAnchorUrl($url)` 规范化；**下载跳转**用 `PageHelper::resolveAppDownloadUrl()` 得到最终 href（见 §7）。

---

## 5. 组件样式（CSS）约定

- **作用域**：组件样式必须限定在**当前组件根**，避免污染全局。根元素 `id="<?= $instanceId ?>"`，样式选择器一律以 `#<?= $instanceId ?>` 开头（或 `#<?= $instanceId ?> .xxx`）。
- **内联样式块**：在组件内使用 `<style>` 包住，所有规则写在 `#<?= $instanceId ?>` 下；不依赖全局类名（如 `.sk-download` 可保留，但主要依赖 id 作用域）。
- **颜色**：尽量使用 `$colors` 中的变量（如 `$textPrimary`、`$buttonPrimaryBg`），避免硬编码色值；与主题色系一致。
- **响应式**：使用 `@media (max-width: 768px)` 等断点，保持与现有主题一致（常见 576px / 768px / 1280px）。

---

## 6. layout.phtml 与公共片段

- **块注释铁律（.phtml）**：`/* … */` / `/** … */` 内**禁止**出现字面量 `*/`（例如不要写「`<?php /* hash */ ?>`」这类示例）。`*/` 会**提前结束**注释，后面整段会变成可执行 PHP 或裸 HTML，直接泄露到页面。说明哈希头、引入方式时用**纯文字**或拆成多行且避免星号+斜杠连续出现。
- **有 layout 的主题**：
  - 在 `</head>` **前**插入：`<?php echo $this->fetchTemplate('GuoLaiRen_PageBuilder::templates/style/_shared/partials/head-common.phtml'); ?>`
  - 在 `</body>` **前**插入：`<?php echo $this->fetchTemplate('GuoLaiRen_PageBuilder::templates/style/_shared/partials/footer-common.phtml'); ?>`
- **default 主题**（无 layout）：在 **header.phtml** 的 `</head>` 前注入 head-common（并 `assign('is_preview', …)`、`style`、`page` 等）；在 **footer.phtml** 的 `</body>` 前注入 footer-common。
- **禁止**：在各主题 **header.phtml** 末尾再输出 `<pixel>` 或再设置 `WELINE_WEBSITE_*` Cookie（已由 head-common → base/tracking.phtml 统一处理）。勿修改 `Weline/Visitor/.../pixel.phtml` 来解决主题问题。

---

## 7. 下载按钮（硬性）

- **统一委托**：所有“下载/跳转”类按钮由 **footer-common.phtml** 处理：页面渲染时 PHP 用 **`GlrDownloadRegistry::register()`** 收集 ref→{href,slot,target}，footer 输出 **`#glr-pb-download-registry`** JSON；前端按 **`a[data-glr-ref]`** 查表，先条件发送 `WelinePixel.send`，再跳转。
- **组件内写法（推荐，DOM 不写真实 URL）**：
  - `$primaryHref = PageHelper::resolveAppDownloadUrl((string)$primaryUrl);`
  - `$primaryRef = \GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::register($primaryHref, 'primary');`（slot：`primary` | `secondary` | `android` | `ios` | `url`，与像素事件映射一致）
  - `<a href="#" ... data-glr-ref="<?= htmlspecialchars($primaryRef) ?>">`
  - **新窗口**：`register(..., '_blank')` 或在 `<a>` 上 **`data-glr-target="_blank"`**。
- **兼容旧写法**：`data-glr-download` + `data-glr-href` 仍可用，**新主题/新组件勿新增**。
- **禁止**：在组件内为下载再写 `querySelectorAll('[data-download-trigger]')` 等内联绑定脚本；禁止使用 `weline-pixel::download-*` 类（与 footer-common 的 send 重复）。
- **示例**：

```php
$primaryHref = \GuoLaiRen\PageBuilder\Helper\PageHelper::resolveAppDownloadUrl((string)$primaryUrl);
$primaryRef = \GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::register($primaryHref, 'primary');
?>
<a href="#" role="button" class="fp-cta-btn-primary" data-glr-ref="<?= htmlspecialchars($primaryRef) ?>">
  <?= htmlspecialchars($primaryText) ?>
</a>
```

---

## 8. 颜色配置（colors/*.phtml）

- 每个色系文件返回 **`$colors` 数组**，键为用途名（如 `text_primary`、`hero_bg`、`button_primary_bg`）。
- 文件顶部注释约定：`SCHEME_NAME`、`SCHEME_DISPLAY_NAME`、`SCHEME_DESCRIPTION`（可选）。
- 值可为 hex、rgba、或 `linear-gradient(...)` 等合法 CSS 值。
- 加载逻辑在 **base/colors.phtml**：根据 `template_code` 与 `style_settings['color_scheme']` 加载 `style/{theme}/colors/{scheme}.phtml`，不存在则回退 `default.phtml`。

---

## 9. component.json

- **template**：主题代码，与目录名一致。
- **regions**：`header`、`content`、`footer`，各 region 的 `accepts`、`default_component`/`default_components` 与 layout 中 `$componentFiles` 对应。
- **components**：每个组件需有 `name`、`name_en`、`region`、`category`、`type`、`file`（相对 components 的路径，如 `content/hero.phtml`）、`default_config`（键与 @fields 中 config.key 一致）、`config_groups`（可选）、`sort_order`、`is_default` 等。
- **file** 路径与 layout 中 `$componentFiles` 的 value 一致，例如 `'hero' => 'content/hero.phtml'`。

---

## 10. layout 中的组件渲染

- **$layoutConfig**：从 `$this->getData('layout_config')` 取，结构为 `['header' => [...], 'content' => [...], 'footer' => [...]]`，每项为组件列表，元素含 `code`、`enabled`、`config`。
- **$componentFiles**：映射 `code` → 相对 components 的路径（如 `'hero' => 'content/hero.phtml'`）。
- **渲染闭包**：传入 `page`、`style_settings`、`component_config`，用 `$this->fetchTemplate("GuoLaiRen_PageBuilder::templates/style/{theme}/components/{$file}")` 输出；渲染前 `assign` 上述变量。
- 若某 region 为空，可给默认组件列表，保证页面至少有一栏。

---

## 11. 与其它技能的关系

- **visitor-pixel**：页面级 UV/PV 与 `<pixel>` 由 head-common → base/tracking.phtml 负责；下载事件名由 footer-common 映射。细节见 `dev/ai/skills/visitor-pixel/SKILL.md`。
- **theme-development**：CSS 尽量用主题变量、响应式与暗色兼容，见 `theme-development` 技能。
- **i18n-internationalization**：用户可见文案用 `__()` 或模板中的 i18n 机制；@fields 中的标签为后台配置用，可按需加多语言。

---

## 12. 自检清单（生成/修改主题或组件时）

- [ ] 主题前缀与类名/instanceId 一致（sk/pa/le/rr/fp/ss/fh/tpmst）。
- [ ] 组件有 `@fields_start` / `@fields_end`，键名与 component.json 的 default_config 一致。
- [ ] 组件内 `$config = $component_config ?? [];`、`$colors` 防护、输出全部 `htmlspecialchars()`。
- [ ] 样式全部限定在 `#<?= $instanceId ?>` 下。
- [ ] 下载按钮使用 `GlrDownloadRegistry::register` + `data-glr-ref`（或仅兼容旧写法），无内联下载脚本、无 `weline-pixel::download-*`。
- [ ] layout 在 `</head>` / `</body>` 前正确引入 head-common / footer-common；default 主题在 header/footer 中引入。
- [ ] 未在 header.phtml 中重复输出 `<pixel>` 或 WELINE_WEBSITE_* Cookie。
- [ ] fetchTemplate 路径使用 `GuoLaiRen_PageBuilder::templates/...` 或 `GuoLaiRen_PageBuilder::style/...`。
- [ ] **`.phtml` 文件内未写 `declare(strict_types=1)`**（hash 注释或空白会使其不是第一行语句，触发 `E_COMPILE_ERROR` 崩溃 WLS Worker）。
