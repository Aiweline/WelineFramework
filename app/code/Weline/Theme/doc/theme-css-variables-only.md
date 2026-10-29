# 主题 CSS/PHTML 变量强约束（变量单一作者 · 外部只许引用）

> **强约束 / 高压线（2026-10-29 用户口径升级）**：**所有视觉变量（颜色、字号、间距、圆角、阴影、字重、控件高、断点外的刻度…）只有一个作者——主题变量盘**。默认主题与设计主题的源 CSS、`.phtml` 内嵌 `<style>` / 行内 `style`、部件参数、布局/部件/partial 模板，**一律禁止写任何硬编码视觉字面量，也禁止自建平行变量**；只能 `var(--已登记Token)` 引用已登记的主题变量。  
> **兜底例外（本条 supersede 旧「禁止 fallback 字面量」条款）**：允许 `var(--token, 默认值)` ——**默认值只在「该变量不存在」时生效**，这是唯一允许在外部出现字面量的形态；但兜底**不得**变成「模板自己定义该变量的实际色/值」：兜底值必须与主题盘叶子同义，且**禁止**靠兜底实现主题未声明的视觉。  
> **变量可以加，但必须加在主题变量盘里再被引用**：需要新变量/新颜色/新刻度 → 在 `variables/_*.css` / `colors/_*.css`（或 design 主题对应盘）**声明叶子**，再由外部 `var()` 消费；**禁止**在模板/组件里直接写字面量或私建 `--my-*` 平行变量绕过主题盘。  
> 权威约束归属 `Weline_Theme` 模块文档；由 MCP 按任务动态检索。  
> 关联需求：`REQ-THEME-0007`。MCP 硬规则：`theme_variables_single_author_no_hardcode`。  
> 全局硬约束参见 `app/code/Weline/Ai/doc/AI开发治理.md` §7。

## 目标

1. 视觉表现由主题盘（palette / semantic Token）**单一作者**统一控制，支持亮暗色、Scope 覆盖与 Theme Editor 外观盘。
2. 禁止页面/部件各自“私写” `#hex`、`rgb()`、`px/rem` 字面量，也禁止各自私建平行变量，导致无法换盘、暗色漏适配。
3. 新增与修改默认主题时，以 Token 为唯一视觉来源，避免回归硬编码。

## 变量单一作者契约（硬 · `theme_variables_single_author_no_hardcode`）

**判定谓词**：`Hardcode(外部) ∨ PrivateVar(外部) → ⊥`；`NeedVar → □(DeclareInThemePlate ∧ ConsumeViaVar)`。

| 层 | 谁能写 | 写什么 |
|---|---|---|
| **主题变量盘（唯一作者）** | 主题开发 / design 主题 | 叶子的**默认值字面量**：颜色、字号、间距、圆角、阴影、字重、控件高、z-index 等全部视觉变量 |
| **Foundation 桥接**（`foundation.css`） | 主题层 | `--weline-theme-* ← var(--color-*)` 只做映射，不新造值 |
| **组件 / 布局 / 部件 / partial / 模板 / 内嵌 `<style>` / 行内 `style`** | 任何业务方 | **只准** `var(--已登记Token)`；允许 `var(--token, 默认值)` 兜底；**不准**任何字面色值、也不准私建平行变量 |

**兜底（默认值）细则**：`var(--token, 默认值)` 中默认值语义 = 「变量不存在时才用」→ 允许；但 ① 默认值必须与主题盘叶子同义（不与盘冲突）；② 不得用兜底承载主题尚未声明的视觉（那等于模板私造色，须回盘加叶子）；③ 一个 Token 若全站都需要，禁止靠各模板兜底各写一份。

**加变量的正确姿势**：需要新色/新刻度 → ① 先在 `variables/_colors.css`（或 `_spacing.css` / `_borders.css` / `_typography.css` / `_shadows.css`）声明叶子；② 颜色的 `@meta.panel` 归属 `color`；③ 亮/暗/品牌盘按需给差异；④ 外部再 `var()` 引用。

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

