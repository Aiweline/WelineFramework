# PageBuilder 样式模板规约

本目录下为各主题（style）的模板文件，遵循以下规约以保证行为一致、可维护。

**完整开发规约（目录结构、@fields、组件 PHP/CSS、component.json、自检清单）见：`dev/ai/skills/pagebuilder-style-templates/SKILL.md`**。生成或修改主题/组件时请先读该技能。

---

## 1. 公共区：`head-common` / `footer-common`

### 1.1 路径

- `style/_shared/partials/head-common.phtml` — 统一注入统计与 Visitor `<pixel>`（内部引用 `templates/base/tracking.phtml`）。
- `style/_shared/partials/footer-common.phtml` — 统一下载链接委托 + 条件 `WelinePixel.send`（预览不发）。
- `style/_shared/components/legal-content.phtml` — 法律页正文组件；默认段落由布局 JSON 的 `content.body` / `toc.items` 提供（`page.content` 可为空）。主题无 `layouts/default/{privacy_policy|terms_of_service|…}.json` 时，`ComponentService` 会回退到 `style/_shared/layouts/default/` 下同名的共享布局。

### 1.2 接入方式

- **有 `layout.phtml` 的主题**：在 `</head>` 前、`</body>` 前各 `echo $this->fetchTemplate('GuoLaiRen_PageBuilder::templates/style/_shared/partials/head-common.phtml');`（及 `footer-common`）。
- **`default` 主题**（无 layout）：在 **`default/header.phtml`** 的 `</head>` 前注入 `head-common`（并保证 `assign('is_preview', …)`、`style`/`page` 与预览一致）；在 **`default/footer.phtml`** 的 `</body>` 前注入 `footer-common`。
- **`default` / `_ai_frameworks`**：已提供 `components/component.json` 中的 **`legal-content`**（文件回退 `_shared`），以及 `layouts/default/` 下与 `_shared` 一致的法律页 JSON。`default` 的 `component.json` 含 **`"content_phtml_scan": "always"`**，避免仅补充 `legal-content` 时跳过对根目录 **`content.phtml`** 的组件拆分注册。

### 1.3 禁止

- 在各主题 **`header.phtml` 末尾**再输出 `<pixel>` 或重复设置 `WELINE_WEBSITE_*` 等 Cookie（已由 `tracking.phtml` 处理）。
- 修改 **`Weline/Visitor/.../pixel.phtml`** 来解决主题侧问题（应在 PageBuilder/主题层完成）。

---

## 2. 下载入口（硬性规约）

**`style/` 下凡属「应用下载 / APK / 商店包 / 由 `resolveAppDownloadUrl` 解析的落地下载」的交互，一律走注册表 + `footer-common` 委托，禁止例外另写一套点击逻辑或把真实下载直链暴露在可点击元素的 `href` 上。**

包括：**各主题 `components/content/*`（hero、app-download、cta-banner 等）、`header.phtml` / `components/header/nav.phtml` 的 CTA、`_shared` 组件、tpmst 下载按钮、`_ai_frameworks` / `_ai_generated` 中带下载意图的 CTA**。

### 2.1 必须怎么做（三步）

1. **解析**：`$resolved = \GuoLaiRen\PageBuilder\Helper\PageHelper::resolveAppDownloadUrl((string)$rawUrl);`  
   配置里的占位（如 `#`、`#download`、`/#download`）在 PHP 侧解析为最终 URL；普通站内锚点（如 `#hero`）解析后仍为锚点，行为不变。
2. **登记**：`$code = \GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::register($resolved, $slot, $openTarget);`  
   - `$code` 由 **下载 URL 的 SHA-256** 稳定派生（`glr_` + 64 位 hex），同一 URL 同页多次 register 得到同一 code。  
   - `$slot`：`primary` | `secondary` | `android` | `ios` | `url`（与像素事件映射一致，见下表）。
