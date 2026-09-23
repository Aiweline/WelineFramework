# channel · pixel-sandbox-init · 数据分析交付

时间：2026-09-22  
席位：`Team:数据分析:`  
`notify_pm`: **true**

## 结果

`result=closed`

结账成功页事件监视流已恢复：`WelinePixelSandbox` / `WelineEventSandbox` 在 telemetry 异常后仍创建；`checkout_success` 进全量流；重复转化黄标 `hit_kind=dedupe` 可见。

## 根因（已修）

`__initBehaviorTelemetry` / PageBuilder 印象在沙盒创建前同步执行；抛错中断脚本 → 无沙盒；`__WelinePixelLoaded` 过早置 true 导致二次加载无法补建。

## 改动要点

1. `__WelinePixelLoaded` 延后到沙盒就绪；半截初始化可重入补建  
2. 行为遥测 / 印象 init 挪到沙盒 + Forwarders 桥接之后，try/catch  
3. `__ensurePixelSandboxBus` + `__seedSandboxFromRecentEvents`（缓冲空回放转化族+page_view）  
4. 监视开启时同步回放；去重黄标必经 `sb.emit`  
5. 版本 `2026.09.22-sandbox-init1` / 引导 `20260922-sandbox-init1`；模块 `1.1.39`→`1.1.40`  
6. 清理 `pixel.phtml` 双份 IIFE  

未改 Payment SESSION。

## 验证

- UT：`PixelSandboxInitResilienceContractTest` + 相关 pixel 契约 33 tests OK  
- WB（禁缓存 reload）：成功页可见 `hasSandbox=true`，脚本 `2026.09.22-sandbox-init1`，监视「去重丢弃 1」+ `checkout_success` 系统行  

## related_web_urls

- [结账成功验收](https://p05113ef3.test.weline.com:9555/checkout/success?order_uuid=058c5c3b-6524-4d72-8e29-1c3bf98cb2ae)

`notify_pm: true` — `@项目经理：本席已交付/上报，请检查并更新 SESSION`（Visitor 像素沙盒监视流；禁改 Payment SESSION）。
