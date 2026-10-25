# theme.css：架构层 vs 风格层（硬分责）

> 硬规则 id：`theme_css_architecture_not_style_shell`  
> 席位指令：[主题开发.md](../../../../../dev/ai-command/ai/主题开发.md) Mode A  
> 互补：`theme_design_must_not_override_core_runtime_assets`（design **禁止覆盖** theme.css）≠ 本文（theme.css **禁止写风格壳**）

## 一句话

`view/theme/{area}/assets/css/theme.css` 是**主题架构 CSS**：定布局骨架、表面机制、全局组件与 Token 侧效应。  
**禁止**把厂商/品牌皮肤 / 单页英雄区等**风格壳**写进 `theme.css`，也**禁止**塞进 **theme-head 主题合包**（`ThemeHeadChromeCssPack`）。

Token/变量：后台外观盘 → `theme:disk:compile` → **独立 disk link**（主题包之后）。  
风格叶（`*-{vendor}.css` 等）：进部件 `layout_source` / `source` bake，**不进** theme-head。

## Head CSS 加载序（权威 · 2026-10-14 业界又快又准）

```text
1) theme:layout-critical          ← 极小骨架（独立；FOUC 解锁依赖它已在 DOM）
2) ThemeHeadChromeCssPack STOREFRONT
     叶序：foundation → frontend → theme.css → variables → colors
     （无 disk、无 design overlay、无 data-weline-layout-css）
3) ThemeDiskHeadService override  ← 主题配置合并 CSS
4) ThemeHeadChromeCssPack OVERLAY ← design overlay（独立 pack）
5) 大布局包（widget layout_source 合包，data-weline-layout-pack）
6) 其他 source CSS
```

## 编译合并链（权威 · 含变量）

```text
Theme Editor 外观盘 / Brand（ThemeData Meta）
        │
        ▼
php bin/w theme:disk:compile
  → generated/theme/disks/.../override.{hash}.css
        │
        ▼
<head>：critical → 主题 pack → disk link → overlay pack → 大布局包 → 其他
```

要点：

1. **主题 pack 内**：布局/壳 CSS → 尺寸变量 → 颜色（同包层叠）。  
2. **disk 在主题后**：配置覆盖正确，且不挡极小骨架首段。  
3. **风格叶不进 theme-head**：走部件 bake。  
4. **layout-critical 不合进大布局包**：保持极小独立。

## 能写（架构 · ALLOW）

| 类别 | 示例 |
|------|------|
| 版心 / 布局架构 | `.w-container`、内容宽、区域分区、全局栅格与结构工具类 |
| 表面机制 | `[data-surface=inverse]` / `.w-surface-inverse` 及 nested raised 纸面恢复（WO-UI-CONTRAST-INVERSE） |
| 全局组件契约 | 与 Weline UI / Taglib 全局控件相关的 **token 桥接与侧效应** |
| Token 桥接 | `--weline-theme-*` ↔ `--color-*` / `--w-surface-*` 等跨层别名（不含品牌皮肤字面量堆砌） |
| 无障碍 / 运动 | `prefers-reduced-motion`、基础 `color-scheme` 等架构级护栏 |
| 布局 partial 结构 CSS | 如 `partials/header-default.css`（结构/槽位，**不是** `*-{vendor}` 皮肤） |

## 不能写（风格 · FORBID）

| 类别 | 示例 | 正确落点 |
|------|------|----------|
| 风格壳类名 | `.{vendor}-*`、`.w-{vendor}-*`、某品牌专属 BEM 皮肤块 | `view/statics/css/widgets/*-{vendor}.css` 等风格叶；**后台选型 → 编译/生成后发射** |
| 单页英雄/关于/FAQ/政策皮肤 | about/faq/policy hero 的厂商视觉细节 | 风格叶或 design 独立 brand CSS（非 theme.css） |
| Chrome 皮肤 | header/footer 厂商 chrome | `header-chrome-{vendor}.css` 等；部件 `@widget.layout_source` 或 Partials/design 壳 `SharedChromeService::emitPartialsHeaderShellLayoutCssHtml()`（layout + module-source → 大布局包）——**禁止裸 `<link>`**；**勿写入 theme-head 合包** |
| 购物者 toast（架构部件） | `.w-shopper-toast` / `.w-shopper-cart-added` | `storefront-shopper-toast.css`；由 `storefront-shopper-toast.js` ensure 加载；**勿写入 theme.css / PACK_STOREFRONT**；`*-{vendor}.css` 仅为兼容 `@import` shim |
| 站点/品牌开站货架视觉 | 某站专属色板字面量当「全局」 | 外观盘 + disk compile；或 design colors/variables + 独立 brand CSS（进 overlay，非硬嵌厂商皮肤） |

## 分责速查

```text
theme.css                         → 架构（所有 design 继承的机制）
colors/_*.css + variables/_*.css  → Token 源叶子（进 theme-head）
ThemeData 外观盘 → disk:compile   → override CSS（含变量覆盖，进 theme-head）
statics/css/widgets/*-{vendor}.css  → 风格叶（部件 bake / 页面链路；禁止进 theme-head 布局合包）
design/.../assets/css/{brand}*.css→ design 品牌皮（overlay）
layout-critical / theme-head pack → 仅布局/架构/Token/disk；禁止塞风格叶
```