1. **选型**：优先复用已有语义 Token（`--color-*` / `--weline-theme-*` / spacing·radius·shadow 刻度）。**基础组件**（`w-button` / `w-input` / `w-alert` 等）**禁止**私写 `#hex`/`rgb()`，必须走主题规范变量（MCP `theme_base_components_token_only`；见 `theme-semantic-color-matrix.md`）。
2. **落盘**：确需新 Token 时，**叶子默认值**写入对应盘文件：
   - 颜色 → `variables/_colors.css`，并在 `colors/_default.css`（默认可继承合同）与 `colors/_light.css` / `colors/_dark.css`（若有模式差异）给出可解析默认；
   - 间距/字号/边框/阴影 → 对应 `variables/_*.css`。
3. **面板归属**：颜色 Token 的 `@meta.panel` 必须是 `color`（或挂在 `_colors.css` / `colors/**`），**禁止**把颜色丢进 `_auto-literals.css`（spacing 杂项堆）导致 Theme Editor 色盘空白/未设置。
4. **禁止空壳（硬 · MCP `theme_referenced_tokens_must_resolve`）**：业务/基础组件侧不得只写 `var(--new-token)` / 标 `data-tone` 却不在盘内定义叶子；桥接层 `theme.css` 的 `var(--x, fallback)` 不能替代色盘登记。暗色模式属性有了但颜色不生效 = 叶子缺失，须补 `_dark`（或模式盘）而非组件私造色。
5. **继承（default 为准 · 一层）**：前台 `_default` → 品牌盘（如 `_ink`）→ `_light` → `_dark`；后台 `_default` → `_light` → `_dark`。模式盘（light/dark）只写差异；未覆盖叶子继承 `_default`。chrome pack 编译合并同序。品牌盘只叠加品牌叶子，不拆除 secondary/status 全家。模式盘禁止用裸 `:root` 污染另一模式。
6. **验收**：对新增 Token 做「引用 → 盘内定义 → 亮/暗可解析」三连检查；Theme Editor 外观盘应能看到非空默认。审图见按钮色/字重/描边未按主题生效 → 补主题全局叶子到 pass。

### 零漂移硬目标（HARD · 2026-10-29 用户指定「对齐所有自定义主题，不允许漂移」）

**同一 Token 名在两处盘里必须同值。同键不同值 = 漂移缺陷，交付前必须归零。**

| 对比对 | 同口径 | 允许漂移数 |
|---|---|---|
| `{area}/variables/*.css` vs `{area}/colors/*.css` | 同 key，按**末次声明胜出**（同一 `:root` 块内后写覆盖先写） | **0**（前后台核心盘 + 全部 design 主题盘） |

**方向（HARD）**：以 `colors/_default.css`（默认色合同）为基准单向对齐兜底盘，**不是**双向折中。默认盘改了若影响到兜底就向外辐射改兜底；**自定义主题自己定义的变量不干扰**（design 主题只查自己盘内同名漂移，不改其取值）。

**判定口径（HARD）**：必须按**末次声明**统计，不是首次——同一 `:root` 块里重复声明同一个 key 时，后者胜出，按首次会把「实际生效值」算错。

**审计命令**（机器可判定，输出漂移数须为 `0`）：

```bash
# 前/后台核心盘（把 variables/_colors.css 与 colors/_default.css 的同 key 不同值列出来）
php -r 'function L($p){$t=file_get_contents($p);$t=preg_replace("~/\\*.*?\\*/~s","",$t);$o=[];preg_match_all("/(--[A-Za-z0-9_-]+)\\s*:\\s*([^;]+);/",$t,$m,PREG_SET_ORDER);foreach($m as $x){$o[$x[1]]=trim($x[2]);}return $o;}foreach([["frontend","app/code/Weline/Theme/view/theme/frontend"],["backend","app/code/Weline/Theme/view/theme/backend"]] as $a){[$n,$b]=$a;$v=L("$b/variables/_colors.css");$d=L("$b/colors/_default.css");$k=array_intersect_key($v,$d);$dr=[];foreach($k as $t=>$val){if($val!==$d[$t])$dr[]="$t $val -> ".$d[$t];}echo "$n 同key=".count($k)." 漂移=".count($dr).PHP_EOL;foreach($dr as $s)echo "  $s".PHP_EOL;}'
```

