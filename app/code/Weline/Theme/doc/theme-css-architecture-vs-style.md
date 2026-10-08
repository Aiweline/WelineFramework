# theme.css：架构层 vs 风格层（硬分责）

> 硬规则 id：`theme_css_architecture_not_style_shell`  
> 席位指令：[主题开发.md](../../../../../dev/ai-command/ai/主题开发.md) Mode A  
> 互补：`theme_design_must_not_override_core_runtime_assets`（design **禁止覆盖** theme.css）≠ 本文（theme.css **禁止写风格壳**）

## 一句话

`view/theme/{area}/assets/css/theme.css` 是**主题架构 CSS**：定布局骨架、表面机制、全局组件与 Token 侧效应。  
**禁止**把 Amazon / 品牌皮肤 / 单页英雄区等**风格壳**写进 theme.css——风格有自己的 CSS（或布局局部 `<style>`）。

## 能写（架构 · ALLOW）

| 类别 | 示例 |
|------|------|
| 版心 / 布局架构 | `.w-container`、内容宽、区域分区、全局栅格与结构工具类 |
| 表面机制 | `[data-surface=inverse]` / `.w-surface-inverse` 及 nested raised 纸面恢复（WO-UI-CONTRAST-INVERSE） |
| 全局组件契约 | 与 Weline UI / Taglib 全局控件相关的 **token 桥接与侧效应**（语言/货币触发器、search chip 在 inverse 下的纸墨恢复等） |
| Token 桥接 | `--weline-theme-*` ↔ `--color-*` / `--w-surface-*` 等跨层别名（不含品牌皮肤字面量堆砌） |
| 无障碍 / 运动 | `prefers-reduced-motion`、基础 `color-scheme` 等架构级护栏 |

## 不能写（风格 · FORBID）

| 类别 | 示例 | 正确落点 |
|------|------|----------|
| 风格壳类名 | `.amazon-*`、`.w-amz-*`、某品牌专属 BEM 皮肤块 | `view/statics/css/widgets/*-amazon.css`、design `assets/css/{brand}*.css`、布局 `<style>` |
| 单页英雄/关于/FAQ/政策皮肤排版 | about/faq/policy hero 的 Amazon 视觉细节 | 对应 layout / design 覆盖 / 独立风格 CSS |
| Chrome 皮肤 | header/footer Amazon chrome 装饰 | `header-chrome-amazon.css` / `footer-chrome-amazon.css` 等 |
| 购物者 toast 皮肤 | `.w-amz-shopper-toast` 等 | `storefront-shopper-toast-amazon.css`（head `@static`） |
| 站点/品牌开站货架视觉 | 某站专属色板字面量当「全局」 | design colors/variables + 独立 brand CSS |

## 分责速查

```text
theme.css          → 架构（所有 design 继承的机制）
colors/_*.css
variables/_*.css   → Token 叶子（语义色 / 度量）
statics/css/widgets/*-amazon.css
design/.../assets/css/{brand}*.css
layout <style>     → 风格壳 / 品牌皮 / 单页皮肤
```

## 判定口诀

1. **删掉类名后机制还在吗？** 在 → 可能是架构；只剩 Amazon/品牌皮肤 → 风格，赶出 theme.css。  
2. **是否所有 design 都必须继承？** 是 → 架构；只服务某一皮肤/某一页 → 风格。  
3. **是否依赖 `.amazon-*` / `.w-amz-*` 选择器？** 是 → 禁止进 theme.css。

## 与 design 覆盖禁令的关系

- design **禁止**同 key 覆盖 `theme.css`（机制入口不能被皮肤顶空）。  
- Mode A 维护 theme.css 时，**同样禁止**把风格壳塞进这份架构文件。  
- 品牌化走 colors / variables / 独立风格 CSS + `<theme:css>` / `@static`。

## 验收

- 契约：`StorefrontShopperToastContractTest`（toast 不在 theme.css）、`ThemeSurfaceTextRolesContractTest`（inverse 机制仍在 theme.css）。  
- 目视：`rg -n '\\.amazon-|\\.w-amz-' view/theme/*/assets/css/theme.css` → 仅允许注释说明，禁止选择器规则。
