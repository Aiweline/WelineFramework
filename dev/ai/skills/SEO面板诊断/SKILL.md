---
name: SEO面板诊断
description: SEO inspector skill for the weline trigger, lazy-loaded SEO panel, and browser console report protocol.
version: 1.0.0
---

# Role

本技能负责 Weline SEO 面板、`weline` 调试入口、页面 head/结构化数据/GA4/SEO 检查，以及给 AI 使用的控制台报告协议。

# When To Use

- 用户提到 SEO 面板、输入 `weline`、SEO inspector、SEO 解析器、head/meta/canonical/hreflang/JSON-LD/GA4 检查。
- 用户需要让 AI 不看截图，直接从浏览器控制台导出 SEO 诊断报告。
- 需要验证 SEO inspector 是否按需加载、报告是否能作为上线/推广判断依据。

# Core Rules

- SEO 面板默认在前台 SEO footer 中注入轻量 bootstrap；开发文档等开发态页面可用 `<w:seo slot="inspector"/>` 只注入 inspector bootstrap。
- 输入 `weline` 后才加载 `seo-inspector/inspector.css` 和 `inspector.js`。
- AI/自动化优先使用控制台 JSON 报告，截图只能作为视觉辅助。
- SEO 报告里的 `info` 是环境说明，尤其本地/中文页 GA4 说明，不得当成 fail。
- 分析顺序固定为：`verdict` -> `actions(P0->P3)` -> `checks.seoFlat` -> `monitoringGaps` -> `ga4`。
- `slot="inspector"` 只用于调试入口，不输出 meta、canonical、JSON-LD，也不触发业务 footer SEO slot provider。

# Console Protocol

输入 `weline` 加载 inspector 后，控制台会暴露：

```js
await window.__WELINE_SEO__.getReport()
await window.welineSeo()
window.__WELINE_SEO_INSPECTOR__.report()
window.__WELINE_SEO_INSPECTOR__.publish()
window.__WELINE_SEO_REPORT__
JSON.parse(document.getElementById('weline-seo-report').textContent)
```

协议字段：

- `contractVersion`：当前 SEO 报告协议版本。
- `command`：`weline-seo`。
- `verdict`：发布/推广主判断，`blocked` 和 `fix` 不能作为可推广状态。
- `actions`：按 P0/P1/P2/P3 输出的修复建议。
- `checks.seoFlat` 与 `checks.seoGrouped`：SEO 规则明细。
- `monitoringGaps`：监控/埋点缺口。
- `ga4` / `ga4Checks`：GA4 检测结果。

# Diagnosis Workflow

1. 打开真实 URL，先确认初始只存在 `script[data-weline-seo-bootstrap]`，不存在完整 inspector CSS/JS；开发文档页应看到 `data-weline-seo-source="inspector-slot"`。
2. 优先运行 `await window.__WELINE_SEO__.getReport()`；如果需要打开 UI，再输入 `weline` 并确认 `window.__WELINE_SEO_INSPECTOR__` 存在。
3. inspector 已加载时运行 `window.__WELINE_SEO_INSPECTOR__.publish()`。
4. 读取 `window.__WELINE_SEO_REPORT__` 或 `script#weline-seo-report`。
5. 按报告里的 `agentGuide.interpretationOrder` 分析，不要把截图当主证据。

# Validation

- 真实浏览器验证 `weline` 能打开 SEO 面板。
- 控制台 `window.__WELINE_SEO_INSPECTOR__.publish()` 返回可 JSON 序列化报告。
- `script#weline-seo-report` 写入成功。
- 报告中 `command === "weline-seo"`。
- 如果修改 inspector JS，执行 `node --check app/code/Weline/Seo/view/statics/seo-inspector/inspector.js`。
