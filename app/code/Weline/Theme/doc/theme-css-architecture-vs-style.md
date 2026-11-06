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

# 文字与背景对比度契约 —— 硬规则

**原则：信息必须一眼可见。** 对比度不是「有点淡更雅致」的风格选择，是主题可读性的验收项。

## 1. 最低对比度

| 文本类型 | 最低 | 目标 |
|----------|------|------|
| 正文、副标题、说明、标签、链接、按钮文字 | **4.5:1** | 7:1 |
| ≥24px 或 ≥18.66px 粗体的大标题 | **3:1** | 4.5:1 |
| 输入框 placeholder、图标、控件边界等非文本信息 | **3:1** | 4.5:1 |

达不到最低值即视为缺陷，不得用「古风/做旧/留白」解释。

## 2. 禁止同色相糊在一起

- **禁止**暖色背景配暖色文字、冷色背景配冷色文字后仍低于 4.5:1（例如米黄纸 `#eeeae1` 上的暖灰 `#7a7874`、深墨栏 `#1f2124` 上的墨灰 `#565959`）。
- 浅色纸面只允许用**深墨阶**（如 `#1c1c1c` / `#4a4a48` / `#5f5d58`）；深色反色面只允许用**浅纸阶**（`#f7f4ef` / `#d4cfc5`）。
- 语义色（success / warning / danger / link）用作小字号文字时，必须按实际底色验算；`#9a7b3c` 这类中间调不得直接当浅底正文色。

## 3. 反色/表面重映射必须成对

- 任何 `[data-surface="inverse"]`、深色 header/footer、抽屉、浮层，必须**同时**绑定前景与背景；只改背景或只改文字 = 违规。
- 禁止让子元素继续继承外层纸面墨色（或反之）；反色容器内显式声明文字色。
- CTA 按钮必须用 `--color-on-primary` 一类成对 token；禁止让通用链接色（如石青 `#3d6b78`）落到朱砂按钮上。

## 4. 验收（缺一不可）

- 按**实际计算样式**验收，不采信 token 字面值：对每个可见文本节点取 `getComputedStyle` 的 `color` 与该元素实际不透明背景（含祖先合成），按 WCAG 公式算比值。
- 至少覆盖：首页、列表页、详情页、FAQ、指南、购物车、结算、账户、政策页；桌面 1440 与手机 375 各一遍。
- 背景图/渐变上的文字无法用纯色公式判定时，必须截图人工确认，不得默认通过。
- 修复后复跑同一审计，低对比条目清零才算通过；不得只改根 token 就宣称全站通过。

## head 阻塞策略（2026-10 起，S1–S3）

head 只允许两类阻塞样式表：

1. tokens pack（`data-weline-theme-tokens="1"`，首屏变量必须同步到位）；
2. backend pack（仅 `/backend/`，管理界面不在 S2 范围）。

storefront / overlay / 设计主题皮肤 / 第三方字体 CSS 一律
`rel="preload" as="style"` + `onload` 切换 + `<noscript data-weline-preload-fallback="1">`。
策略收口在 `ThemeHeadChromeCssPack::tryLinkHtml()`（storefront/overlay 内部转 preload），
模板不必逐个改；自定义主题皮肤里的手写字面量链接由门禁 S2 兜底。

首屏外壳由 `<theme:layout-critical>` 内联兜住（`.w-container` / hero 宽高比 / 字号阶梯 /
按钮最小高度，并把移动端自适应契约一并内联），预算两层：单文件 ≤ 14,336 字节、
composed（基线|快照 + addendum）≤ 16,384 字节。规则与反例见 `theme-spec-gates.md` §S4。

### critical 必须与皮肤终值一致（S13，架构级）

**皮肤是终值权威，layout-critical 只能“提前画出同一个盒子”，不得自己另定一套尺寸。**

理由：critical 先于皮肤生效，皮肤（preload + onload，非阻塞）随后覆盖。同一个
`媒体作用域 + 选择器 + 几何属性` 两侧取值不同 → 首帧按 critical 画、皮肤到齐后跳到终值，
CLS 必被破，且现场看不出「谁和谁不一致」。

执行约定：

1. 要 pin 就 **pin 皮肤的稳态值**（同一个表达式，如同一个 `clamp()`），不是“首帧先写个占位”。
2. 宁可 **不 pin** 让皮肤完全拥有，也不要 pin 一个之后会被覆盖的值。
3. 断点阶梯本就要由皮肤接管时，写显式豁免 `/* @critical-skin-waivers: sel:prop */`，
   不得沉默漂移。
4. **自定义主题必须跟着骨架对齐**：主题自己的首帧骨架（`layout-critical.css` 或增量
   `layout-critical-addendum.css`）的值要与该主题皮肤（`partials/head/theme-head-css-overlay.php`
   列出的叶）一致；这条对自定义主题同样生效，不是「框架基线管不着」的例外。
   **首选形态是 addendum，不是整体 fork**（见下文「layout-critical 的继承模型」）。

判据、几何属性白名单、`var()` 回退归一、1% 数值容差、豁免语法与**静态不可知边界**
（断点阶梯不对称 / 简写与长写 / 运行时计算值）见 `theme-spec-gates.md` §S13。

