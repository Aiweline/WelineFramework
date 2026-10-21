# Weline UI 设计系统：组件、Token 与标签渲染规范（Apple 风格）

> 状态：**现行**（2026-10-21 起）
> 适用：前台默认主题 + 后台默认主题 + 所有模块页面/组件（`app/code/**/view/**`）
> 权威优先级：MCP `hard_constraints` > `AI硬规则索引.md` > 本文 > 旧组件文档
> 视觉判断：`../设计品味.md`；后台页面骨架：`../backend/后端主题开发标准.md`

---

## 0. 一句话

**一套 Token、一套组件语义、一套 Apple 风格尺度**；页面只组合组件，不发明样式。
所有颜色必须可回溯到色盘变量（`--color-*` / `--backend-color-*`），组件层只消费语义 Token。

---

## 1. 分层与目录（唯一结构树）

```
app/code/Weline/Theme/view/ui/css/
├── foundation.css              # 基础层：reset/base/layout/utility + 既有原语（历史来源）
├── tokens/
│   └── apple.css               # 语义 Token：颜色映射、圆角、阴影、字阶、控件高度、动效
├── components/                 # 组件层（新组件只加在这里；按组件一文件）
│   ├── button.css
│   ├── field.css
│   ├── selection.css           # switch / checkbox / radio
│   ├── navigation.css          # tabs / pagination / breadcrumb / menu
│   ├── surface.css             # card / table / dialog / drawer / empty / alert 盒
│   └── feedback.css            # badge / alert 语义 / loading / skeleton
├── backend.css                 # 后台壳层与后台密度（只做作用域覆盖）
├── frontend.css                # 前台壳层（只做作用域覆盖）
└── pages/                      # 页面私有样式（编辑器等特殊页）
```

构建：`app/code/Weline/Theme/etc/weline-ui-assets.json` 的 `foundation` bundle 按
`foundation.css → tokens/apple.css → components/*.css` 顺序拼接，编译产物
`view/statics/ui/weline-foundation.css`。**改源必须** `php bin/w resource:compile welineUi`。

后台/前台共用同一份 foundation bundle；差异只允许在 `backend.css` / `frontend.css`
用 `[data-w-area="backend"]` / 前台作用域覆盖**密度**，不允许另起一套组件外观。

---

## 2. Token 契约（三层，单向依赖）

```
色盘/主题变量层   --color-*（前台） / --backend-color-*（后台）
        ↓ 只能向下引用
语义 Token 层     --weline-theme-*（颜色）、--weline-radius-*、--weline-theme-shadow-*、
                  --weline-font-*、--weline-control-height*
        ↓ 只能向下引用
组件规则层        .w-button / .w-input / .w-switch / .w-card …
```

### 2.1 颜色（色盘可调）

| 语义 Token | 前台来源 | 后台来源 | 用途 |
|---|---|---|---|
| `--weline-theme-canvas` | `--color-bg-canvas` | 后台壳层 | 页面底色 |
| `--weline-theme-surface` | `--color-bg-primary` | `--backend-color-card-bg` | 卡片/输入/弹层底 |
| `--weline-theme-surface-muted` | `--color-bg-subtle` | `--backend-color-bg-secondary` | 次级底、表头、空态 |
| `--weline-theme-text` | `--color-text-primary` | `--backend-color-text-primary` | 正文 |
| `--weline-theme-text-muted` | `--color-text-secondary` | `--backend-color-text-secondary` | 辅助文字 |
| `--weline-theme-border` | `--color-border-default` | `--backend-color-border-default` | 描边 |
| `--weline-theme-primary` | `--color-primary` | `--backend-color-primary` | 主操作/开关/焦点 |
| `--weline-theme-success/warning/danger/info` | `--color-success…` | `--backend-color-success…` | 语义状态 |

规则：

1. 组件 CSS **禁止**写 `#hex`、`rgb()`、`hsl()`、命名色；只允许 `var(--weline-*)`。
2. 新增颜色：先在 `view/theme/frontend/colors/_default.css`（及 `_light/_dark`）或后台
   `view/theme/backend/colors/_default.css` 定义 `--color-*` / `--backend-color-*`，
   再在 `tokens/apple.css` 加映射，最后组件引用语义 Token。**不得跳过映射直接写值。**
