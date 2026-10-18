# 语义色重要程度矩阵

> 归属 `Weline_Theme`。色盘 → Foundation `--weline-theme-*` → `w-button` / `w-badge` / `w-alert` / `w-text` 统一消费。  
> 关联：[`theme-css-variables-only.md`](./theme-css-variables-only.md)、`REQ-THEME-0007`。  
> MCP 硬规则：`theme_base_components_token_only`（基础组件禁止私写色）。

## 默认可继承合同（硬）

任意主题开发**默认继承**下列语义叶子，不得另起平行色板：

1. **变量盘兜底**：`view/theme/{frontend|backend}/variables/_colors.css`（盘加载前可解析）。
2. **默认品牌盘**：`colors/_default.css`（完整 primary/secondary/status/neutral 全家）。
3. **前台加载/编译合并链**：`_default`（底盘合同）→ 品牌叠加（如 `_ink`）→ `_light`（亮色覆盖）→ `_dark`（暗色覆盖）。亮/暗找不到的叶子**只**继承 `_default`（一层）。
4. **后台加载/编译合并链**：`_default` → `_light` → `_dark`（同上：未覆盖即继承 default）。

品牌盘只允许覆盖品牌相关叶子（primary/accent/chrome/link 等）；**不得**删掉 secondary/status 强度矩阵。基础组件（`w-button`/`w-input`/`w-alert`/…）**禁止** `#hex`/`rgb()` 私写色，只消费 `--weline-theme-*` / `--color-*` / `--backend-color-*`。

## 角色 × 强度

| 角色 | 用途 |
|---|---|
| `neutral` | 信息层级（字 / 面 / 边） |
| `primary` | 主操作 / 品牌行动 |
| `secondary` | 次操作 |
| `success` / `warning` / `danger` / `info` | 结果与风险反馈 |

每个角色在色盘中应具备（命名以前台 `--color-*` / 后台 `--backend-color-*` 为准）：

| 强度 | Token 后缀 | 用途 |
|---|---|---|
| solid | （本体） | 实心按钮、强标签 |
| hover | `-hover` | 悬停加深 |
| active | `-active` | 按下 |
| surface | `-bg-subtle` → `--weline-theme-*-surface` | Alert / Badge / soft 按钮底 |
| border | `-border-subtle` | 描边 |
| emphasis | `-text-emphasis` | 状态强调字 |
| on | `on-*` | 实心底上的字色 |

中性额外三级：

- 字：`text` > `text-secondary` > `text-muted`/`text-subtle` > `disabled`；另 `inverse` / `on-dark`
- 面：`canvas` > `surface` > `surface-subtle`/`muted` > `hover`；另 `surface-disabled`
- 边：`border` / `border-subtle` < `border-strong` / `border-emphasis`；另 `border-focus`  
  **禁止**把 `#64748b` 当作默认边框。

## 桥接路径

1. 色盘：`view/theme/{frontend|backend}/colors/_default.css` + `_light.css` / `_dark.css`（及可选品牌盘）
2. Foundation：`view/ui/css/foundation.css`
   - `:root`：`--weline-theme-*` ← `var(--color-*)`
   - `[data-w-area="backend"]`：← `var(--backend-color-*)`
3. 组件：`data-tone="primary|secondary|neutral|quiet|success|warning|danger|info"`

改色盘后须能驱动按钮 / 徽章 / Alert；`php bin/w resource:compile welineUi` 同步编译产物。

## 按钮 / 徽章 / Alert tone 约定（硬 · `theme_semantic_severity_tone_required` + `theme_referenced_tokens_must_resolve`）

表达严重度、风险、结果或操作等级时，**必须**绑 `data-tone`（经 Foundation 上色）。  
**禁止**把「只用 Token / 禁私写色」误读成「全用组件默认 / quiet / neutral」。  
**禁止**只标 `data-tone`、主题 status/色盘叶子（含 `_dark`）缺失——属性有了颜色不生效 = 空壳，须补主题全局叶子，不得在组件私造色。

| 场景 | tone |
|---|---|
| 主保存 / 发布 | `primary` |
| 次操作 | `secondary` |
| 取消 / 返回 / 纯 chrome | `neutral` 或 `quiet` |
| 删除 / 破坏 | `danger` |
| 风险确认 | `warning` |
| 成功结果 | `success` |
| 说明提示 | `info`（常用 soft / Alert） |

`quiet`/`neutral` **仅**用于 chrome 级；严重度不同却全部默认 = 违规。

## 外观盘分组

Theme Editor「全局外观」分组规则（注释优先，前缀兜底）：

1. **注释组**：CSS 分段注释 `/* ========== 组名 ========== */` 或 `/* ========== 组名 #id ========== */`。
   - 源文为中文组名；可选 `#id` 作为稳定 Meta 键段（缺省为 `g_` + sha1(label) 前 10 位）。
   - 词典键：`@meta::theme.{area}.appearance.token_group.{group_id}.name`。
   - 扫盘时 `DictionaryEvents::register` 写入源文；启用 I18n AI 后由 Meta cron（`word_prefix => '@meta::'`）自动翻译。
   - API 返回已按当前语言解析的 `category_label`；外观盘标题直接展示该字段。
2. **前缀兜底**：无分段注释（或落在默认「其他」）时，去掉 `--`、剥叶子修饰，标题为技术名 `--font-size` / `--spacing` 等（不入 Meta）。

搜索过滤仍可用；分组顺序按变量首次出现顺序。
## 禁止

- 业务页再造 `--token-color-*` 按色值命名的叶子；新色必须落入上表语义名。
- 只写 `var(--new-token)` 却不在色盘登记叶子。
- 前后台各维护一套互不桥接的 status 字面量（`error` 仅允许作 `danger` 别名）。
- **基础组件**（`foundation.css` / Weline UI 2.0 基础类）私写颜色或平行色板；只允许改色盘叶子再经 `--weline-theme-*` 桥接。
