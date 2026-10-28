# 主题规范门禁（S1–S10）

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