3. 色盘编辑：色盘只写 `--color-*` / `--backend-color-*`；由于组件只消费语义 Token，
   改色盘即全站生效（含暗色，见 `colors/_dark.css`）。
4. 透明叠加用 `color-mix(in srgb, var(--weline-theme-*) N%, transparent)`，不写 rgba 常量。

### 2.2 尺度（统一高度与圆角）

| Token | 值 | 用途 |
|---|---|---|
| `--weline-control-height-sm` | 2rem / 32px | 小按钮、表格内控件、开关行 |
| `--weline-control-height` | 2.5rem / 40px | 默认按钮、输入、选择器 |
| `--weline-control-height-lg` | 3rem / 48px | 主 CTA、登录/空态主操作 |
| `--weline-control-padding-inline` | 0.875rem | 控件横向内边距 |
| `--weline-radius-xs/sm/md/lg/xl` | 6/8/12/18/22px | 芯片 / 小控件 / 按钮输入卡片 / 面板 / 弹层 |
| `--weline-space-1…12` | 4px 基线 | 所有间距 |
| `--weline-font-size-xs…2xl` | 12/14/16/18/22/26px | 字阶（≥3 级） |
| `--weline-theme-shadow-xs/sm/md/lg/dialog` | 分层柔和阴影 | 卡片/浮层/弹窗 |
| `--weline-motion-fast/normal` + `--weline-motion-ease` | 140/220ms | 交互过渡 |

规则：控件高度只允许取三档；同一行内按钮/输入/选择器高度必须一致；
间距不得写裸 `px`（用 `--weline-space-*`）；圆角不得写裸值。

### 2.3 组件 CSS 与关键路径（Layout-First）

详细契约见 [`CSS关键路径契约.md`](./CSS关键路径契约.md)。要点：

1. 组件各自成 bundle，并按 `foundation → tokens → components` 顺序进入**单一阻塞 Theme Pack**；
   布局/骨架 CSS 必须首屏立即阻塞生效，禁止改成 preload/延迟。
2. `resource:compile welineUi` 扫描模板 `class` 使用，写 `usage.json`（区域清单 + 字节），
   用于后续按部件拆分与预算审计。
3. 组件 CSS 必须**自足**（不依赖 foundation 同名基础规则），便于未来按需拆分。
4. 显式 `.w-spinner` 存在时禁止 `::after` 再生成 spinner（双 loading 回归规则）。
5. 禁止：把 Theme Pack/设计主题皮肤/widget layout 改成延迟；组件层写 `!important` 或颜色字面量。

---

## 3. 组件规范（markup + 状态）

> 所有组件：`aria-*` 必须完整；键盘可达；`focus-visible` 必须有可见环；
> `:disabled` / `[aria-disabled="true"]` / `[aria-busy="true"]` 三态齐全。

### 3.1 Button `.w-button`

```html
<button class="w-button" type="button">保存</button>
<button class="w-button" data-tone="neutral" data-size="sm">取消</button>
<button class="w-button" data-variant="outline" data-tone="neutral">导出</button>
<button class="w-button" data-icon-only="true" aria-label="关闭"><w:icon name="close"/></button>
<button class="w-button" aria-busy="true" disabled>保存中</button>
```

- 主按钮每屏 ≤1；危险操作 `data-tone="danger"`；工具栏用 `neutral`/`outline`/`soft`。
- 尺寸：默认 40px；表格/工具条 32px（`data-size="sm"`）；登录/空态 48px（`lg`）。
- 图标按钮必须 `data-icon-only="true"` + `aria-label`。

### 3.2 Field `.w-field` + `.w-input/.w-select/.w-textarea`

```html
<label class="w-field">
  <span class="w-field__label">名称</span>
  <input class="w-input" name="name" required>
  <span class="w-field__hint">用于后台展示</span>
</label>
```

- 必填、错误、帮助：`required` / `.w-field[data-state="error"]` / `.w-field__hint`。
- `select` 统一 `appearance:none` + 语义箭头；`textarea` 默认 2.5 行高。
- 同一表单行内控件高度一致；不要行内改 `height`。

