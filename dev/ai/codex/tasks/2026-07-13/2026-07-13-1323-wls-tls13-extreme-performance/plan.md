# 计划

1. 审计目标计划、TLS 1.3、拓扑选择、策略热路和已有基准证据。
2. 建立 Direct/Dispatcher HTTPS 冷热基线并定位 CPU、TLS、写入和 FPC 长尾。
3. 对高置信瓶颈做低风险热路径优化，保持策略、安全、后台 Key 和缓存一致性。
4. 验证 TLS 1.3、并发启动、QPS/p95/p99、10万/100万请求稳定性及 Worker 故障恢复。
5. 更新 WLS 架构、性能预算与验收记录，完成 Browser/GitNexus/发布审计。
6. 在不复制 Worker 策略的前提下加入公开 h3/h2/h1 自动协商、TLS session reuse 与多路复用，并验证 Direct/Dispatcher 两种 upstream 拓扑。