**收口标准**：上述命令的**唯一可接受输出**是——`backend 同key=153 漂移=0`，以及 `frontend` 行为 `漂移=1` 且那 1 条恰为 `--color-border-default var(--color-border-soft, …) -> #e5e7eb`（`DefaultFrontendLegacyTokenBridgeTest` 逐字锁定的兼容别名链，字面量形态不可改、解析值已与默认盘同值）。除这 1 条之外出现任何漂移行 = 未收口。

实测样本（2026-10-29 对齐后）：

```
frontend 同key=130 漂移=1
  --color-border-default var(--color-border-soft, var(--color-border-light, #d2d2d7)) -> #e5e7eb
backend 同key=153 漂移=0
```

7. **@media 断点例外**：`@media` 条件里**禁止** `var(--breakpoint-*)` / `var(--token-bp-*)`（浏览器会忽略整条规则，布局全乱）。断点须写 `768px` 等字面量；变量盘里的 `--breakpoint-md` 等只供 JS/文档，不进 `@media`。

8. **WO-UI-CONTRAST-INVERSE 纸面钉例外（硬 · MCP `theme_inverse_raised_paper_ink_pin`）**：暗色 / inverse chrome 会把 `--color-text*` 重绑成 on-dark 浅色。嵌在其下的**浅色 raised 面板**必须在面板根上**先钉** foundation 纸面字面（`#0f1111` / `#565959`，次级可 `#666666`）到 `--weline-chrome-body-text*`，再绑 `--amz-drawer-text` / `--_paper-text`。**禁止**用 `var(--color-text-primary)` 等已被 inverse 染浅的 Token 当纸面种子。**Token 化 / 去硬编码扫除不得剥掉这些钉**——它们是对比度架构机制，不是「私造色板」。例子（仅举例）：迷你车抽屉、顶栏搜索纸面、配送地址弹层、账户下拉。

8b. **Token 化双层清单（硬 · MCP `theme_frontend_must_obey_tokens_and_surface_roles`）**：Token 化 = 把普通视觉字面量收成 CSS 变量，**不等于**「所有字面量都该死」。动手前必须：① 挂主题技能并读本文件例外；② 字面量分类（普通→Token；纸面钉/`@media` 断点→保留）；③ 禁止无表面上下文的「`#hex`→`--color-text-primary`」启发式；④ 契约 + inverse 下 Browser 对比度；⑤ 部件 CSS bake/`theme:upgrade`。缺任一层 = 未按主题规范开发。

9. **内容区宽度（layout content width）**：与 `theme-layout-content-width.md` 配套——宽度/gutter **无局部例外**；已包 `.w-container` 的业务页 `padding-inline: 0`；未包容器页用 `--weline-layout-content-max-width` + `--weline-layout-content-padding-inline`，禁止 `1440px` 等 fallback。颜色/特质 Hero 可模块内 scope 自定义。

10. **提取必须绑定组件（写回 + 接线）**：把 `35px` 提成 `--control-height-sm` 时须三步同批完成：
   - **叶子默认**：`variables/_spacing.css` 写 `--control-height-sm: 35px`（与提取前一致）；
   - **桥接**：`theme.css` 的 `--weline-theme-control-height-sm` → `var(--control-height-sm)`；
   - **组件消费**：`.w-button` / `.btn` / `input` 等用 `min-height: var(--weline-component-control-height-sm)`，禁止在组件 CSS 再留 `35px` 或空变量。
   Theme Editor 改 `--control-height-sm` 后，所有小号按钮/紧凑控件应自动跟随。

错误示例：提取成 `--token-color-e2e8f0` 只塞进 `_auto-literals.css`，色系盘与 Editor 均无项 → 页面/编辑器表现为「变量没设置」。

