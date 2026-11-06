# 主题规范门禁（S1–S13）

**权威实现**：`app/code/Weline/Theme/Service/Spec/ThemeSpecValidator.php`
**命令**：`php bin/w theme:compile spec`
**开发期强制**：`Framework\View\Template::compileSourceBytes()` 在 `DEV` 下调用
`ThemeSpecValidator::assertFileForDev()`，命中硬违规直接抛
`ThemeSpecViolationException`（含 rule / file / line / hint）。

本文件是规则正文；代码只负责执行。改规则 = 改本文件 + `ThemeSpecValidator` + 测试。

## 为什么存在

默认主题与自定义主题共用同一份 head 合同：head 只允许「tokens / 后台」阻塞，
首屏外壳必须内联，图片必须预留盒模型，字体必须 `swap`，SEO 只能从 `<w:seo>` 出。
过去这些要求只写在文档里，靠人工 review 漏检，最终以 PageSpeed render-blocking、
CLS、重复 og、跨站 llms.txt 品牌串台的形式在生产暴露。

## 规则表

| 规则 | 范围 | 硬/软 | 内容 | 违反后果 |
|------|------|-------|------|----------|
| **S1** | 前端 head partial | 硬 | 禁止 `media="print"` 伪装非阻塞 CSS | 抛异常 |
| **S2** | 前端 head partial | 硬 | 只允许 tokens pack（`data-weline-theme-tokens="1"`）与 `<noscript>` 回退（`data-weline-preload-fallback="1"`）阻塞；其余样式表必须 `rel="preload" as="style"` + onload 切换；业务 JS 仅允许 `defer`/`async`/`module` | 抛异常 |
| **S3** | 默认主题 head | 硬 | `default.phtml` / `assets-prefix.phtml` / `minimal.phtml` 必须包含 `<theme:layout-critical>` | 抛异常 |
| **S4** | `layout-critical*.css` | 硬 | 内联预算 ≤ **14,336 字节**（`ThemeSpecValidator::MAX_LAYOUT_CRITICAL_BYTES`） | 抛异常 |
| **S5** | 首屏图 | 硬 | 标 `data-weline-first-screen` 或 class 含 hero/lcp/banner/cover 的 `<img>` 必须带 `width`+`height` | 抛异常 |
| **S6** | 首屏图 | 硬/软 | 首屏图禁止 `loading="lazy"`（硬）；缺 `fetchpriority` 记软告警 | 抛异常 / 警告 |
| **S7** | `<w:theme:font>` | 硬 | 显式 `display` 只允许 `swap` / `optional`（默认即 swap） | 抛异常 |
| **S8** | head 区域 | 硬 | 禁止手写 `og:*` 与 head 内 `application/ld+json`；正文局部 JSON-LD 允许 | 抛异常 |
| **S9** | 渲染期 | 软 | `<h1>` 唯一（渲染后断言，见 Seo 检查面板） | 警告 |
| **S10** | 渲染后整页 | 硬 | 必须含 `</body>` / `</html>` 且非空（FPC 落盘同判据） | 抛异常 |
| **S11** | `layout-critical*.css` | 硬 | 首个样式表必须自钉层序（`ThemeCssLayerContract::DECLARATION`），否则 `weline-theme` 的最低位可能被抢先固定而失效 | 抛异常 |
| **S12** | `layout-critical*.css` | 硬 | 未分层正文里 `display`/`visibility` 的 `!important` 必须带条件（位于 `@media` 内，或选择器带状态标记 `[hidden]` / `:not([open])` / `[data-*` / `aria-` / `:hover` / `:focus` / `.is-*`） | 抛异常 |
| **S13** | 主题整根（critical ↔ 皮肤叶） | 硬 | 同一 `媒体作用域+选择器+几何属性` 在 layout-critical 与皮肤叶里不得取值不同 | 抛异常 |

### 适用范围