## 判定口诀

1. **删掉类名后机制还在吗？** 在 → 可能是架构；只剩厂商/品牌皮肤 → 风格。  
2. **是否所有 design 都必须继承？** 是 → 架构；只服务某一皮肤/某一页 → 风格。  
3. **是否依赖 `.{vendor}-*` / `.w-{vendor}-*`？** 是 → 禁止进 `theme.css` / theme-head 布局合包。  
4. **加强合包 ≠ 拆前端风格**：本次计划只加强布局合包与 critical；不要去改 header 部件既有风格加载。

## 与 design 覆盖禁令的关系

- design **禁止**同 key 覆盖 `theme.css`（机制入口不能被皮肤顶空）。  
- Mode A 维护 theme.css 时，**同样禁止**把风格壳塞进这份架构文件。  
- 品牌化：外观盘 / colors / variables / disk compile；design 独立 brand CSS 走 overlay 列表，**不要**在主题模板硬 `@static` 风格叶。

## 验收

- 契约：`StorefrontShopperToastContractTest`（toast 不在 theme.css / 不在 PACK）、`ThemeHeadChromeCssPackContractTest`（合包无风格叶）、`ThemeSurfaceTextRolesContractTest`（inverse 机制仍在 theme.css）。  
- 目视：`rg -n '\\.{vendor}-|\\.w-{vendor}-' view/theme/*/assets/css/theme.css`（把 `{vendor}` 换成实际前缀） → 仅允许注释说明，禁止选择器规则。  
- 合包扫描：`ThemeHeadChromeCssPack` / layout-critical 源中不得出现 `*-{vendor}.css`。

---

# 版心（Container）与页面画布（Canvas）契约 —— 硬规则

> 硬规则 id：`theme_container_single_source` / `theme_page_canvas_token_only`
> 适用范围：**所有** design 主题

## 1. 只允许两个版心 token

| token | 含义 | 谁可以改 |
|---|---|---|
| `--weline-container-gutter` | 左右沟槽，`clamp(1rem, 0.9rem + 1.5vw, 2.5rem)` | 框架默认；主题如需不同节奏可整体覆写此 token |
| `--weline-layout-content-max-width` | 版心外框宽度（含沟槽） | 框架默认 1440px；主题可改为自己的值（如 1360/1260） |

派生别名（只读，不要各自定义新值）：
`--spacing-container-padding`、`--weline-layout-content-padding-inline` → `var(--weline-container-gutter)`。

## 2. 唯一容器实现

```css
.w-container,
.w-theme-content-width {
    box-sizing: border-box;
    width: min(100%, var(--weline-layout-content-max-width));
    margin-inline: auto;
    padding-inline: var(--weline-layout-content-padding-inline);
}
```

**禁止**：
- `width: calc(100% - 64px)` / 每个断点写一个 `padding-inline`；
- 自建等价容器（`.xx-container` 自己算 max-width + padding），要用就写
  `.xx-container { width: min(100%, var(--weline-layout-content-max-width)); margin-inline:auto; padding-inline: var(--weline-layout-content-padding-inline); }`；
- header / footer / breadcrumb / 正文各算一套 —— 它们必须同构，否则出现「Header 与正文不同步」。

header 若为通栏背景，内沿用与 `.w-container` **同一条数学**：
`padding-inline: calc(max(0px, (100vw - var(--weline-layout-content-max-width)) / 2) + var(--weline-layout-content-padding-inline))`。

## 3. 页面画布只能用 token

```css
body {
    background-color: var(--weline-page-canvas-color);
    background-image: var(--weline-page-canvas-image);
    background-size: var(--weline-page-canvas-size);
    background-repeat: var(--weline-page-canvas-repeat);
    background-position: var(--weline-page-canvas-position);
}
```

主题要换底色/纸纹/山水横幅，**只设这五个 token**（可在 `body.xxx-layout` 作用域内设）。
**禁止**在主题或 layout 的 `<style>` 里直接写 `body { background-image: … }`，也不要用 `!important` 去压骨架。

## 4. 风格选择不写进元素 class

- 视觉风格（配色/皮肤）由**风格文件**（`colors/_*.css`、`assets/css/theme.css` 之外的 skin 叶、`data-theme` 切换）决定；
- **禁止**用厂商名做修饰类（如 `--{vendor}`、`{vendor}-*-layout`）来表达风格；修饰类必须是语义化的（`--compact` / `--classic`）或干脆不出现，由风格文件命中；
- 新增风格 = 新增一个风格叶文件 + 一个 `data-theme` 值，不改模板 class。

## 5. 主题自定义的正确姿势

1. 只改 token（版心/gutter/画布/间距/字体）；
2. 需要结构差异 → **复制**骨架声明到主题自有文件里改（自持一份），不要在原骨架里加主题分支；
3. 禁止在主题里堆 `!important` 与骨架对抗；发现需要 `!important`，说明骨架缺 token，补 token。

## 6. 验收（缺一不可）

- 1440 / 1280 / 1024 / 768 / 375 五档，`header 内容左右边界 == 正文内容 == footer 内容`（像素相等）；
- 页面画布在 devtools 里 `background-image` 等于 token 解析值，且没有第二条 body 背景规则覆盖；
- `grep -r "calc(100% - " <theme css>` 不得出现在容器上。
