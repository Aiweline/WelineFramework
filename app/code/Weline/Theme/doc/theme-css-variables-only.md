# 主题 CSS/PHTML 变量强约束（禁止硬编码视觉字面量）

> **强约束 / 高压线**：默认主题与设计主题的源 CSS、以及 `.phtml` 内嵌 `<style>` / 行内 `style`，**禁止**直接写颜色、字号、间距、圆角、阴影等视觉字面量；必须使用主题变量色盘与间距/字体/边框/阴影 Token。  
> 权威约束归属 `Weline_Theme` 模块文档；由 MCP 按任务动态检索。  
> 关联需求：`REQ-THEME-0007`。全局硬约束参见 `app/code/Weline/Ai/doc/AI开发治理.md` §7。

## 目标

1. 视觉表现由主题盘（palette / semantic Token）统一控制，支持亮暗色、Scope 覆盖与 Theme Editor 外观盘。
2. 禁止页面/部件各自“私写” `#hex`、`rgb()`、`px/rem` 字面量导致无法换盘、暗色漏适配。
3. 新增与修改默认主题时，以 Token 为唯一视觉来源，避免回归硬编码。

## 唯一来源（Token 根）

前后台默认主题变量目录（示例，以仓库实际文件为准）：

- `app/code/Weline/Theme/view/theme/{frontend|backend}/variables/_colors.css`
- `.../variables/_spacing.css`
- `.../variables/_typography.css`
- `.../variables/_borders.css`
- `.../variables/_shadows.css`
- `.../colors/**`（色盘 / palette）

设计主题只允许**覆盖 Token 值**，不得用同路径业务 CSS 绕开语义层重新发明一套字面量。

## 提取必落盘（高压线）

把业务硬编码改成 `var(--…)` **不是终点**。同一次改动必须完成：

1. **选型**：优先复用已有语义 Token（`--color-*` / `--weline-theme-*` / spacing·radius·shadow 刻度）。
2. **落盘**：确需新 Token 时，**叶子默认值**写入对应盘文件：
   - 颜色 → `variables/_colors.css`，并在 `colors/_light.css` / `colors/_dark.css`（若有模式差异）给出可解析默认；
   - 间距/字号/边框/阴影 → 对应 `variables/_*.css`。
3. **面板归属**：颜色 Token 的 `@meta.panel` 必须是 `color`（或挂在 `_colors.css` / `colors/**`），**禁止**把颜色丢进 `_auto-literals.css`（spacing 杂项堆）导致 Theme Editor 色盘空白/未设置。
4. **禁止空壳**：业务侧不得只写 `var(--new-token)` 却不在盘内定义叶子；桥接层 `theme.css` 的 `var(--x, fallback)` 不能替代色盘登记。
5. **验收**：对新增 Token 做「引用 → 盘内定义 → 亮/暗可解析」三连检查；Theme Editor 外观盘应能看到非空默认。

6. **@media 断点例外**：`@media` 条件里**禁止** `var(--breakpoint-*)` / `var(--token-bp-*)`（浏览器会忽略整条规则，布局全乱）。断点须写 `768px` 等字面量；变量盘里的 `--breakpoint-md` 等只供 JS/文档，不进 `@media`。

7. **提取必须绑定组件（写回 + 接线）**：把 `35px` 提成 `--control-height-sm` 时须三步同批完成：
   - **叶子默认**：`variables/_spacing.css` 写 `--control-height-sm: 35px`（与提取前一致）；
   - **桥接**：`theme.css` 的 `--weline-theme-control-height-sm` → `var(--control-height-sm)`；
   - **组件消费**：`.w-button` / `.btn` / `input` 等用 `min-height: var(--weline-component-control-height-sm)`，禁止在组件 CSS 再留 `35px` 或空变量。
   Theme Editor 改 `--control-height-sm` 后，所有小号按钮/紧凑控件应自动跟随。

错误示例：提取成 `--token-color-e2e8f0` 只塞进 `_auto-literals.css`，色系盘与 Editor 均无项 → 页面/编辑器表现为「变量没设置」。

正确示例：映射到 `--color-bg-tertiary` / `--color-border-subtle`，或在 `_colors.css` + `_light.css` 新增语义名并给叶子值。

## 禁止

在源文件（非 `variables/`、非 `colors/` 色盘定义文件）中：

| 类别 | 禁止示例 |
|---|---|
| 颜色 | `#fff`、`#0d6efd`、`rgb()`/`rgba()`/`hsl()` 字面量；命名色 `red`/`white` |
| 尺寸 | `12px`、`1.5rem`、`10%` 用作字号/间距/宽高/圆角（布局百分比除外见下） |
| 阴影/边框观感 | 自写 `box-shadow:` / `border-radius:` 数字字面量 |
| 行内样式 | `style="color:#..."`、`style="font-size:14px"` |
| 假变量 | `var(--x, #fff)` / `var(--x, 16px)` 用字面量作 fallback（须收敛为纯 Token） |

## 允许

- `var(--token-name)` / `var(--w-*)` 等已登记 Token
- 在 `variables/**`、`colors/**` 内**定义** Token（此处可出现字面量，作为盘的叶子值）
- `0`、`none`、`transparent`、`currentColor`、`inherit`、`auto`、`100%`（仅流式布局宽高）、`1`/`0` 作为 `opacity`/`flex` 等非主题色盘语义
- 纯结构类名与 Taglib，不含视觉字面量

## 写法对照

```css
/* 禁止 */
.card { color: #333; padding: 16px; border-radius: 8px; background: #fff; }

/* 必须 */
.card {
  color: var(--w-color-text);
  padding: var(--w-space-4);
  border-radius: var(--w-radius-md);
  background: var(--w-color-surface);
}
```

```html
<!-- 禁止 -->
<div style="color:#c00;font-size:14px">...</div>

<!-- 必须：类名 + Token 驱动的 CSS -->
<div class="w-text-danger">...</div>
```

## 扫描与验收

- 范围：`app/code/Weline/Theme/view/theme/{frontend,backend}`（排除 `variables/**`、`colors/**`）
- 审计快照与优先改造清单：[`theme-hardcoded-visual-audit.md`](./theme-hardcoded-visual-audit.md)
- 新增/修改主题视觉后，应对照该清单收敛；Theme Editor 外观盘与编译产物须能覆盖同一 Token 集
- 本约束当前以文档 + 清单门禁为主；后续可升级为 Rules/CLI 扫描（不在本需求范围内强绑实现）

## 非目标

- 不要求本轮批量改完历史硬编码（见审计清单分批）
- 不修改 `generated/` 编译产物
- 不把业务模块私有皮肤当作 Theme 默认盘例外（业务模块同样应消费 Theme Token）