- 默认主题：`app/code/Weline/Theme/view/theme/**`
- 自定义主题：`app/design/*/*/frontend/**`（含 `partials/head`、`layouts`）
- 模块模板：`app/code/*/*/view/templates/**`（仅 S5–S8，避免误伤后台片段）
- 后台（`/backend/`）不受 S1–S3 约束：管理界面允许阻塞 chrome。

## 用法

```bash
# 全量校验（默认主题 + 全部设计主题），有硬违规 exit 1
php bin/w theme:compile spec

# 开发期：改主题模板保存后首次渲染即报错（无需跑命令）
```

## 反例 / 正例

```html
<!-- 反例 S2：阻塞品牌 CSS -->
<link rel="stylesheet" href="/static/.../brand.css">

<!-- 正例 S2：preload + noscript -->
<link rel="preload" as="style" href="/static/.../brand.css"
      onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" data-weline-preload-fallback="1" href="/static/.../brand.css"></noscript>

<!-- 反例 S1：media=print 伪装 -->
<link rel="stylesheet" href="/x.css" media="print" onload="this.media='all'">

<!-- 正例 S5/S6：首屏图 -->
<img src="/hero.webp" width="1600" height="600" fetchpriority="high"
     class="homepage-hero" alt="...">
```

## S13：layout-critical 与皮肤叶的几何一致性

**权威实现**：`app/code/Weline/Theme/Service/Spec/ThemeCriticalSkinConflict.php`
**接线**：`ThemeSpecValidator::validateCriticalSkinConflicts()`（`assertDirectory()` 末尾，跨文件必须整根校验）

### 判据

默认主题的架构契约是：**首帧几何由内联 layout-critical 钉住，皮肤到齐后不得改动它**。
只要同一个 `媒体作用域 + 选择器 + 属性` 在两侧写成不同值，位移就是必然的 —— critical
先画一帧，皮肤（preload + onload，非阻塞）随后覆盖，CLS 被打回来，而且现场看不出
「是谁和谁不一致」。

- 键 = `media | selector | property`；同一键有多条声明时取**最后一条**（CSS 层叠后者胜）。
- 只比「两侧都声明」的同一键；一侧没声明 = 不归 S13 管（那是「谁该拥有它」的归属问题）。
- 皮肤叶清单取自主题自己的 `partials/head/theme-head-css-overlay.php`
  （与 `ThemeHeadChromeCssPack::OVERLAY_LIST_RELATIVE` 同源），不另立名单。

### 只比几何属性

`display` / `position` / `width|height|min-*|max-*` / `inline-size|block-size` /
`aspect-ratio` / `gap|row-gap|column-gap` / `flex|flex-basis|flex-grow|flex-shrink` /
`flex-direction|flex-wrap|order` / `grid-template*|grid-auto-*` / `align-*|justify-*` /
`inset|top|right|bottom|left` / `line-height|font-size|letter-spacing|word-spacing` /
`columns|column-width`，外加 `margin*` / `padding*` 前缀。

**颜色 / 背景 / 边框 / 阴影不进 S13**：它们不移动盒子，报出来只是噪声，会把真实位移
淹掉。完整白名单见 `ThemeCriticalSkinConflict::GEOMETRY_PROPERTIES` + `GEOMETRY_PREFIXES`。

### 归一与容差

1. `var(--x, 22px)` → `var(--x)`：critical 带 fallback 是首帧设计意图，不该算成漂移。
2. 数值容差 **1%**：`aspect-ratio: 420/270` 与 `1.55` 视为相等，避免算术表达差异误报。
3. 注释先遮蔽再解析，选择器与值都做空白归一。

### 豁免（不允许沉默漂移）

有些断点阶梯本来就要由皮肤接管（首帧允许一次水平位移，换更少的 critical 字节）。
这类意图必须写进 critical 文件顶部的显式豁免：

```css
/* @critical-skin-waivers: .ca-brand:width, .ca-nav:gap */
```

语法 `selector:property`，多个用逗号分隔；`property` 留空 = 豁免该选择器全部属性。
豁免是**声明**，与「改 critical 对齐皮肤终值」是两回事：能改就改，改不了（预算/台阶）
才写豁免，并把原因写进同一个注释。

