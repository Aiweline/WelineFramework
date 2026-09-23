# homepage-p1 · Team:主题开发工程师: 返工

日期：2026-09-23T12:55+08:00  
席位：`Team:主题开发工程师:`  
`work_mode`：**`design_theme`**（hanfu / theme_id=3）  
对照证据：`channel/p1-reverify-evidence.md`（vh≈645，containerW≈700）  
SESSION：`app/code/Weline/Theme/doc/开发/session/hanfu-theme-editor-optimize.md`  
`notify_pm: true`  
`@项目经理：本席已交付/上报，请检查并更新 SESSION`

## result

**`result=done`**（本席范围 CSS 已落盘）  
**验收未本席复测**（禁 `server:start/stop` / `theme:active` / 发布）；请测试按编辑器草稿禁缓存复测 UC-P1-01/02。

未做：拆信任条；清空水墨 Hero；同 key 覆盖 `theme.css`/`theme.js`；改 HotCache；发布激活。

---

## 目标对照

| 缺口 | 主题对策 |
|------|----------|
| chrome 视觉≈380px（意向 ≤~160） | 压 notice/belt/logo/search/nav；盖过 ink `padding-block: space-6` |
| Hero≈40vh 边缘 + 窄宽文案下沉 | Hero `min(28vh,14rem)`；强制文案绝对叠图；dots/pause 绝对定位 |
| 精选 top≈1123 出 fold | chrome+Hero+信任间距压缩 → 精选上移入 fold |
| deals_top_vh≈3.65 | 信任→精选→品类垂直再压；媒体 68%→52%；区头 margin 收紧 |
| 700px 只渲 2 列 | 布局内联 MQ：`columns-4` 保持到 >560（旧 768→2 是抬高货架主因之一） |

---

## 改动清单（选择器 / 数值）

### A. `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`
内联 `style[data-theme-inline=layout-homepage-default]`

| 选择器（摘要） | 旧 → 新 |
|----------------|---------|
| `.homepage-hero + .homepage-section` padding-block-start | `space-2` → `0` |
| `body...hanfu-atelier-chrome` | 新增 `--header-logo-height: 1.75rem` / compact `1.5rem` |
| `...header-site-notice-inner` | padding `space-1` → `0.125rem` |
| `...header-belt` | `space-2` → `space-1 !important`；`min-height:0 !important` |
| `...header-logo :is(img,.logo-image,…)` | max-height compact + `height` 锁 `1.5rem` |
| `...header-search-form` / input / submit | 高度锁 `size-control-sm`（2rem） |
| `...header-search-hot-words` | `display:none` |
| `...header-main-nav-inner` | min-height ≈ `1.75rem`；字号 `xs` |
| `.homepage-hero .slide.active` / `.slide-media img` | `min(40vh,20rem)` → **`min(28vh,14rem)`**（≤768 用 `13rem`） |
| `.homepage-hero .slide-content` | **强制 `position:absolute; inset:0`**（盖过窄宽下沉） |
| `.homepage-hero .slider-dots` / `.slider-pause` | 绝对贴底，去掉 static 抬高 |
| `.homepage-hero .slide-title` 等 | 字号/边距再收；subtitle 2 行 clamp |
| `.homepage-hero + .trust-strip` / trust badges | padding → `0` / `0.125rem`；badge 更矮 |
| `.trust-strip + .featured-lead` | padding `space-2` → `space-1` |
| `.featured-lead .products-grid.columns-4` | **默认 4 列 `!important`**；**仅 `max-width:560px` → 2 列**（删除旧 768→2） |
| `.featured-lead .wpc-media` padding-top | **68% → 52%** |
| `.featured-lead` 卡 CTA min-height | `control-height-md` → `size-control-sm` |
| `.widget-header` 统一 margin-block-end | `space-6` → `space-2` |
| `.category-strip` | padding `space-2` → `space-1`；媒体 max-height `2.75rem` |
| featured→category→deals 段 padding | `space-2/3` → `space-1/2` |
| Hero CTA | 兼容 `.w-button-primary` + 矮按钮 token |

### B. `app/design/Weline/hanfu/frontend/assets/css/hanfu-homepage.css`

| 选择器 | 旧 → 新 |
|--------|---------|
| `@media (max-width:768) .hanfu-default-hero .slide-content` | `position:relative` + 下沉 padding → **`absolute` 叠图** |
| 同档 `.slide-media img` | `height:auto; contain` → **`min(28vh,13rem)` cover** |
| `.slider-dots` / `.slider-pause` | static 抬高 → absolute 贴底 |

槽序 HTML：**未改**（仍 Hero→信任→精选→品类→特价）。

---

## escalate（他席）

### 1. `Team:部件开发工程师:` · UC-P1-04
实测 `solid_ctas_active_slide=0`。设计模板主 CTA class 为 `slide-button w-button w-button-primary`（非 `w-button--primary`）。请核对：
- 草稿实体是否覆盖掉默认 slide（无 `button_text` / 未吐主 CTA）；
- 预览 DOM 活跃 slide 是否真有实心主钮（背景 primary）；
- 若仅 class 命名与测试探针不一致，与测试对齐探针或统一 BEM。

主题侧已兼容两种 class 的矮按钮样式，**不能**代修部件 PHP 配置实体。

### 2. `Team:前端:` · UC-P1-03 协同确认
本席已把布局内联 **768→2** 改为 **560→2**，与 `widget-hanfu-product-featured-products-default.css` 的 559 断点对齐。若前端模块 CSS（非 hanfu 部件皮）仍有 `@media (max-width:768) .columns-4 → 2` 且 source order 压过布局，请同步降到 ≤560。  
证据里的 `344px 344px` 与旧布局 768 MQ 一致；复测应见 `repeat(4, …)`。

### 3. `Team:测试:`
请在 theme_id=3 编辑器草稿 `#previewFrame`（禁缓存 + 抹 webdriver）复测：
- UC-P1-01：scrollY=0 ≥4 带价卡 + ≥1 真 ATC/Buy
- UC-P1-02：槽序不变；`deals_top_vh ≤ 1.5`
- 顺带确认 UC-P1-03 列数（主题已改断点）

---

## 禁止项确认

- **未** `server:start` / `server:stop`
- **未** `theme:active` / 发布
- **未** 同 key 覆盖 `theme.css` / `theme.js`
- **未** 改 HotCache
- **未** 拆信任条 / 清空水墨 Hero

paths_changed：
- `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`
- `app/design/Weline/hanfu/frontend/assets/css/hanfu-homepage.css`
- `app/code/Weline/Theme/doc/开发/team/hanfu-theme-editor-optimize/channel/homepage-p1-theme-rework.md`
