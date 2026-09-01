# 语义色重要程度矩阵

> 归属 `Weline_Theme`。色盘 → Foundation `--weline-theme-*` → `w-button` / `w-badge` / `w-alert` / `w-text` 统一消费。  
> 关联：[`theme-css-variables-only.md`](./theme-css-variables-only.md)、`REQ-THEME-0007`。

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
| surface | `-bg-subtle` → `--weline-theme-*-surface` | Alert / Badge / soft 按钮底 |
| border | `-border-subtle` | 描边 |
| emphasis | `-text-emphasis` | 状态强调字 |
| on | `on-*` | 实心底上的字色 |

中性额外三级：

- 字：`text` > `text-muted` > `text-subtle` > `disabled`
- 面：`canvas` > `surface` > `surface-muted` > `hover`
- 边：`border`（默认浅分隔，如 `#e2e8f0`）< `border-strong` / `border-emphasis`  
  **禁止**把 `#64748b` 当作默认边框。

## 桥接路径

1. 色盘：`view/theme/{frontend|backend}/colors/_light.css` / `_dark.css`
2. Foundation：`view/ui/css/foundation.css`
   - `:root`：`--weline-theme-*` ← `var(--color-*)`
   - `[data-w-area="backend"]`：← `var(--backend-color-*)`
3. 组件：`data-tone="primary|secondary|neutral|quiet|success|warning|danger|info"`

改色盘后须能驱动按钮 / 徽章 / Alert；`php bin/w resource:compile welineUi` 同步编译产物。

## 按钮 tone 约定

| 场景 | tone |
|---|---|
| 主保存 / 发布 | `primary` |
| 次操作 | `secondary` |
| 取消 / 返回 | `neutral` 或 `quiet` |
| 删除 / 破坏 | `danger` |
| 风险确认 | `warning` |
| 成功结果 | `success` |
| 说明提示 | `info`（常用 soft / Alert） |

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