### 已知边界（静态不可知）

S13 是**纯静态跨文件比较**，只看得见「两侧都声明」的同一键。以下场景它不会报，需要人盯：

- **断点阶梯不对称**：皮肤在 `≤480` 另有 `104px`，而 critical 只在 `≤900` 生效 →
  窄屏首帧仍会跳。S13 比不到这一层，因为两侧没有同键声明。
- **继承与简写**：`flex: 1` 与 `flex-grow/shrink/basis` 三条分开写，静态不等价（按字面比会报）。
- **运行时计算值**：`clamp()` / `calc()` 里的变量在换主题时改值，静态阶段看到的是同一个表达式，
  算相等。

这类残留只能在浏览器侧用计算样式回归（见 `theme-css-architecture-vs-style.md`「首帧几何」）。

## 与其它覆盖面的关系

- **FPC 完整性**：`HtmlCacheAdmission::admitDocument()` 与 S10 同一判据；head-only 快照
  在 publish 时被拒，read 时自愈（`.ok` 标记）。
- **SEO 唯一出口**：S8 与 `HeadRenderer` / `PageSeoContextResolver` 对应；手写 og 会
  造成 canonical/og:url 与 https 规范不一致。
- **GEO 站点绑定**：`Geo\Service\Protocol\SiteBindingResolver`（品牌名按站点 id 绑定）、
  `UrlDeduper`（协议文档按规范化 URL 去重）。
- **字体**：`ThemeFont preload="1"` + `FontLocalePolicy::isCjk()` 决定是否发 CJK 子集。

## 渐进收紧（诚实记录）

S5/S6 当前只对「首屏标记」的 `<img>` 硬校验；普通正文图片暂为软告警，避免一次性
把历史模板全部打红。预算收紧（如 14,336 → 10,240）需要同步改常量与
`LayoutCriticalBudgetTest`，不得只改文档。

## 上线后改动不生效：固定排查顺序（2026-10-28 线上取证）

线上出现「代码已 rsync、`php -l` 通过、md5 一致，但页面仍是旧输出」时，按下面顺序排查，
**不要**先怀疑代码逻辑：

1. **FPC 叶**：`generated/html/generations/*`（`live` 软链指向当前代）。清叶只解决整页 HTML 缓存。
2. **店面持久缓存池**（这才是 headline 常见根因）：`theme.storefront_head`、
   `theme.storefront_head_assets`、`theme.storefront_homepage_layout_html` 存在 WLS 持久池
   （`weline_theme_storefront_chrome` 等）里，key 由 theme 身份 + publish 世代决定。
   `theme:update` 报 `theme_update_batch_already_current` 时代次不变 → key 不变 → 旧 head
   继续被命中，`?w_fpc_pierce=` 与清 FPC 都无效。
   必须执行：`php bin/w cache:clear -f --no-warm`（`-f` 含持久池；`--no-warm` 避免预热失败
   导致退出码 2）。
3. **框架编译产物**：`php bin/w framework:compile`。`server:start` 会预检产物，产物过期会
   直接拒绝启动（"框架编译产物缺失或已过期"）。
4. **WLS 进程**：`server:restart -r` 只做 worker 滚动重启，**master 常驻**（opcache 共享内存
   不重置）；需要彻底换代码用 `server:stop` + `framework:compile` + `server:start`。

取证（同一份文件、同一台机）：清池前首页 JSON-LD 为 `"url": "https://amayum.com"` +
跨站 `logo`；执行 `cache:clear -f --no-warm` 后立即变为本站域名、跨站 `logo` 消失。

固定发布链（手工 rsync 场景）：`rsync 目标文件` → `chown` → `framework:compile` →
`cache:clear -f --no-warm` → 清 FPC 叶 → 公网冷请求验收。生产 `core:update -b dev` 在有
脏工作区时会拒绝（`-f` 会强制覆盖），不要用 `-f`，改走精准 rsync + 上面的链。