# 移动端自适应契约 —— 硬规则（默认主题自带，设计主题继承）

**一句话：默认主题基线自己把手机端做完，设计主题继承即可；只有确实要偏离时才覆盖，
并且必须付出可解释的特异性代价。** 契约落在
`app/code/Weline/Theme/view/ui/css/layout-critical-frontend.css`（无层，随 `<theme:layout-critical>`
内联进 head），因此**所有设计主题都在它之后**加载皮肤，皮肤能改它，但必须先赢过它的特异性。

## 1. 契约内容（三条，全部在 `@media` 内）

| # | 规则 | 选择器特异性 | 目的 |
|---|------|--------------|------|
| ① | 输入字号地板 `max(var(--weline-input-font-floor), 1rem)` | (0,2,1) | 控件字号 < 16px 时 iOS 聚焦会整页放大 |
| ② | 触控目标下限 `min-block-size: var(--weline-tap-target-min)` | (0,2,1) | WCAG 2.5.5 44px；用 `min-*` 而非 `height` |
| ③ | 点按体验：`-webkit-tap-highlight-color: transparent` + `touch-action: manipulation` | (0,0,0) `:where()` | 去灰块闪烁 / 去双击 300ms 延迟 |

守卫统一为 `@media (pointer: coarse), (max-width: 48rem)` → **桌面零影响**；
另加 `@supports (height: 100dvh)` 把页壳 `min-height` 从 `100vh` 升到 `100dvh`（地址栏收起/展开不再留白）。

可调 token：`--weline-tap-target-min`（默认 `2.75rem`）、`--weline-input-font-floor`（默认 `16px`）。
主题只调数值时改 token 即可，不要复制规则。

## 2. 为什么必须写高特异性（实测，不要再用 `:where()`）

两条硬事实（2026-11-06 实测，hanfu 站点 390/360/320）：

1. **`:where()`（特异性 0）等于没写**：皮肤单类 `min-height`（0,1,0）直接压回 30px，
   实测触控小目标数 28→28、输入字号 15→15，契约失效。
2. **纯类型选择器提不动特异性**：首版把字号地板写成 `html input:not(...), html select, html textarea`，
   其中 `html select` 只有 **(0,0,2)**（两个类型选择器），连皮肤的 `.ca select { font: inherit }`(0,1,1)
   都赢不过 → `/category/hanfu`、`/products` 的排序下拉实测仍是 **14px**；
   而 `html input:not([type="hidden"])` 因为是 (0,1,2) 才生效，所以当时只有输入框被修好。
   改用 `:is(input, select, textarea):not([type="hidden"])` 后三类控件一致到 (0,2,1)。
3. **页面内联样式会到 (0,2,0)**：`.hanfu-plp .amz-plp__sort-select { font: inherit }` 两个类压过 (0,1,2)，
   最终定版把字号地板提到与触控地板同级的 **(0,2,1)**（`:root` + `:not()` 提供类级）。

结论（写新规则时照抄）：

- 能压回契约的只有**未分层 + 类选择器（≥0,1,0）**的皮肤/legacy 局部 —— 所以下限必须 ≥ (0,1,1)，
  实际取 **(0,2,1)** 才稳。
- 触控尺寸只能用 `min-block-size` / `min-inline-size`（盒模型 clamp），写成 `width`/`height` 会被皮肤后到的硬编码顶回。
- `a[href]` 对**行内 `<a>`** 的 `min-block-size` 天然无效（行内盒不吃 min-height），不要靠它救文字链接。

## 3. 桌面零影响（验收基线，改契约后必须复测）

1440 宽桌面实测：`formFontMin` 仍是主题自己的 12px、仍有 44 个 <44px 的控件 —— 即契约在桌面**完全没有介入**。
若改完契约后桌面这些数字变了，说明守卫写错（漏写 `@media` 或把规则放到了 media 外）。

## 4. 主题如何退出/覆盖

- addendum 与基线在**同一个 `<style>` 内**、且 addendum **后到** → addendum 写同特异性值即可覆盖，
  必要时用 `!important`（S12 只约束 `layout-critical.css` / addendum 里的 `display`/`visibility`，不动字号与尺寸）。
- 在皮肤里覆盖则必须 ≥ **(0,3,0)** 或 `!important`，否则 (0,2,1) 会赢——这正是契约想要的效果。
- 只想改数值时优先改 token，别抄规则，避免以后基线升级时两套并存。

## 5. 已知例外台账（框架自身部件，故意低于 44px）

以下由**框架部件自己**用 ID 选择性（1,1,0 级）钉死，契约结构上管不到，属于**有意保留**，
不是遗漏；改它们要连同步改两处并跑一致性测试：

| 部件 | 尺寸 | 出处 | 为何不改 |
|------|------|------|----------|
| `.w-storefront-float-edge__dismiss` | 24×24 | `storefront-float-layer.css` + `SharedChromeService` 内联 critical | Lighthouse「触摸目标」门槛是 ≥24px，40px 在浅纸面上会盖内容；两侧必须同值（`FloatChromeCriticalParityTest`） |
| `.weline-social-quick-bar__btn` | 高 36px | `storefront-float-layer.css` | 同上，浮层按钮成组排列，撑到 44px 会在 320 宽顶出横向溢出 |