### 3.3 Switch `.w-switch`

```html
<label class="w-switch">
  <input type="checkbox" role="switch" checked>
  <span>启用</span>
</label>
```

- 固定 51×31（Apple 比例），开态用主色，滑块带柔和阴影；`disabled` 降透明度并禁用指针。
- 表单提交用原生 `checkbox`，不要用 `<div>` 模拟；JS 只改 `checked`。

### 3.4 Checkbox / Radio `.w-check`

```html
<label class="w-check"><input type="checkbox" checked><span>记住我</span></label>
<label class="w-check"><input type="radio" name="mode" checked><span>自动</span></label>
```

- 尺寸 18px；checkbox 圆角 6px；radio 正圆 + 内白点；只读用 `disabled`。

### 3.5 Card / Table / Dialog / Drawer / Empty

- `w-card`：一个页面同一层级卡片圆角/阴影一致；禁止在卡片上再套 `style` 阴影。
- `w-table`：表头 `surface-muted`、行 hover、末行无描边；数字列 `font-variant-numeric: tabular-nums`。
- `w-dialog` / `w-drawer`：头部/正文/底部分区；关闭按钮 `data-icon-only`；危险确认用 `data-tone="danger"`。
- `w-empty`：必须有一个可执行出口（按钮/链接），标题 + 一句说明，不写“暂无数据”四字了事。

### 3.6 Tabs / Pagination / Badge / Alert

- `w-tabs` 为分段控件（segmented）：容器 `surface-muted`，选中项白底 + 轻阴影；`aria-selected` 必须同步。
- `w-pagination` 当前页用主色实底；首尾/上一页/下一页 `aria-disabled`。
- `w-badge` 只表达状态，不替代可读文字；`data-tone` 与语义一致。
- `w-alert` 必须带 `data-tone`；错误提示必须给出下一步动作。

---

## 4. 标签渲染：编译期静态 / 编译期展开 / 运行期

Weline 模板编译器 v3 用 `TagDescriptor::$stage` 决定**何时** Lower：

| stage | 何时处理 | 产物 | 典型标签 |
|---|---|---|---|
| `compile` | 编译期 | 纯 HTML，或编译后的 PHP 语句 | `icon`/`static`/`css`/`js`/`url`/`lang`/`block`/`if`/`foreach`/`hook` |
| `runtime` | 运行期 | `TagRuntime::render()` 调用 | `form` 等需要运行期上下文的标签 |
| `auto` | 编译期判定 | 有运行期表达式→runtime，否则 compile | 自定义标签默认 |

**必须区分三种语义**（`stage=compile` 不等于纯静态）：

1. **编译期静态（static）**：产物只有 HTML 字符串，不含 `<?php`、不读请求/DB/主题状态；
   同一源码两次编译字节一致，运行期零开销。纯结构/固定文案的标签必须做到这一档。
2. **编译期展开（compiled-PHP）**：编译期完成代码生成，但产物是 PHP（如 `lang` 输出
   `__()`、`url` 输出 URL 构造、`block` 输出 `framework_view_process_block(...)`），
   运行期执行。它仍是「编译期完成编译」，但不是「编译期完成展示」。
3. **运行期（runtime）**：编译期只生成 `render()` 调用，DOM/数据在运行期决定。

硬规则：

1. 纯静态标签**不得**读取运行期状态；一旦属性里出现 `@lang` / `{{ }}` / `<?= ?>` /
   请求或主题数据，必须转为 compiled-PHP 或 runtime。
2. 同一标签的静态/动态形态输出**同一份 DOM**（标签名、类、属性、子节点一致），
   差异只允许在文本/URL 等值上；用编译器影子对比（shadow）验证。
3. 需要再次 PHP 渲染的（`block`/`widget`/`template`/条件循环/权限）走 compiled-PHP 或
   runtime，不允许在 static 阶段 echo 业务数据。
4. 新增标签必须提供：`TagDescriptor`（stage/attrs/paired）、静态输出快照、
   动态路径测试；`static` 档还要「同源码两次编译字节一致」断言。
