---
name: SEO面板诊断
description: SEO diagnostics through the unified Weline Panel SEO tab and console report protocol.
version: 1.3.0
---

# Role

本技能负责通过全局 `weline` 面板进入 `SEO` tab，检查页面 head/meta/canonical/hreflang/JSON-LD/H 标签/多搜索平台适配，并生成 AI 可读 SEO 报告。

# When To Use

- 用户提到 SEO 面板、`SEO` tab、SEO inspector、SEO 解析器、head/meta/canonical/hreflang/JSON-LD/H 标签/多平台搜索引擎检查。
- 用户需要让 AI 不看截图，直接从浏览器控制台导出 SEO 诊断报告。
- 需要验证 SEO inspector 是否按需加载、报告是否能作为上线/推广判断依据。

# Core Rules

- 唯一入口是输入 `weline` 打开全局 `WelinePanel`；SEO 不再提供独立 inspector 口令入口。
- 开发环境可直接输入 `weline` 打开面板；生产环境即使输入 `weline`，也必须先通过 token 门禁。
- SEO 能力只注册为全局面板的 `SEO` tab；只有页面具备 SEO inspector 能力时显示。
- SEO inspector 的 CSS/JS 仅在激活 SEO tab 或发布报告时按需加载，并内嵌渲染到 `SEO` tab 内。
- SEO tab 内含 `SEO 校验`、`搜索平台` 子页；`搜索平台`覆盖 Google、Bing、Yahoo、Yandex、Baidu、DuckDuckGo、Naver、Seznam、Sogou、Ecosia/Qwant。
- 浏览器内平台检测只能确认当前渲染 DOM、head、JSON-LD、heading、可见内容等；robots.txt、HTTP header、X-Robots-Tag、重定向链、Core Web Vitals、IndexNow 和站长后台状态必须看报告 `limitations`，不能当作已通过。
- AI/自动化优先使用统一控制台 JSON 报告，截图只能作为视觉辅助。
- SEO 不负责 GA4、Pixel、CTA 事件或外部转发诊断；这些统一进入全局面板的 `访问` tab，由 Weline_Visitor Pixel 报告当前事件清单、过滤规则和转发状态。
- 分析顺序固定为：`verdict` -> `scores` -> `engineMatrix` -> `issues` -> `actions(P0->P3)` -> `checks.seoFlat` -> `limitations` -> `monitoringGaps`。

# Console Protocol

开发环境打开页面后：

```js
await window.WelinePanel.open()
await window.WelinePanel.activateTab("seo")
await window.WelinePanel.publish({tabs:["seo"], refresh:true})
```

统一报告位置：

```js
window.__WELINE_PANEL_REPORT__
JSON.parse(document.getElementById("weline-panel-report").textContent)
```

顶层协议：`contractVersion === "weline-panel-console/v1"`，`command === "weline-panel-report"`。

SEO tab 协议：`tabs.seo.contractVersion === "weline-panel-seo/v1"`，`tabs.seo.command === "weline-panel:seo"`。

多平台字段：

```js
const seo = window.__WELINE_PANEL_REPORT__.tabs.seo
seo.scores.indexability
seo.scores.understandability
seo.scores.experience
seo.scores.engineFit
seo.engineMatrix.google
seo.engineMatrix.baidu
seo.issues
seo.limitations
```

平台矩阵状态：

- `pass`：浏览器 DOM 层可确认通过。
- `warning`：存在可执行优化项或平台专项风险。
- `fail`：当前页面存在平台相关阻断项。
- `unknown`：浏览器模式无法确认，需服务端爬虫、站长平台或外部 API。

# Diagnosis Workflow

1. 打开真实 URL，先确认初始只存在轻量 `script[data-weline-panel-bootstrap]` 和 SEO 能力登记脚本，不存在完整 inspector CSS/JS。
2. 输入 `weline`，进入 `SEO` tab。
3. 运行统一控制台协议发布报告。
4. 读取 `window.__WELINE_PANEL_REPORT__` 或 `script#weline-panel-report`。
5. 按报告里的 `tabs.seo.agentGuide.interpretationOrder` 分析，不要把截图当主证据。
6. 多平台诊断先看 `tabs.seo.engineMatrix`，再看 `tabs.seo.issues` 与 `tabs.seo.limitations`；不要把 `unknown` 写成已通过。

# Validation

- 真实浏览器验证 `weline` 能打开全局面板并进入 `SEO` tab。
- 控制台 `await window.WelinePanel.publish({tabs:["seo"], refresh:true})` 返回可 JSON 序列化报告。
- `script#weline-panel-report` 写入成功。
- 报告中 `tabs.seo.contractVersion === "weline-panel-seo/v1"`。
- 报告中 `tabs.seo.engineMatrix` 覆盖默认 10 个搜索引擎/生态。
- 报告中 `tabs.seo.scores` 包含 `indexability`、`understandability`、`experience`、`engineFit`。
- 如果修改 inspector JS，执行 `node --check app/code/Weline/Seo/view/statics/seo-inspector/inspector.js`。
