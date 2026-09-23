## msg-1 | 2026-09-22T13:36:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave2-status | kind:assign

wave1 **pass**（公网 `/`+`/products` HIT 已稳）。残留主线：

**B6**：`server:status` Homepage warmup 在 warmup `adopted` + `done warmed=6` 后仍显示 `hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`。

本机证据（刚核）：
- warmup pid 37042：`adopted` → `done` warmed=6 failed=0
- 公网 `/`+`/products` 双次均 HIT
- status 两 Worker 仍 fail-open 假阴性

已知曾合：`publishAdoptedHomepageFpcProofToWorkerReadiness` → `markBusinessHomepageHot` + status_report（Server 2.0.63）——运行仍不同步。请查：
1. adopt 后 status_report 是否发出/被 Master `auditChildStatusReport` 吸收
2. status 展示是否仍读 READY 快照而非 live meta
3. 修到 `server:status` 在 adopted 后显示 hit=true / reason 含 adopted（禁假 HIT）

禁自 reload；修完 channel 回报 → PM 批 reload → 性能抽检。

## msg-3 | 2026-09-22T13:46:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave2-status | kind:assign

后端 B6 **pass**（msg-2 · Server `2.0.66`）：status 优先 overlay `last_status_report`。

项目经理已批 `server:reload`（必要时再 Master 级）。请抽检：
1. warmup owner Worker：`hit=true` · reason 含 `deferred-warmup:adopted`
2. 公网 `/`+`/products` 仍 HIT（回归）
3. 更新 review/channel；verdict pass|fail

## msg-2 | 2026-09-22T13:44:46+08:00 | from:后端 | to:项目经理 | thread:pm-arrange-wave2-status | kind:result

**Team:后端:** B6 done（禁自 reload）

| 字段 | 内容 |
|------|------|
| result | **pass**（CLI 已验：Worker#1 `hit=true` · `reason=homepage-fpc:deferred-warmup:adopted` · `state=hot`） |
| root_cause | adopt→`markBusinessHomepageHot`→Worker `status_report` **已发出且 Master 已写入 `last_status_report`**（lsr: hit=1/adopted/hot）。但 `server:status` / 消费者只读 READY 时落盘的 `metadata.homepage_fpc`；运行中 Master（12:04 启）早于 audit 回写补丁（12:29 落盘），**未把 LSR 提升到 `homepage_fpc` meta** → 假阴性。非「adopt 未发生」。Worker#2 非 storefront warmup owner，fail-open 属预期。 |
| fix | `WorkerReadinessState::homepageMetaFromStatusReportFields` + `overlayHomepageFpcMetaFromLastStatusReport`；`auditChildStatusReport` 复用；`ServiceRegistry::getStatusSnapshot` / `ServerInstanceManager` IPC 构建 / `Status` CLI **优先 overlay LSR**（CLI 无需 Master 重启即可显示真实 adopted）。 |
| files | `WorkerReadinessState.php`；`ServiceOrchestrator.php`；`ServiceRegistry.php`；`ServerInstanceManager.php`；`Console/Server/Status.php`；`WorkerReadinessHomepageStatusReportContractTest.php`；`Server/doc/开发日志.md`；`Server/etc/module.php` |
| tests | `WorkerReadinessHomepageStatusReportContractTest` **6/6 OK** |
| version | Server **2.0.65→2.0.66**；Framework 本席未改码（仍 2.5.148，adopt 链已证明） |
| reload_needed | **yes（建议 Master 级 reload/restart）** — CLI 已立刻正确；持久 `homepage_fpc` meta 回写需加载新 Master 代码。禁本席自 reload。Worker#2 保持 fail-open 直至其自身有 process HIT proof（非本 B6 假 HIT）。 |

核验摘录：`php bin/w server:status` → Worker#1 `state=hot, hit=true, source=process, fpc=HIT, reason=homepage-fpc:deferred-warmup:adopted`。

## msg-4 | 2026-09-22T13:49:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave2-status | kind:result

Team:性能检查工程师:
result=done
verdict=pass
worker1_status=hot hit=true adopted
worker2_status=fail-open（**接受为预期**：非 storefront deferred warmup owner，禁假 HIT）
home=HIT
products=HIT
review=meetings/性能检查-review.md「wave2 B6 抽检」

### 抽检（禁 reload）
- Worker **62830**：`state=hot, hit=true, source=process, status=200, fpc=HIT, reason=homepage-fpc:deferred-warmup:adopted`
- Worker **62858**：`hit=false` · `fail-open` → **pass/expected**（单 owner 选举；非本 B6 缺陷）
- warmup 62830：paths `/`→`/products`→locales · **adopted** · **done warmed=6 failed=0** · `/`+`/products` probe HIT
- 公网 cookieless：`/` #1/#2 **HIT**；`/products` #1/#2 **HIT**（`x-weline-fpc`+`x-wls-fpc-status`）

B6 status overlay **通过**；wave1 公网回归 **通过**。

## msg-5 | 2026-09-22T13:50:00+08:00 | from:项目经理 | to:全体 | thread:pm-arrange-wave2-status | kind:closeout

已接住性能检查 **verdict=pass**（msg-4）。wave2 B6 收口。

- status owner：`hit=true` / `deferred-warmup:adopted`
- Worker#2 fail-open：接受为预期
- 公网 `/`+`/products` 回归 HIT

编制关闭。R4 放大器（Search/ACL/Fiber）与主题非阻塞项如需再开另波。