同理，页脚**行内文字链接**（高 23px / 宽 22px）适用 WCAG 2.5.8 的「行内文本」豁免。
要动这批尺寸，必须先重测 320 档 `docScrollW`，不能只看单控件几何。

## 6. 验收（缺一不可）

```bash
php bin/w theme:compile layoutCritical   # 把各设计主题生成物对齐当前基线
php bin/w cache:clear
php bin/w fpc:clear -w <站点域名>        # html-disk 层 Nginx 直出，cache:clear 清不掉
```

然后对**至少 8 类页面**（`/`、FAQ、分类、列表、详情、购物车、博客、静态页）× **320/360/390** 取：

- `document.documentElement.scrollWidth - innerWidth` 必须为 **0**（横向溢出）；
- 全部 `input/select/textarea` 的 computed `font-size` 最小值必须 **≥ 16px**；
- 响应头必须带 `X-Wls-Fpc-Status: MISS`（证明是新渲染，不是旧 FPC 样本）。

`formFontMin` 出现 14/15 就是契约没送达或被压住，**不要**当成「主题设计如此」。

# layout-critical 的继承模型：baseline + addendum —— 硬规则

**一句话：基线归框架、增量归主题，两者拼起来才是真正内联进 head 的那段 CSS。**

## 1. 四种形态（`LayoutCriticalCssService::readShellCriticalCss()` 判定）

| 形态 | 判定 | 实际内联 | 基线升级是否自动送达 |
|------|------|----------|----------------------|
| baseline | 主题目录**没有** `layout-critical.css` | 基线 | ✅ |
| snapshot | 有文件且头部含 `weline-layout-critical:generated` | **基线**（忽略该文件） | ✅ |
| fork | 有文件且**无** marker（主题接管） | 该文件 | ❌（主题自己负责合并） |
| any + addendum | 同级目录另有 `layout-critical-addendum.css` | 上面的结果 **+ addendum** | ✅ |

`snapshot` 存在的原因是 `LayoutCriticalCssMaterializer` 会把基线物化成主题目录下的副本
（`theme:compile layoutCritical`），供主题自己看；读它等于读基线且**可能过期**，所以服务层显式走基线。
**要接管就把 marker 删掉变成 fork，否则永远只是镜像。**

## 2. addendum 是首选增量形态

- addendum 与基线在同一 `<style>` 内、**后到**：同特异性即胜，所以主题增量不必抬特异性。
- addendum **不要**写 `@layer` 序声明（层序由 `layout-critical.css` 负责，S11 只约束后者）。
- addendum 里的 `display`/`visibility` 的 `!important` 仍受 S12：必须带条件（`[hidden]` / `:not([open])` / `@media`）。
  hanfu 抽屉事故就是不带给条件：`display:none!important` 把打开态一起压回（marker `hanfu-menu-closed-state-only-v1`）。
- 迁移规程（fork → baseline+addendum，已经在 hanfu 与 playinginchina 上执行过）：
  1. 备份 fork 到 `dev/backup/<相对路径>.<时间戳>`；
  2. 用 `ThemeCriticalSkinConflict::declarations()` 做**声明级**比对：剥注释后 fork 独有行 = 真正主题增量，
     其余必须与基线逐值相等（若不等，先解释再迁）；
  3. 把增量写进 `layout-critical-addendum.css`（注释压成 marker 形式）；
  4. 删掉 fork —— `designCriticalPaths()` 只 glob **已存在**文件，所以 `theme:compile layoutCritical`
     **不会**把它复活；主题此后就是 baseline + addendum；
  5. `php bin/w theme:compile spec` 必须 `0 hard, 0 soft`，且 S13 的
     `Real themes have no critical skin drift` 必须绿。

## 3. S4 composed 预算（16,384 字节）

判的是**一个 area 目录实际会内联多少字节**（`baseline|override 快照 + addendum`），不是单文件 ≤14KB。
逐文件判会假通过：基线 6.8KB、增量 8KB，各自都没超，拼起来就超了。

现状（2026-11-06）：基线 6,979 B；`Weline/hanfu` = 基线 + addendum 8,192 B = **15,171 / 16,384**（余量 1,213）。

**不要靠抬 `MAX_COMPOSED_LAYOUT_CRITICAL_BYTES` 过关**——阈值不是可调参数。要腾空间就
**压注释、把散文搬到本文档**（本轮为此压缩了 hanfu addendum 的 15 个注释块，腾出 1,300+ 字节）。

## 4. 注释占预算，散文不属于 CSS

head 里的 CSS 会被 minify，注释不进浏览器；但 S4 量的是**原始文件字节**。所以：

- `layout-critical*.css` 注释只允许 **marker + 事故/PSI 编号**（如 `msh0ffvsbv`），一句以内；
- 「为什么这么写」写进本文档，CSS 里留指针（`契约正文：Theme/doc/...`）。
