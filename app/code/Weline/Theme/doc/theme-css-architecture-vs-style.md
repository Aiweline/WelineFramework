# theme.css：架构层 vs 风格层（硬分责）

> 硬规则 id：`theme_css_architecture_not_style_shell`  
> 席位指令：[主题开发.md](../../../../../dev/ai-command/ai/主题开发.md) Mode A  
> 互补：`theme_design_must_not_override_core_runtime_assets`（design **禁止覆盖** theme.css）≠ 本文（theme.css **禁止写风格壳**）

## 一句话

`view/theme/{area}/assets/css/theme.css` 是**主题架构 CSS**：定布局骨架、表面机制、全局组件与 Token 侧效应。  
**禁止**把 Amazon / 品牌皮肤 / 单页英雄区等**风格壳**写进 `theme.css`，也**禁止**塞进 **theme-head 主题合包**（`ThemeHeadChromeCssPack`）。

Token/变量：后台外观盘 → `theme:disk:compile` → **独立 disk link**（主题包之后）。  
风格叶（`*-amazon.css` 等）：进部件 `layout_source` / `source` bake，**不进** theme-head。

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
| 布局 partial 结构 CSS | 如 `partials/header-default.css`（结构/槽位，**不是** `*-amazon` 皮肤） |

## 不能写（风格 · FORBID）

| 类别 | 示例 | 正确落点 |
|------|------|----------|
| 风格壳类名 | `.amazon-*`、`.w-amz-*`、某品牌专属 BEM 皮肤块 | `view/statics/css/widgets/*-amazon.css` 等风格叶；**后台选型 → 编译/生成后发射** |
| 单页英雄/关于/FAQ/政策皮肤 | about/faq/policy hero 的 Amazon 视觉细节 | 风格叶或 design 独立 brand CSS（非 theme.css） |
| Chrome 皮肤 | header/footer Amazon chrome | `header-chrome-amazon.css` 等；部件 `@widget.layout_source` 或 Partials/design 壳 `SharedChromeService::emitPartialsHeaderShellLayoutCssHtml()`（layout + module-source → 大布局包）——**禁止裸 `<link>`**；**勿写入 theme-head 合包** |
| 购物者 toast（架构部件） | `.w-shopper-toast` / `.w-shopper-cart-added` | `storefront-shopper-toast.css`；由 `storefront-shopper-toast.js` ensure 加载；**勿写入 theme.css / PACK_STOREFRONT**；`*-amazon.css` 仅为兼容 `@import` shim |
| 站点/品牌开站货架视觉 | 某站专属色板字面量当「全局」 | 外观盘 + disk compile；或 design colors/variables + 独立 brand CSS（进 overlay，非硬嵌 amazon） |

## 分责速查

```text
theme.css                         → 架构（所有 design 继承的机制）
colors/_*.css + variables/_*.css  → Token 源叶子（进 theme-head）
ThemeData 外观盘 → disk:compile   → override CSS（含变量覆盖，进 theme-head）
statics/css/widgets/*-amazon.css  → 风格叶（部件 bake / 页面链路；禁止进 theme-head 布局合包）
design/.../assets/css/{brand}*.css→ design 品牌皮（overlay）
layout-critical / theme-head pack → 仅布局/架构/Token/disk；禁止塞风格叶
```

## 判定口诀

1. **删掉类名后机制还在吗？** 在 → 可能是架构；只剩 Amazon/品牌皮肤 → 风格。  
2. **是否所有 design 都必须继承？** 是 → 架构；只服务某一皮肤/某一页 → 风格。  
3. **是否依赖 `.amazon-*` / `.w-amz-*`？** 是 → 禁止进 `theme.css` / theme-head 布局合包。  
4. **加强合包 ≠ 拆前端风格**：本次计划只加强布局合包与 critical；不要去改 header 部件既有风格加载。

## 与 design 覆盖禁令的关系

- design **禁止**同 key 覆盖 `theme.css`（机制入口不能被皮肤顶空）。  
- Mode A 维护 theme.css 时，**同样禁止**把风格壳塞进这份架构文件。  
- 品牌化：外观盘 / colors / variables / disk compile；design 独立 brand CSS 走 overlay 列表，**不要**在主题模板硬 `@static` 风格叶。

## 验收

- 契约：`StorefrontShopperToastContractTest`（toast 不在 theme.css / 不在 PACK）、`ThemeHeadChromeCssPackContractTest`（合包无风格叶）、`ThemeSurfaceTextRolesContractTest`（inverse 机制仍在 theme.css）。  
- 目视：`rg -n '\\.amazon-|\\.w-amz-' view/theme/*/assets/css/theme.css` → 仅允许注释说明，禁止选择器规则。  
- 合包扫描：`ThemeHeadChromeCssPack` / layout-critical 源中不得出现 `*-amazon.css`。