正确示例：映射到 `--color-bg-tertiary` / `--color-border-subtle`，或在 `_colors.css` + `_light.css` 新增语义名并给叶子值。

语义角色 × 强度矩阵、Foundation 桥接与 `data-tone` 约定见 [`theme-semantic-color-matrix.md`](./theme-semantic-color-matrix.md)。  
硬规则：`theme_referenced_tokens_must_resolve`——组件只能消费主题变量，且引用必须在主题盘有可解析叶子（含暗色）；`theme_semantic_severity_tone_required`——风险/结果/操作须绑 `data-tone`（仍依赖上条叶子上色）。  
「禁组件私造色」与「必须补主题全局色/粗细变量」**不矛盾**：私色禁在组件，统一配在主题盘。
店面间距/圆角/字号**消费约定**见 [`theme-storefront-token-consumption.md`](./theme-storefront-token-consumption.md)。

## 禁止

在源文件（非 `variables/`、非 `colors/` 色盘定义文件）中：

| 类别 | 禁止示例 |
|---|---|
| 颜色 | `#fff`、`#0d6efd`、`rgb()`/`rgba()`/`hsl()` 字面量；命名色 `red`/`white` |
| 尺寸 | `12px`、`1.5rem`、`10%` 用作字号/间距/宽高/圆角（布局百分比除外见下） |
| 阴影/边框观感 | 自写 `box-shadow:` / `border-radius:` 数字字面量 |
| 行内样式 | `style="color:#..."`、`style="font-size:14px"` |
| **平行变量（硬 · `theme_variables_single_author_no_hardcode`）** | 外部私建 `--my-btn-bg` / `--brand-color` / `--token-color-e2e8f0` 等**未在主题盘声明**的变量，再拿字面量喂它——等于绕过主题盘私造视觉 |
| **兜底越界** | 把 `var(--token, 默认值)` 的默认值当成实际视觉来源：默认值与盘叶子不同义、或靠兜底实现主题盘未声明的色/刻度（须回盘加叶子，见「兜底细则」） |
| ~~假变量~~（**已 supersede · 2026-10-29 用户口径**） | ~~`var(--x, #fff)` / `var(--x, 16px)` 用字面量作 fallback（须收敛为纯 Token）~~ → 现已**允许**兜底；仅禁止上两行所述越界形态 |
| **`!important`（默认层硬 · `theme_default_forbids_css_important`）** | 默认主题（`app/code/Weline/Theme/view/theme/**` 与默认层静态 CSS）视觉/布局/呈现声明写 `!important`「硬抢」（**含** `var(--token) !important`）——会逼自定义主题跟着打战争 |

### 默认层禁 `!important` · design 只继承改（流程）

1. **默认层（Mode A）**：样式须靠选择器特异性、加载顺序、Token 叶子**原生生效**；**禁止**新增业务/视觉 `!important`（含 `var() !important`）。历史债务勿扩大。
2. **自定义主题（Mode B）**：只在 `app/design/{Vendor}/{theme}/` 用品牌盘 / 独立 CSS / 同 key 模板覆盖去改外观；**禁止**直接改上游 `app/code` 主题源。
3. **做不到则停**：缺覆盖钩子或被默认层压死 → **停手询问用户**（框架缺口）；禁止静默改上游解围。

**UA / 无障碍窄例外（仅此两类可保留 `!important`）**：

| 允许 | 禁止当作例外 |
|------|----------------|
| `[hidden]` → `display: none !important`（压过显示声明） | 任意视觉色/间距/宽度/定位用 `!important` |
| `@media (prefers-reduced-motion: reduce)` 内缩短 `transition`/`animation`/`scroll-behavior` | `var(--token) !important`「逼人只改变量」 |

权威流程：[主题开发.md「默认层级联与 design 继承改法」](../../../../../dev/ai-command/ai/主题开发.md)；互补硬规则 `theme_design_must_inherit_not_mutate_source_widgets`。

## 允许