3. **模板**：可点击元素使用  
   - `href="<?= htmlspecialchars(\GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::codeHref($code)) ?>"`（即 `#` + code，**不写真实 APK/http 直链**）  
   - `data-glr-ref="<?= htmlspecialchars($code) ?>"`（与 code 一致，无 `#`）  
   - 建议：`role="button"`、`tabindex="0"`。  
   仅写 `href="#glr_…"`、不写 `data-glr-ref` 时，`footer-common` 仍可从 `href` 解析 code（次选，推荐两者都写）。

### 2.2 页面前提

- 正式/预览页由 **`PageRenderService` 已注入 `footer-common`**（或主题 `layout.phtml` / `default` header+footer 已引入）。无 `footer-common` 则注册表脚本不存在，点击无效。

### 2.3 `register` 的 slot（`footer-common` → 像素事件）

| 登记时 `$slot` | 行为（映射） |
|----------------|----------------|
| `primary` | `download-click` |
| `secondary` | `download-secondary` |
| `android` / `ios` | `download-click` |
| `url` | `download-click` |

### 2.4 新窗口

- `register(..., '_blank')`，或元素上 **`data-glr-target="_blank"`**（先发像素再 `window.open`）。

### 2.5 完整示例

```php
$primaryHref = \GuoLaiRen\PageBuilder\Helper\PageHelper::resolveAppDownloadUrl((string)$primaryBtnUrl);
$primaryCode = \GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::register($primaryHref, 'primary');
```

```html
<a role="button" tabindex="0" class="sk-download-btn-primary"
   href="<?= htmlspecialchars(\GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry::codeHref($primaryCode)) ?>"
   data-glr-ref="<?= htmlspecialchars($primaryCode) ?>">
  <?= htmlspecialchars($primaryBtnText) ?>
</a>
```

### 2.6 禁止

- 在主题模板里为下载 **再写** `addEventListener` / 内联脚本扫按钮发跳转（统一由 `footer-common` 捕获）。  
- **勿新增** `a[data-glr-download][data-glr-href]`（遗留仅由 `footer-common` 兼容）。  
- **勿新增** `data-download-trigger`、下载专用 **`weline-pixel::download-click`** 类与 `footer-common` 重复发像素。  
- 非下载类统计：`data-track-event`（见 `tracking.phtml`）。

### 2.7 空 URL / 无效解析

- 解析结果为不可导航时，委托不跳转；无需在组件里再写一套判空下载脚本。

### 2.8 同一真实 URL 多次按钮

- 同一 `$resolved` 对应同一 `$code`，注册表条目以 **首次** `register` 的 slot/target 为准；若同一 APK 需不同像素事件，请在配置上区分 URL（如不同 query 串）。

---

## 3. 访客像素与其它统计

- 主题侧以 **`head-common` → `tracking.phtml`** 输出 `<pixel>` 与 GTM/GA/Meta；**无需**在业务组件里手写 `WelinePixel.send()` 处理常规下载。
- 自定义点击统计：元素上 `data-track-event`（详见 `tracking.phtml` 头注释）。
- 拓展约定见 **`dev/ai/skills/visitor-pixel/SKILL.md`**、**`dev/ai/skills/pagebuilder-style-templates/SKILL.md`**。

---

## 4. 主题目录结构（参考）

```
style/
├── readme.md                 # 本规约
├── _shared/partials/         # 全主题公用 head/footer 片段
├── {theme}/                  # 如 sattaking、poker-arena
│   ├── readme.md             # 主题说明
│   ├── layout.phtml
│   ├── header.phtml
│   ├── footer.phtml
│   ├── content.phtml
│   ├── colors/
│   ├── components/
│   │   ├── component.json
│   │   ├── header/
│   │   ├── content/
│   │   └── footer/
│   └── layouts/
```

新增或修改**任意下载相关**模板时，**必须**遵守 **§2**；并确认页面已加载 **§1** 中的 `footer-common`（或由 `PageRenderService` 注入）。
