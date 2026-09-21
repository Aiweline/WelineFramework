# 汇审补记 — Memory sidecar 自我淘汰

## 结论
共享 Memory 服务**本来就会自我淘汰**（`SessionStore::relieveMemoryPressure` / LRU + `gc_mem_caches`；维护周期与读路径都会触发；TLS session 缓存另有水位淘汰）。

## 本波加固（低配）
1. `session_server.php`：`role=memory_server` 时合并 `wls.memory_service` 的 `max_sessions` / 水位 / `gc_interval`
2. 本地 `env.php`：`memory_service.max_sessions=15000`，高/低水位 0.70/0.55（256M → ~179MB/~141MB）
3. Session sidecar：`max_sessions=20000`，同水位
4. 证据：日志 `Config: max_sessions=15000`；UT `SessionStoreMemoryPressureTest` 5/5；首页 200

## 低配机行为
键数或 PHP used/allocated 触高水位 → LRU 淘汰到低水位；不依赖人工清缓存。
