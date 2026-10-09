# 默认主题硬编码视觉审计清单

> 扫描日期：2026-08-24（批量 Token 化后复扫）  
> 范围：`app/code/Weline/Theme/view/theme/{frontend,backend}`（排除 `variables/**`、`colors/**`；忽略 CSS 注释与 `url(data:...)`）  
> 配套强约束：[`theme-css-variables-only.md`](./theme-css-variables-only.md)（`REQ-THEME-0007`）  
> 机器快照：`theme-hardcoded-visual-audit-20260824.json`

## 汇总（改造后）

| 类别 | 命中 |
|---|---:|
| `hard_color`（业务 CSS/PHTML 字面颜色） | **0** |
| `hard_size`（业务 CSS/PHTML 字面尺寸） | **0** |
| `var_fallback`（`var(--t, 字面量)`） | **0** |

改造前基线约：`hard_color` 511、`hard_size` 1415、`var_fallback_color` 303、涉文件 96。

## 本轮做法

1. 扩展 `variables/_colors.css`、`_spacing.css`、`_borders.css`、`_typography.css` 语义刻度与别名。  
2. 去掉业务侧 `var(--token, 字面量)` / 嵌套字面 fallback。  
3. 常见颜色/间距/圆角/断点映射到语义 Token（`--color-*`、`--spacing-*`、`--weline-*` 等）。  
4. 剩余一次性字面量收编到 `variables/_auto-literals.css`（叶子值只允许出现在 variables；业务文件只写 `var(--token-*)`）。

## 验收命令（本地）

在排除 `variables/**`、`colors/**` 的前提下，对 `view/theme/{frontend,backend}` 的 `.css` / `.phtml` 内 `<style>` 与 `style=""` 扫描：不应再出现裸 `#hex` / `rgb()` / `Npx` / `Nrem`（注释与 data-URL 除外）。

## 后续建议

- Theme Editor / 盘编辑优先暴露语义 Token；`_auto-literals.css` 中的 `--token-*` 可逐步合并回语义名。  
- 新增主题样式时直接写 `var(...)`，禁止再引入字面量。  

## 部件扩展（2026-10-09）

> 范围：`app/code/**/widgets/**` 的 `.css` / `.phtml`（含 `statics/css/widgets`；排除 test/doc/generated）  
> 工具：`Theme/bin/widget-theme-token-audit.py` · 契约 `test/Unit/Css/WidgetThemeTokenHardcodeContractTest.php`

| 类别 | 命中 |
|---|---:|
| `hard_color_*` / `hard_size` / `var_fallback_*` / 真 `inline_style_literal` | **0** |

改造前基线约：`bad_files` 101、`total_hits` ~4666（见会话 `dev/tmp/widget-theme-token-audit.json`，勿提交）。  
Amazon 风格壳仍落在 `statics/css/widgets/*-amazon.css`（符合 `theme_css_architecture_not_style_shell`）；业务侧只消费语义 Token，叶子在 `variables/_colors.css` / `_auto-literals.css` / `colors/**`。