5. 页面模板不允许为了「看起来静态」把手写 HTML 包成标签；标签的价值是语义、
   去重与编译期校验，而不是换一种写法。

> 现状（2026-10-21）：`BuiltinRegistry` 中 25 个 compile 标签、1 个 runtime（`form`）。
> 其中 `url`/`lang`/`block`/`hook`/`css`/`js` 属 compiled-PHP；`static`/纯结构标签属
> static。新增标签时按上表归类，并在本文件登记。

## 4.5 暗色 hover / active 语义（硬）

- 新增语义 Token：
  - `--weline-theme-active-bg`：当前项背景（浅色 `primary-bg-subtle`，暗色 `sidebar-active` 半透明蓝紫）；
  - `--weline-theme-active-text`：当前项文本（浅色 `primary-text-emphasis` `#3730a3`，暗色 `#c7d2fe`）；
  - `--weline-theme-surface-hover`：后台必须映射 `--backend-color-surface-hover`，禁止回落到前台浅色。
- 规则：
  1. active 文本**禁止纯白**；暗色用 `#c7d2fe`/`#dbe4ff` 一类柔和强调色。
  2. active 背景禁止直接用主色实心（大面积刺眼）；用 14–24% 主色混入 surface。
  3. hover 用 `surface-hover`（暗色 #1b2940），文本用 `--weline-theme-text`，不用 primary。
  4. 导航、菜单、选项卡、分页的 active 状态统一消费上面两个 Token。
- 对比度实测（WCAG）：
  - 浅色 active `#e0e7ff` / `#3730a3` = **8.06:1**
  - 暗色 active 合成背景 `#1e314f` / `#c7d2fe` = **8.8:1**
  - 暗色 hover `#1b2940` / `#e6eef8` = **12.48:1**
- 根因备忘：`tokens/apple.css` 的 `:root` 曾重新定义 `surface-hover` 并覆盖 foundation 暗色值，
  导致暗色 hover 变浅底浅字；修复方式为后台映射 + 暗色覆盖块，且放在 `:root` 之后。

## 5. 色盘可调性验收（必须可复现）

1. 后台 → 主题编辑器 → 颜色/色盘，修改主色/表面/文字/描边/状态色；
2. 保存后刷新页面，检查：按钮、开关、输入焦点环、Tab 选中、卡片描边/阴影、
   表格表头、暗色模式全部跟随；
3. 用浏览器 DevTools 检查计算样式：`getComputedStyle(btn).backgroundColor` 应等于
   色盘解析值；组件文件里搜不到 `#`/`rgb(`；
4. 若某组件不跟随：先查它是否直接写了颜色 → 改成语义 Token；再查色盘是否只写了
   `--color-*` 而组件读 `--backend-color-*`（后台需同时映射）。

---

## 6. 新增/修改组件的交付清单

- [ ] 只改 `view/ui/css/components/<component>.css`（或 `tokens/apple.css`），不散落页面级样式
- [ ] 颜色/圆角/间距/高度全部走 Token；无 `!important`；无字面量
- [ ] 结构：基础态 + hover + active + focus-visible + disabled + busy/loading + error/empty
- [ ] `tokens/apple.css` 若新增 Token：同步本文档与 `colors/_default.css`（如涉及色盘）
- [ ] `php bin/w resource:compile welineUi` 成功，产物包含新规则
- [ ] 契约测试：`BackendThemeStandardContractTest` + `DesignSystemContractTest`（见 test/Unit/Ui）
- [ ] 真机截图：前台 + 后台，1440/375，亮/暗；主按钮/开关/输入/表格各一张
- [ ] 色盘改色复验：改主色后上述组件全部跟随

---

## 7. 禁止清单

- 组件层写死颜色/圆角/阴影/高度；用 `!important` 抢样式；另起一套按钮/输入外观。
- 页面模板里写 `<style>` 视觉块或行内视觉 `style`（仅允许 `--w-*` 变量接线）。
- 静态标签里塞运行期数据；动态标签伪装静态；同一标签两套 DOM。
- 改色盘只改一处变量、组件读另一处变量（导致“调了不生效”）。
- 新增第三方 UI 库/图标库/Web Font 替代本设计系统。
