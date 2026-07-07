---
name: WLS运行时工程师-WLS面板性能诊断
description: WLS performance diagnostics through the unified Weline Panel WLS 服务 tab and console report protocol.
version: 1.1.0
---

# Role

本技能负责通过全局 `weline` 面板进入 `WLS 服务` tab，诊断 WLS 性能瓶颈、静态资源缓存路径、FPC miss、worker_fastpath、Services/Workers/Logs，以及生成 AI 可读性能报告。

# When To Use

- 用户提到 WLS 面板、WLS Performance、`WLS 服务` tab、性能面板报错、`Invalid Weline binary magic`、query-bin 卡慢、静态资源卡慢、FPC miss、worker_fastpath、请求瀑布图、Services/Workers/Logs。
- 需要判断普通静态资源是否应该走内存缓存或快速缓存。
- 需要判断预览态是否可以绕过缓存。
- 需要在浏览器里验证 WLS 诊断是否能通过统一 Weline Panel 按需加载。

# Core Rules

- 唯一入口是输入 `weline` 打开全局 `WelinePanel`；不再使用 `wls` 口令。
- 开发环境可直接输入 `weline` 打开面板；生产环境即使输入 `weline`，也必须先通过 token 门禁。
- WLS 诊断只作为 `WLS 服务` tab 出现，且仅 WLS 环境显示。
- WLS 性能数据仍使用专用 JSON 端点，不依赖 `Weline.Api.resource('server')` 或 query-bin。
- AI/自动化优先通过 JSON 报告分析性能，不以截图作为主要证据。
- 静态文件、模块静态资源、主题非预览资源，能走 WLS 内存缓存、fastpath 或快速缺失返回就走缓存/fastpath。
- 只有明确预览请求才绕过缓存：路径包含 `__preview` / `theme_previews`，或 query 中存在明确的 `preview`、`preview_theme`、`preview_theme_id`、`theme_preview`、`weline_preview_token`、`virtual_theme_id`、`frontend_theme_id`、`backend_theme_id`、`visual_editor` 等预览参数。

# Console Protocol

开发环境打开页面后：

```js
await window.WelinePanel.open()
await window.WelinePanel.activateTab("wls")
await window.WelinePanel.publish({tabs:["wls"], refresh:true, limit:80})
```

统一报告位置：

```js
window.__WELINE_PANEL_REPORT__
JSON.parse(document.getElementById("weline-panel-report").textContent)
```

顶层协议：`contractVersion === "weline-panel-console/v1"`，`command === "weline-panel-report"`。

WLS tab 协议：`tabs.wls.contractVersion === "weline-panel-wls/v1"`，`tabs.wls.command === "weline-panel:wls"`。

# Diagnosis Workflow

1. 打开真实 URL，确认初始只存在 `script[data-weline-panel-bootstrap]`，不预加载完整 WLS 性能 CSS/JS。
2. 输入 `weline`，进入 `WLS 服务` tab。
3. 运行统一控制台协议发布报告。
4. 优先看 `tabs.wls.actions`，再看 `requests.groups`、`bottlenecks.slowRequests`、`selectedRequest.trace.spans`、`services`、`workers`。
5. 静态资源卡慢时，先确认是否普通静态资源；非明确预览不应绕过缓存，缺失静态文件应优先 fast missing。
6. 修改 WLS 运行时代码后，使用独立 `9502+`、唯一 `ai-test-*` 实例验证；自动验证结束必须停止实例。若交付需要用户人工验收，保留该实例并报告 URL/实例名/端口/状态/停止命令，等用户确认验收后再停止。

# Validation

- `php -l` 检查改动 PHP 文件。
- `node --check` 检查面板和 WLS JS。
- 新 Controller 或路由变更后执行 `php bin/w setup:upgrade --route`。
- 验收点：
  - 输入 `weline` 能打开全局面板。
  - `WLS 服务` tab 仅 WLS 环境出现。
  - 旧 `wls` 输入不打开任何面板。
  - `await window.WelinePanel.publish({tabs:["wls"], refresh:true})` 返回 `weline-panel-console/v1` 顶层报告，且 `tabs.wls.contractVersion === "weline-panel-wls/v1"`。

# Constraints

- 不修改 `generated/`。
- 不新增或手写 `routes.xml`。
- 不用 `sleep`、`die`、`exit`。
- 不为了绕过报错吞掉真实性能错误；面板要给出能理解的错误信息。
- 不把 WLS 面板做成初始页面重资源脚本。
