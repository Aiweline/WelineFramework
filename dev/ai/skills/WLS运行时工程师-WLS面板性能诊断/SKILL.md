---
name: WLS运行时工程师-WLS面板性能诊断
description: Diagnose static-resource caching, FPC misses, worker_fastpath, services, workers, logs, and request waterfalls through the unified Weline Panel WLS tab and console report. Use for WLS-panel performance evidence; not for generic page design or functional E2E.
---

# WLS panel performance diagnosis

## Contract

- Type `weline` to open global `WelinePanel`; production still requires its token gate. The retired `wls` phrase must not open a panel.
- The `WLS 服务` tab appears only under WLS and uses its dedicated JSON endpoint.
- Automation consumes the structured report, not screenshots:

```js
await window.WelinePanel.open()
await window.WelinePanel.activateTab("wls")
await window.WelinePanel.publish({tabs:["wls"], refresh:true, limit:80})
```

`window.__WELINE_PANEL_REPORT__` has `contractVersion === "weline-panel-console/v1"`; `tabs.wls.contractVersion === "weline-panel-wls/v1"`.

## Workflow

1. Open the real URL and confirm only the bootstrap script is initially loaded.
2. Publish a fresh WLS report and inspect actions, request groups, slow requests/spans, services, and workers.
3. For static misses, distinguish explicit preview requests from ordinary assets; only documented preview paths/parameters bypass cache/fastpath.
4. Fix and re-measure on a dedicated WLS instance using the process-stability lifecycle.

Validate PHP/JS syntax, route collection when a Controller changed, panel/tab visibility, report contracts, and cleanup. Never hide performance errors or make the initial page load the full diagnostic bundle.
