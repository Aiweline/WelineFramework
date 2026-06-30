---
name: WLS运行时工程师-WLS面板性能诊断
description: WLS performance panel skill for diagnosing framework bottlenecks, lazy-loading the panel, and keeping static/cache paths fast under WLS.
version: 1.0.0
---

# Role

本技能负责 WLS 性能面板、`wls` 命令调试入口、框架性能瓶颈定位、静态资源缓存路径和显式预览绕过缓存规则。

# When To Use

- 用户提到 WLS 面板、WLS Performance、输入 `wls`、性能面板报错、`Invalid Weline binary magic`、query-bin 卡慢、静态资源卡慢、FPC miss、worker_fastpath、请求瀑布图、Services/Workers/Logs。
- 需要判断普通静态资源是否应该走内存缓存或快速缓存。
- 需要判断预览态是否可以绕过缓存。
- 需要在浏览器里验证 WLS 面板是否按需加载、默认中文、是否能在后台/前台/开发文档页打开。

# Core Rules

- WLS 面板必须在页面 footer/body-end 注入轻量 bootstrap；初始页面只允许注入口令监听和配置，不得预加载完整面板 HTML、CSS、JS 或 query-bin API 链。
- 输入 `wls` 后才加载 `wls-performance-panel/panel.css` 和 `panel.js`；面板 HTML 由 JS 打开时创建。
- WLS 性能面板自身的诊断数据不要依赖 `Weline.Api.resource('server')` 或 query-bin；面板用于排查 query-bin 时，必须使用独立 JSON 端点，避免诊断工具被被诊断链路拖垮。
- 面板默认中文。新增用户可见文本必须中文优先并走 `__()` 或前端文案表。
- 静态文件、模块静态资源、主题非预览资源，能走 WLS 内存缓存、fastpath 或快速缺失返回就走缓存/fastpath。
- 只有明确预览请求才绕过缓存：路径包含 `__preview` / `theme_previews`，或 query 中存在明确的 `preview`、`preview_theme`、`preview_theme_id`、`theme_preview`、`weline_preview_token`、`virtual_theme_id`、`frontend_theme_id`、`backend_theme_id`、`visual_editor` 等预览参数。
- SEO 面板的 `weline` 口令只在前台 SEO footer 中启用，同样只加载轻量 bootstrap；不要把 SEO inspector 全量脚本放进初始 head。

# Diagnosis Workflow

1. 打开用户给的真实 URL，先确认页面初始阶段是否只出现 bootstrap：
   - WLS：存在 `script[data-weline-wls-performance-bootstrap]`。
   - WLS：不存在 `link[data-weline-wls-panel="css"]` 和 `script[data-weline-wls-panel="js"]`。
   - SEO：前台页存在 `script[data-weline-seo-bootstrap]`，不存在 `link[data-weline-seo-inspector]` 和 `script[data-weline-seo-inspector]`。
2. 输入 `wls` 后验证 WLS 面板能打开，且不再出现 `Invalid Weline binary magic`。
3. 在 WLS 面板按顺序查看：
   - Requests：找总耗时、FPC miss、DB、Session、Template 异常行。
   - Waterfall：找耗时最高 span。
   - Services：看 SessionServer / MemoryServer 是否慢或未命中。
   - Workers：看 worker、端口、pid、请求分布是否异常。
4. 对静态资源卡慢：
   - 先确认是否普通静态资源。
   - 如果不是明确预览，不应绕过缓存。
   - 如果是缺失静态文件，应优先 fast missing，不应进入完整框架渲染链路。
5. 修改后必须重载 WLS 路由/进程，并用浏览器在真实 URL 验证。

# Validation

- `php -l` 检查改动 PHP 文件。
- `node --check` 检查面板 JS。
- 新 Controller 或路由变更后执行 `php bin/w setup:upgrade --route`。
- 真实浏览器冒烟：
  - `https://127.0.0.1:9520/dev/tool/docs?id=6`
  - `https://127.0.0.1:9520/dev/tool/docs/api`
  - 一个普通前台页面。
- 验收点：
  - 初始页只加载 bootstrap。
  - 输入 `wls` 后才加载 WLS 面板 CSS/JS。
  - 面板默认中文。
  - 面板数据来自专用 JSON 端点。
  - docs 页面不再出现 `Invalid Weline binary magic`。
  - SEO inspector 输入 `weline` 后才加载全量脚本。

# Constraints

- 不修改 `generated/`。
- 不新增或手写 `routes.xml`。
- 不用 `sleep`、`die`、`exit`。
- 不为了绕过报错吞掉真实性能错误；面板要给出能理解的错误信息。
- 不把 WLS 面板做成初始页面重资源脚本。
