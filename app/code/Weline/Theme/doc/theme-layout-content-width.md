# 主题内容区宽度 Token（layout content width）

> **高压线（MCP `frontend_unified_content_container`）**：前台 layout / 业务页 / 部件 / 模块 CSS **必须**套用下方壳层 A 或 B，与 Header/Footer 共用同一套内容区宽度 Token。  
> **禁止**自写第三套「页面容器」（私有 `max-width` + `margin:auto` + `padding-inline`）、`1440px` / `1280px` / `1180px` / `1200px` / `90rem` 等字面量版心，或在已有 `.w-container` 外再套一层等宽壳。  
> 关联：`theme-css-variables-only.md`（颜色/间距 Token）、`ThemeDefaultContainerWidthContractTest`、`ThemeFrontendLayoutsContentWidthContractTest`、`ThemeStorefrontModuleContentWidthContractTest`。

## 权威 Token

| Token | 定义链（叶子在 `variables/_spacing.css`） | 用途 |
|---|---|---|
| `--weline-layout-content-max-width` | `_spacing.css` → `--layout-max-width` → `--spacing-container-max-width` | 内容区最大宽度 |
| `--weline-layout-content-padding-inline` | `_spacing.css` → `clamp(0px, (max-width - 100vw) * 9999, --spacing-container-padding)` | 内容区左右内边距；**视口 ≥ max-width 时为 0，窄屏保留 gutter** |

**前台 head 必须加载** `theme/frontend/variables/_spacing.css`（见 `partials/head/default.phtml`），否则 `--weline-layout-content-*` 未定义时 Header 会撑满视口、`.w-container` 却走私有 fallback，版心左沿错位。

Header / Footer / `.w-container` 已消费上述 Token；业务页不得使用不同 fallback 导致左右沿错位。

### PC 最宽时 padding 归零

`--weline-layout-content-padding-inline` 用 `100vw` 与 `--spacing-container-max-width` 比较：视口达到或超过版心最大宽度时计算为 `0`，避免顶栏/版心在 1440 满宽时仍出现左右绿色 padding；窄于 max-width 时仍等于 `--spacing-container-padding`。禁止再写第二个 `1440px` 媒体断点字面量。

## Homepage Shell B 特例（`layouts/homepage/default.phtml`）

Homepage 未包 `.w-container`：非 Hero/Promo 分区由布局给子节点套版心 max-width + `padding-inline`；**`.homepage-hero` / `.homepage-promo`（及 `:has(hero-slider|promo-banner)` 兜底）必须 `padding-block: 0`**，避免默认 Banner 与上方导航之间出现布局白缝。分区纵向间距只留给其余 `.homepage-section`。

主题编辑器全页预览（`editor_mode`）下，Hero 不得因 `preview_mode=true` 被压成部件库卡片高度（120px）；紧凑高度仅用于部件库卡片预览。

## 两种壳层（必选一，禁止混用）

### A. 布局已包 `.w-container`（如 `default.default`）

`layouts/default/default.phtml` 已在 `main` 内提供：

```html
<div class="w-container w-frontend-page">…</div>
```

**业务页根节点必须：**

```css
.my-page {
    width: 100%;
    max-width: none;
    margin-inline: 0;
    padding-inline: 0; /* 禁止再写 horizontal padding */
}
```

仅允许 `padding-block` 控制上下间距。Hero 全宽视觉在容器内用 `width: 100%`，不要另设 `max-width`。

### B. 布局未包 `.w-container`（如 `checkout` / `cart` 专用 layout）

页面根节点（或唯一内容壳）**自己**承担宽度：

```css
.my-page {
    box-sizing: border-box;
    width: min(100%, var(--weline-layout-content-max-width));
    max-width: 100%;
    min-width: 0;
    margin-inline: auto;
    padding-inline: var(--weline-layout-content-padding-inline);
}
```

**禁止** `var(--weline-layout-content-max-width, 1440px)` 等带像素 fallback——宽度由 Theme 盘统一解析。

也可在 HTML 根节点加 Foundation 工具类 `.w-theme-content-width`（见 `weline-foundation.css`）。

## 部件（Widget）在 slot 内

注入 `default.default` 等已含 `.w-container` 的 slot 时，部件根节点**禁止**：

- `max-width: var(--layout-max-width)` + `margin: 0 auto`
- `padding-inline: var(--weline-space-6)` 等自造左右 gutter

应改为：

```css
.widget-root {
    width: 100%;
    max-width: none;
    margin-inline: 0;
    padding-inline: 0;
    padding-block: var(--weline-space-8); /* 仅纵向 */
}
```

## 颜色与局部视觉例外

- **宽度 / 左右 gutter**：无例外，必须走上述 Token 或 `.w-container`。
- **颜色 / 渐变 / Hero 暗色底**：模块可在局部 scope（如 `.w-promotion-page__hero`）自定义，但应优先 `--weline-theme-*` / `--weline-chrome-*`；Amazon 风格 CTA 等**特质色**允许在模块 CSS 内写语义别名（如 `--promo-cta-bg`），不得借此绕过宽度约束。

## 禁止写法

```css
/* 禁止：与 Header 不同源的 fallback */
max-width: var(--weline-layout-content-max-width, 1440px);
padding: 0 24px;
max-width: 1200px;
width: 1180px;

/* 禁止：在 .w-container 内再套一层 max-width + padding-inline */
.page { max-width: var(--layout-max-width); padding: 0 1.5rem; }
```

## 验收

1. 1280 / 768 断点：Header 内容左沿 ≈ 页面主内容左沿（DevTools 量 `.w-container` 或页面根 `padding-inline`）。
2. Theme Editor 修改 `--spacing-container-max-width` / `--spacing-container-padding` 后，Header 与 Promotion/Cart/Checkout 同步变化。
3. 契约测试：`ThemeDefaultContainerWidthContractTest`、模块 `*LayoutWidthContractTest`（如有）。
