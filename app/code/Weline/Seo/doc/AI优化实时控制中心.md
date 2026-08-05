# AI 优化实时控制中心

## 边界

实时控制中心是只读控制面。PageBuilder 的 plan_json 仍是页面内容、revision、fingerprint、构建和发布状态的唯一真相源。Cycle、Activity、Queue 回执和 SSE 只能用于调度可观测性、审计和界面展示，不得触发、恢复、重置或判定任何构建、发布、保留或回滚。

## 数据模型

- SeoOptimizationCycle 以站点和调度 request_key 聚合一次检测，记录正交 lifecycle、phase、outcome 和真实目标计数。
- SeoOptimizationActivity 是追加式、幂等的脱敏事件流。核心事件保留 180 天，细粒度进度保留 14 天。
- SeoOptimizationScheduleState 保存默认 1440 分钟的最近和下一次检测投影。
- 上线前 Run 不迁移 Activity，在查询层以单目标虚拟父任务兼容展示。

## 实时协议

后台页面通过 Weline.Api.resource 读取 seo_optimization_control，并通过 Weline.Api.stream 订阅 optimizationActivityStream。快照先返回 as_of_cursor，流从该游标继续。服务端使用 WLS SchedulerSystem 让出执行权，不使用 sleep、usleep、裸 EventSource 或浏览器 Worker。

SSE 断开后客户端每 15 秒降级轮询；45 秒无信号会重连。游标过期返回 resync_required，客户端重新读取快照。

## AI 分析输出合同

事件表现分析固定使用数据库绑定的 Provider、Model 和 Account。输出必须是单个严格 JSON 对象，完整通过 target、可编辑路径、主指标、guardrails、confidence 和敏感数据门禁后才能产生建议。guardrails 只允许服务端可计算指标，排除主指标，去重后最多 5 个。

JSON 或语义合同失败时，同一 Provider/Model 最多执行 3 次无会话、温度 0 的纠错调用；纠错提示只携带固定合同 finding，不携带上一轮原始响应。三次仍不合法则 Run 以 `analysis_failed` 结束，不创建 Experiment、不修改 PageBuilder owner、不进入 PublishQueue。

Block 候选在 CAS 前完成 HTML 重渲染，并在私有 optimization checkpoint 中暂存实验前的 canonical HTML 及 SHA-256；公开 snapshot 不返回该内容。回滚不重新生成近似 HTML，而是恢复原始 canonical HTML，并在写入前验证完整 owner fingerprint 必须与 Experiment 的 base fingerprint 一致。HTML 或 fingerprint 任一不匹配时进入 `manual_intervention`，不得覆盖当前 owner。

## 安全

全局视图只投影 Websites 查询返回的授权站点。null 表示全部授权站点，0 始终表示真实站点 0。Activity facts、证据、建议和修改摘要经过字段白名单、长度限制和 PII/HTML/提示词过滤。界面使用 textContent 和安全 DOM 节点渲染实时字符串。