- `var(--token-name)` / `var(--w-*)` 等已登记 Token
- **`var(--token, 默认值)` 兜底**（`var(--x, #fff)` / `var(--x, 16px)`）：默认值**只在变量不存在时生效**；须与主题盘叶子同义，且不得成为该视觉的实际来源（详见「兜底细则」）
- 在 `variables/**`、`colors/**` 内**定义** Token（此处可出现字面量，作为盘的叶子值）——**这是全仓唯一允许写字面视觉值的地方**
- 在 `variables/**` / `colors/**` 内**新增**变量（任何视觉类别均可加），加完由外部 `var()` 消费
- `0`、`none`、`transparent`、`currentColor`、`inherit`、`auto`、`100%`（仅流式布局宽高）、`1`/`0` 作为 `opacity`/`flex` 等非主题色盘语义
- **inverse 下 raised 纸面钉**（见上「WO-UI-CONTRAST-INVERSE 纸面钉例外」）：面板根上的 `#0f1111` / `#565959` / `#666666` 用于钉 `--weline-chrome-body-text*`——**不是**违规硬编码
- `@media` 断点字面量（见上「@media 断点例外」）
- **UA 窄例外**的 `!important`（见上表）
- 纯结构类名与 Taglib，不含视觉字面量

## 写法对照

```css
/* 禁止：外部写字面色值 */
.card { color: #333; padding: 16px; border-radius: 8px; background: #fff; }

/* 禁止：外部私建平行变量再喂字面量 */
.card { --card-bg: #fff; background: var(--card-bg); }

/* 必须：只引用主题盘变量 */
.card {
  color: var(--w-color-text);
  padding: var(--w-space-4);
  border-radius: var(--w-radius-md);
  background: var(--w-color-surface);
}

/* 允许：兜底仅在变量不存在时生效 */
.card { background: var(--w-color-surface, #fff); }
```

```html
<!-- 禁止 -->
<div style="color:#c00;font-size:14px">...</div>

<!-- 必须：类名 + Token 驱动的 CSS -->
<div class="w-text-danger">...</div>
```

```text
/* 需要新颜色/新刻度时，正确姿势是回盘加叶子，不是模板里造 */
1. app/code/Weline/Theme/view/theme/frontend/variables/_colors.css
     --color-brand-accent: #xxxxxx;   /* @meta.panel = color */
   或 colors/_default.css / _light.css / _dark.css （亮暗差异）
2. 外部：color: var(--color-brand-accent);
```

## 扫描与验收

**扫描面（覆盖到模板层，不只 CSS）**：

| 通道 | 范围 | 判定 |
|---|---|---|
| CSS 字面量 | `view/theme/{frontend,backend}/**/*.css`（排除 `variables/**`、`colors/**`） | 出现 `#hex` / `rgb()` / 裸刻度 → 违规 |
| 模板内嵌样式 | 布局 / 部件 / partial 的 `.phtml` 内 `<style>` 与行内 `style` | 同上；含 `var(--x, 字面) **私义**` → 违规 |
| 部件参数 | `@param … type="color"` 的**自由 hex 默认值** | 违规；应改语义 `select` + 语义选项（详见 `部件开发指南.md`） |
| 运行期注入 | 模板 `data-widget-style` / 内联 CSS 属性注入 `color` / `background-color` 字面 | 违规；应改 `data-tone` 或语义 class |
| 平行变量 | 外部 `--my-*` / `--brand-color` 等不在主题盘声明的变量 | 违规 |

- 审计快照与优先改造清单：[`theme-hardcoded-visual-audit.md`](./theme-hardcoded-visual-audit.md)
- 新增/修改主题视觉后，应对照该清单收敛；Theme Editor 外观盘与编译产物须能覆盖同一 Token 集
- 本约束以文档 + 清单 + 契约测试门禁为主；模板层扫描（部件参数 / 运行期注入）为 2026-10-29 新增扫描面，存量分批收敛

## 非目标

- 不要求本轮批量改完历史硬编码（见审计清单分批）
- 不修改 `generated/` 编译产物
- 不把业务模块私有皮肤当作 Theme 默认盘例外（业务模块同样应消费 Theme Token）
