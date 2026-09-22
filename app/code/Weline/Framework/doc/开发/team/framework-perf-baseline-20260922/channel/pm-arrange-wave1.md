## msg-1 | 2026-09-22T11:44:00+08:00 | from:项目经理 | to:架构师,后端,主题 | thread:pm-arrange-wave1 | kind:assign

已接住 性能检查工程师 escalate（channel/escalate-to-pm.md）。采纳 recommendation=C。

### 根因转述（施工席必读）

1. **R1**：`CachePool::getMultiple/setMultiple` 仍 foreach 单键；虽有 `BatchCacheAdapterInterface`，Pool 未走批量 → 伪批量 RPC。
2. **R2**：默认 homepage READY **fail-open** → 首击 deferred、warmup 未 HIT；冷 TTFB≈2.3s vs 暖≈10–20ms。
3. **R3**：冷构建瀑布 + HTML 体积；已发布布局/Slot 未完整进 HotCache。
4. **R4**：Search direct / ACL 多别名 / Fiber — **本波不施工**，下一波。

### 本波任务

| 席 | 任务 | 完成定义 |
|----|------|----------|
| 架构师 | 确认 R1 走 BatchCacheAdapter；R2 fail-open 收口策略（严格 prime vs deferred 仍保证首 HIT）；禁区不变 | 写入 `channel/pm-arrange-wave1.md` stance + 更新 surfaces 要点 |
| 后端 | 实现 R1（CachePool 优先 Batch 接口，保留逐键 fallback）+ R2（按架构师策略改 WlsRuntime/FPC 预热，使本机 `/` 与 `/products` 冷首击可 HIT 或证明路径进 warmup 首槽）+ Framework 升版+UT | 代码+测试绿；回报证据 |
| 主题 | 启动 R3：已发布布局/Slot 投影进 HotCache（CachePolicy；预览/草稿禁入） | design 纪要或首批补丁；不得阻塞 R1/R2 |
| 性能检查工程师 | 本波结束后复测 `性能检查-review.md` | 冷/暖 TTFB + getMultiple 非伪批量抽检 |

禁止：业务 parallel static；无 epoch 进程袋；删依赖假 HIT；跨模块直调；本席私改已冻禁区。

请各席开工前 get_skill / Read 本席镜；经本通道回报 stance 或施工结果。

## msg-2 | 2026-09-22T11:48:00+08:00 | from:架构师 | to:项目经理,后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:stance

stance: **同意**（采纳 recommendation=C；本波只表态机制收口，不私改已冻 UC / 禁区）。

### R1_decision（CachePool 真批量）

- **确认**：`CachePool::getMultiple` / `setMultiple` **必须**优先走已存在的 `BatchCacheAdapterInterface`；非批量 Adapter 才逐键 `get`/`set` fallback。
- **核对结论**：`Weline\Server\Cache\Adapter\WlsMemoryAdapter` **已实现** `BatchCacheAdapterInterface`（远程 `getCacheMultiple` / `setCacheMultiple`）；`CachePool.php` L306–328 **仍 foreach 单键**，完全未 `instanceof BatchCacheAdapterInterface` → Adapter 批量能力被 Pool 浪费，属实现缺口而非选型缺口。
- **扩展点映射**：Framework 内 **Interface**（`BatchCacheAdapterInterface`）— 不新建 Event/Query；禁止业务旁路直调 Server Memory facade。
- **后端施工边界**：只改 Framework `CachePool`（+UT）；保留 buildKey/namespace/禁用缓存/TTL 抖动语义；文档「已优先批量驱动」与代码不一致，修码后对齐 `统一缓存范围与性能优化.md` / 开发日志口径。禁止用假批量或删依赖冒充 HIT。

### R2_decision（homepage fail-open 收口）

- **否决**把默认改成严格 READY prime（`ready_gate_homepage_fail_open=0`）作为本波默认：严格 prime 仍保留为**运维显式**选项（E_COMPILE_ERROR 不可捕会杀 Worker / STARTUP_FAIL_FAST）。
- **可执行收口（本波默认路径）**：
  1. **保留**默认 fail-open（READY 跳过 in-process `/` prime，proof 可为 `deferred-after-ready:fail-open`）。
  2. **硬耦合**：fail-open=1 时，`storefront_deferred_warmup` **必须实际执行**（当前 `Env`/`env.sample` 默认 `storefront_deferred_warmup_enabled=false/'0'`，与「fail-open 后靠 deferred 补 `/`」假设矛盾——这是冷首击全量 SSR 的机制缺口）。后端须使本机默认路径下 deferred **开启**（或 fail-open⇒deferred 自动启用），且 `runDeferredStorefrontCriticalWarmup` 继续把 `/` 放**首槽**，`max_paths≥6` 不挤掉 `/products` 等目录代表路径。
  3. Host/FPC authority 用公网验收 Host（`*.test.weline.com`），禁止回环 Host 预热出不可命中的 key。
  4. 完成定义：deferred 结束后探针对 `/`（及本波要求的 `/products`）为**真实** FPC HIT；可采用 `adoptDeferredHomepageWarmupProof` 更新 Worker 身份。**禁止**把 `hit=true` 写成假证明、禁止删 namespace 依赖保假 HIT。
- **扩展点映射**：路径贡献继续走 `FpcWarmupProviderInterface`（Theme/Product 注册）；预热编排属 Framework Runtime + FPC Coordinator；禁止跨模块直调 Service/Model「性能捷径」。

### 禁区（重申，与 perf-architect 冻结一致）

业务 parallel static；无 epoch 进程袋；假 HIT；单方定「加缓存」；跨模块直调；本席私改已冻 UC。

surfaces：见同目录 `surfaces.md`（本波机制面最小版）。

## msg-3 | 2026-09-22T11:49:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:relay

架构师 msg-2 已冻结。后端按下列施工，勿等其它席：

- **R1**：CachePool 优先 `BatchCacheAdapterInterface`（WlsMemoryAdapter 已有真批量）；逐键仅 fallback。
- **R2**：保留默认 fail-open；**硬耦合** fail-open=1 ⇒ deferred warmup 必须实际执行（纠正 `storefront_deferred_warmup_enabled` 默认 false）；`/` 首槽；`max_paths≥6`；公网 Host；真实 HIT 证明。否决默认改严格 prime。

读完 msg-2 全文后继续改码。

## msg-4 | 2026-09-22T11:52:00+08:00 | from:主题 | to:项目经理,架构师,后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:主题:
result=R3 首批补丁已落（不阻塞 R1/R2）
R3_status=patched

### 探查摘要
- 店面 Entity 填槽 / `readPublishedSnapshot` / ConfigStore·Pointer **已有** HotCache。
- 缺口在 `SlotRendererService::getLayoutData`：`runtimeCacheGet/Set` 空 stub + 私有静态袋（编辑器/遗留路径）。

### 本席落地
- Policy：`publishedLayoutStructurePolicy`（channel，`vary=[]`，deps `theme`，stale=0）
- 键：`pub_layout|area|theme|page_type|layout_option|scope|target_*`；草稿/page target 禁入
- Cleaner 增结构池清理；复用发布 `w_changed`
- 纪要：`meetings/主题-R3-design.md`；Theme `2.2.537`

### 建议后端协作（非本波阻塞）
- B1 R1 真批量（HotCache 写侧受益）
- B2 `rollbackReleaseBatch` 补 `w_changed`
- B3 架构师确认 `publishedSnapshotPolicy` scope=global 是否保持
- B4 性能复审 Entity 结构 HIT 率 vs HTML/widget SSR

证据：契约 UT `SlotRendererHotPathCacheContractTest` + cleaner/coordinator 断言。

## msg-4 | 2026-09-22T11:55:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=done
files_changed=app/code/Weline/Framework/Cache/Pool/CachePool.php; app/code/Weline/Framework/Runtime/WlsRuntime.php; app/code/Weline/Framework/Test/Unit/Cache/CachePoolBatchAdapterContractTest.php; app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeFailOpenDeferredWarmupContractTest.php; app/code/Weline/Framework/etc/module.php; app/code/Weline/Framework/doc/开发日志.md; app/code/Weline/Framework/doc/统一缓存范围与性能优化.md
R1=CachePool::getMultiple/setMultiple 在 adapter instanceof BatchCacheAdapterInterface 时走真批量（buildKey + 禁用池 + hits/misses；整批一次 TTL jitter；非批量逐键 fallback）。核对：仅 Server\\Cache\\Adapter\\WlsMemoryAdapter 实现该接口。开发日志曾写「已优先批量」与代码不符 → 按回归修并记入 2.5.141。
R2=按架构师 R2_decision：保留默认 fail-open；shouldRunDeferredStorefrontCriticalWarmup 在 fail-open 或 homepage proof 未 HIT 时强制调度 deferred（纠正 enabled 默认 0）；既有 runDeferredStorefrontCriticalWarmup 仍将 `/` 置首槽且 max_paths 默认 6。未改严格模式开关语义。
tests=phpunit CachePoolBatchAdapterContractTest + WlsRuntimeFailOpenDeferredWarmupContractTest + Server/WlsMemoryAdapterBatchTest → OK (12 tests, 64 assertions)
version=2.5.141（无 Schema/路由注册变更，未跑 setup:upgrade）
setup_upgrade=skipped_not_required

### 手工复测建议（给性能检查席）
1. 滚动重载 Worker 后，在 deferred 完成窗口观察日志 `deferred storefront critical warmup` paths 含 `/` 与目录代表。
2. 本机公网 Host（`*.test.weline.com`）冷探针 `/` 与 `/products`：期望 `X-Weline-FPC: HIT`（或 deferred 后 adopt proof reason=`homepage-fpc:deferred-warmup:adopted`），禁回环 Host 预热。

## msg-5 | 2026-09-22T11:56:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

R1/R2（后端 done）与 R3（主题 patched）均已回报。请立刻做开发后复审：

- 读 msg-2（架构师决议）、msg-4 主题、msg-4 后端、`surfaces.md`
- 代码抽检：CachePool 批量路径；WlsRuntime fail-open⇒deferred 强制
- 本机公网 Host 冷/暖探针 `/` 与 `/products`（禁回环 Host）
- 写 `meetings/性能检查-review.md`（pass/fail + 证据）；经本通道回报
- fail → escalate；禁自排下一波施工

## msg-6 | 2026-09-22T11:57:00+08:00 | from:项目经理 | to:全体 | thread:pm-arrange-wave1 | kind:note

[主题](7eeccb81-a185-4234-b6da-7a964aeee6ce) R3 正式 closed。主题建议的非阻塞项 **B2**（`rollbackReleaseBatch` 补 `w_changed`）、**B3**（`publishedSnapshotPolicy` scope=global 确认）、**B4**（Entity 结构 HIT vs SSR）→ **下一波**，等性能检查 review 结论后再排；本波不拉后端返工。

## msg-7 | 2026-09-22T11:58:30+08:00 | from:性能检查工程师 | to:项目经理 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=escalate
verdict=fail
findings=R1代码真批量pass；R2代码fail-open⇒deferred+/首槽/max_paths≥6 pass，但运行未闭环（Worker仍hit=false·fail-open；/products FPC MISS；无deferred adopt/paths日志；Worker启动03:38早于本波落码且本席未reload）；R3 Policy+草稿禁入代码pass，冷瀑布收益未证
evidence=meetings/性能检查-review.md；公网Host https://p05113ef3.test.weline.com:9555；`/` x-weline-fpc:HIT+edge STALE TTFB≈8–9ms；`/products` x-wls-fpc-status:MISS TTFB≈8–9ms；server:status Homepage warmup hit=false reason=homepage-fpc:deferred-after-ready:fail-open；ready-gate: storefront_fpc_skipped；UT CachePoolBatch+FailOpenDeferred+SlotRenderer 绿

@项目经理：请立刻组队解决

### options（≥2）

A. 受控滚动 reload Worker（保留脏改）→ deferred 完成窗 → 性能席复测 `/`+`/products` 冷/暖 + Worker proof hit/adopted。  
B. 后端排查 `/products` MISS + `runtime_storefront_fpc_skipped`（provider路径/owner选举/公网Host）；修完再 reload+复测。  
C. 并行 A 为主门禁 + B 若仍 MISS；B4 Entity HIT 与 R4 仍下一波。

### recommendation

C（以 A 为主门禁）

### suggested_seats

- Team:项目经理:（组队 / 批准受控 reload）
- Team:后端:（R2 运行闭环）
- Team:架构师:（必要时 Host/FPC authority）
- Team:性能检查工程师:（reload 后复测；禁自排）

## msg-8 | 2026-09-22T11:54:00+08:00 | from:项目经理 | to:后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（verdict=fail · recommendation=C）。

本波动作：
1. **项目经理批准并执行**受控 `server:reload`（保留脏改，不 git 擦场）。
2. deferred 完成窗后 → **性能检查工程师**复测 `/`+`/products` + Worker proof。
3. 若仍 `/products` MISS 或 proof 未 adopt → **后端**排查 B（provider 路径 / owner / 公网 Host / `runtime_storefront_fpc_skipped`）。

先执行 reload，再 resume 性能席复测。

## msg-9 | 2026-09-22T12:00:00+08:00 | from:项目经理 | to:后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

**A 已执行**：`php bin/w server:reload` 退出 0，耗时 5.1s；Worker 新 PID 60470/60547。

**reload 后立刻证据（仍未闭环）**：
- `server:status`：两 Worker 仍 `hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`
- `var/log/wls-storefront-warmup.log` 仅有 `stage=begin`：`paths=["/","/en_US","/ar_SA","/products","/en_US/products","/ar_SA/products"]`（路径 OK），但 **`hosts=["p05113ef3.test.weline.com:19655"]`**（Worker 口，不是验收 `:9555`）
- 无 `done` / `adopted` / incomplete 后续行 → deferred 疑似卡住或日志不全
- ready-gate 新窗仍见 `runtime_storefront_fpc_skipped` + fail-open-default（符合「READY 跳过 in-process」；闭环应靠 deferred）

进入 **B（后端主责）**：
1. 预热 Host authority：公网验收 Host 须可被 FPC 命中（`*.test.weline.com:9555`），禁止仅用内部 `:19655` 写出不可命中 key。
2. 查清 begin 后无 done/adopt：卡在哪条路径/异常；修到 proof `hit=true` 或 `homepage-fpc:deferred-warmup:adopted`。
3. `/products` 在公网 Host 上须可 HIT（或证明路径未进 FPC 的合法原因）。
4. 修完后回报 channel；项目经理再安排 `server:reload`（若需）+ 性能复测。

**性能检查工程师**：本回合先记录 reload 后基线（可探针），但 **以后端 B 闭环后再做正式 review 更新** 为准；禁自排施工。

## msg-10 | 2026-09-22T12:01:30+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=waiting_peer
verdict=fail（非正式最终；B 未修完前不标 pass）
baseline=reload后已记入 meetings/性能检查-review.md「reload 后基线」

### 摘要
- Worker 60470/60547：仍 `hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`
- 公网 `:9555`：`/` HIT+edge STALE/UPDATING TTFB≈41ms→15ms；`/products` **MISS** TTFB≈150ms→31ms
- warmup.log：begin paths OK（`/`首槽 max=6）但 **hosts=`…:19655`**；随后 **failed** warmed=2 failed=4（`/` cookies=4 未 ready；locale 301；内部 `/products` ready:HIT 但公网仍 MISS）；无 adopt
- 等后端 B（Host authority + adopt）；本席禁 reload / 禁自排

## msg-11 | 2026-09-22T12:02:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:relay

性能检查 msg-10 基线补充（施工时对照）：

- Host 仍 `:19655` → 与公网 `:9555` 权威不一致（主因嫌疑）
- warmup **failed** warmed=2/failed=4：`/` cookies=4 未 ready；locale 301；内部 `/products` 曾 ready:HIT 但公网仍 MISS
- 无 adopt；Worker proof 仍 hit=false

后端 B 继续：修 Host authority + 解释/修复 cookies-not-ready 与 301 失败路径；修完 channel 回报，勿自 reload（由项目经理批）。

## msg-13 | 2026-09-22T12:05:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B **patched**（msg-12 · Framework `2.5.143`）：Host 改 `public_origin`（`:9555`）；adopt/incomplete 日志；尾斜杠路径。

项目经理已批准并执行 **第二次** `server:reload`。请正式复测并更新 `meetings/性能检查-review.md`：

1. warmup hosts 须为 `…:9555`（非 `:19655`）
2. Worker proof `hit=true` 或 reason 含 `deferred-warmup:adopted`
3. 公网 `https://p05113ef3.test.weline.com:9555` `/` 与 `/products` 真实 FPC HIT
4. verdict pass|fail；fail → escalate（禁自排）

## msg-14 | 2026-09-22T12:08:00+08:00 | from:项目经理 | to:后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

第二次 `server:reload` **已成功**（Worker 81503/81568）。Host 权威已对：`hosts=["…:9555"]`。

**仍未闭环（后端继续）**：
- warmup `failed` + `incomplete` reason=`homepage-not-ready`
- `/`：status=200 · fpc MISS · **`set_cookie_count=4`** → ready=false → **无 adopt**
- `/en_US/`、`/en_US/products`：仍 **301**
- `/products`：内部 probe 可 `ready:fpc-hit`，但首页未 ready 阻断 adopt

后端：根治首页预热 `cookies=4` 导致 FPC 拒收（禁假 HIT）；收敛 locale 301 槽浪费。修完再请 PM reload。

性能检查：可基于本次 reload 写正式 review 更新（含 Host 已修、cookies 阻断 adopt）；若仍 fail → escalate。

## msg-12 | 2026-09-22T12:08:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=deferred Host 权威错用 Worker `public_host:main_port`（`:19655`），未读实例 `public_origin`（`:9555`）→ FPC key 与公网探针错位；`/` 在错误 Host 下触发 Session cookie×4 → FPC 拒收 → sample ready=false → 无 adopt。begin 后并非卡死：约 35s 有 `stage=failed`（PM 早探只见 begin）。locale `/en_US` 无尾斜杠经 discovery rtrim → 301 浪费槽。
fix=① `resolveWorkerPublicOrigin` 回落实例 `public_origin`；② `resolveCurrentInstanceWarmupHosts` / `resolveProcessLocalDynamicWarmupHosts` 优先公网 authority；③ deferred 路径直接用 provider+`normalizeInternalWarmupPath` 保留 `/en_US/` 尾斜杠；④ adopt 写 `adopted`/`incomplete` 阶段日志（含 homepage_sample）。
files=app/code/Weline/Framework/Runtime/WlsRuntime.php; app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimePublicOriginWarmupHostContractTest.php; app/code/Weline/Framework/etc/module.php; app/code/Weline/Framework/doc/开发日志.md
tests=phpunit WlsRuntimePublicOriginWarmupHostContractTest + FailOpenDeferred + Server StorefrontWarmup → OK (20 tests, 77 assertions)；本机反射核验 hosts=`p05113ef3.test.weline.com:9555`
version=2.5.143
reload_needed=yes（请项目经理批 `server:reload`；reload 后期望 warmup.log hosts=`:9555`，有 done 或 failed+adopted/incomplete；Worker proof `hit=true` reason=`homepage-fpc:deferred-warmup:adopted`；公网 `/`+`/products` 真实 HIT）
setup_upgrade=skipped_not_required

## msg-15 | 2026-09-22T12:10:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=escalate
verdict=fail
review=meetings/性能检查-review.md「正式复审（二次 reload · Host B 后）」

### 分项
- **Host 权威：pass**（81503 begin hosts=`…:9555`，相对前基线）
- **adopt/运行闭环：fail**（81503 failed→incomplete `homepage-not-ready`；`/` **cookies=4** · fpc MISS · 无 adopted）
- **公网探针**：`/` HIT（TTFB≈8–129ms）；`/products` **持续 MISS**（TTFB≈10–25ms）→ 完成定义未过

### 附注
复审窗他席曾 stop/start（非本席）；其后 89407 出现 `adopted`（`/` 已 cookies=0 HIT）——**不得**当 B2 已关；`/ar_SA/` 仍 cookies=4；公网 `/products` 仍 MISS；权威失败窗仍是 PM 二次 reload 的 81503。

@项目经理：请立刻组队解决

### options
A. 后端 **B2** 根治 `/` Set-Cookie×4 → FPC 拒收（禁假 HIT）  
B. 并行收敛 locale 301 + 公网 `/products` MISS  
C. A 为主、B 为辅

### recommendation
C（以 B2 cookies 为主）

### suggested_seats
后端（B2）、项目经理（组队/批 reload）、性能检查工程师（复测·禁自排）

## msg-16 | 2026-09-22T12:11:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（msg-15 · recommendation=C）。**B2 已在编**（勿重复排班）。

对照补充：
- 权威失败窗仍是二次 reload 的 **81503**（cookies=4 → incomplete）
- 附注：其后他席 stop/start 出现 **89407 adopted**（`/` cookies=0 HIT）——说明 cookie 路径可清零，请对照根因固化，不得把偶发 adopt 当已交付
- 辅线：locale 301 + 公网 `/products` 仍 MISS

修完 channel 回报；**禁止自 reload**；PM 批 reload 后再唤醒性能正式复测。

## msg-18 | 2026-09-22T12:20:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B2 **patched**（msg-17 · Framework `2.5.145`）：cookieless GET 不写 Session；过滤默认语 301 槽。

项目经理已批准并执行 **第三次** `server:reload`。请正式复测并更新 `meetings/性能检查-review.md`：

1. warmup hosts=`…:9555`；`/` **cookies=0**（或不再因 Set-Cookie 拒收）
2. proof `adopted` / `hit=true`
3. 公网 `/` 与 `/products` 真实 FPC HIT
4. locale 路径不再占 301 失败槽
5. verdict pass|fail；fail → escalate


## msg-17 | 2026-09-22T12:18:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=匿名公共 `/`（及 `/ar_SA/`）SSR 经 Newsletter `w:form csrf=auto` → `Token::create` → 原始 `Session::get/set`（ensureStarted）分配 `WELINE_CUSTOMER_SESSID` + 同族 Expire×3（共 cookies=4）；`FullPageCacheCoordinator` L2197 拒收 Set-Cookie → deferred sample ready=false → incomplete `homepage-not-ready` 无 adopt。`/products` 无 CSRF 隐藏域故 cookies=0 可 probe HIT。locale：`App::redirectDefaultLocalizationPrefixIfNeeded` 对默认语（本站 `en_US`）剥前缀 301，`/en_US/`、`/en_US/products` 浪费槽。偶发 89407 adopted（cookies=0）说明路径可清零，但未固化 Token 门禁前仍会回归。
fix=① `Token::mayPersistInSession`：cookieless frontend GET/HEAD 不碰 Session；`Csrf::render` 空令牌不输出（对齐 GuestStorefrontFpcContract）。② deferred `filterDeferredStorefrontWarmupPath` + Website 默认语 snapshot 用 `State::canonicalizeStorefrontLocalizationPath` 丢掉会 301 的路径；sample 记 `set_cookie_names`。③ Index/Product warmup provider 跳过默认语前缀。
files=app/code/Weline/Framework/Security/Token.php; app/code/Weline/Framework/View/Block/Csrf.php; app/code/Weline/Framework/Runtime/WlsRuntime.php; app/code/Weline/Framework/Test/Unit/Security/TokenAnonymousStorefrontFpcContractTest.php; app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeDeferredWarmupDefaultLocalePathContractTest.php; app/code/Weline/Framework/etc/module.php; app/code/Weline/Framework/doc/开发日志.md; app/code/Weline/Index/Api/View/ViewWarmupContributionProvider.php; app/code/Weline/Index/etc/module.php; app/code/Weline/Index/doc/开发日志.md; app/code/Weline/Index/Test/Unit/Api/View/ViewWarmupContributionProviderTest.php; app/code/Weline/Product/Api/View/ViewWarmupContributionProvider.php; app/code/Weline/Product/etc/module.php; app/code/Weline/Product/doc/开发日志.md; app/code/Weline/Product/Test/Unit/Api/View/ViewWarmupContributionProviderTest.php
tests=phpunit TokenAnonymous + DeferredWarmupDefaultLocale + FailOpenDeferred + PublicOriginWarmup + Index/Product ViewWarmupContribution → OK (13 tests, 31 assertions; 2 skipped 既有)
version=Framework 2.5.145; Index 1.0.3; Product 1.0.289
reload_needed=yes（请项目经理批 `server:reload`；**禁止本席自 reload**。期望：warmup `/` cookies=0 → probe HIT → adopted；paths 无 `/en_US/` `/en_US/products`；公网 `/`+`/products` 真实 FPC HIT）
setup_upgrade=skipped_not_required

## msg-19 | 2026-09-22T12:22:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=escalate
verdict=fail
review=meetings/性能检查-review.md「正式复审（三次 reload · B2 2.5.145）」

### 核验（Worker 59632）
- Host **pass**：`:9555`
- warmup **done** warmed=6 failed=0；paths=`/`,`/ar_SA/`,`/bn_BD/`,`/es_ES/`,`/fr_FR/`,`/products`（**含 `/products`**；无默认语 301 槽）
- `/` cookies=0 · ready=true · **adopted** `homepage-fpc:deferred-warmup:adopted` → adopt **真实**
- `server:status` 仍 hit=false/fail-open → **展示滞后**（adopt 只写进程内 proof，未回写 Master `homepage_fpc` meta），非假 adopt
- 公网：`/` **HIT**（TTFB≈4–30ms）；`/products` **MISS**（及 `/products/`、`/zh_Hans_CN/products`、`/ar_SA/products` 均 MISS）→ 完成定义失败主因

@项目经理：请立刻组队解决

### options
A. 后端 B3：公网 `/products` MISS（键/vary/locale vs warmup）  
B. 辅：adopt 后回写 status `homepage_fpc`  
C. A 为主、B 为辅

### recommendation
C（以 `/products` HIT 为主）

### suggested_seats
后端、项目经理、性能检查工程师（复测·禁自排）

## msg-20 | 2026-09-22T12:23:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（msg-19 · recommendation=C）。

**B3 主线**：公网 `https://p05113ef3.test.weline.com:9555/products`（及变体）须真实 FPC HIT。对照：warmup 槽含 `/products` 且内部可 HIT，公网持续 MISS → 查键/vary/locale/edge vs process FPC / Host 归一。
**辅**：adopt 后回写 Master `homepage_fpc` meta（消除 status 展示滞后）。

禁假 HIT；禁自 reload；修完 channel 回报 → PM 批 reload → 性能复测。

## msg-22 | 2026-09-22T12:31:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B3 **patched**（msg-21 · Framework `2.5.146` / Server `2.0.63`）：禁 edge 缓存 FPC MISS；cache key `|fpc2`；adopt 回写 Master meta。

项目经理已批准并执行 **第四次** `server:reload`。请正式复测：

1. 公网 `/` 与 `/products`（及变体）真实 FPC HIT（注意 edge 头 vs 进程 FPC）
2. `server:status` Homepage warmup 是否不再假阴性（hit/adopted）
3. 更新 `meetings/性能检查-review.md`；verdict pass|fail；fail → escalate

## msg-21 | 2026-09-22T12:30:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=公网 cookieless `/products` 的 `x-wls-fpc-status:MISS` **不是**进程 FPC 未暖：Worker 直连 `Host=…:9555` 已 `X-Weline-Fpc:HIT`；deferred warmup 为 **in-process**（同公网 authority）故 probe 可 HIT。托管 Nginx HTML edge 把冷 SSR（带 MISS 头）按 `proxy_cache_valid 200` 写入后，cookieless 探针长期吃到 `edge STALE/HIT` + 头仍 MISS（body≈1.5MB gzip vs 进程≈2.0MB）。辅：`adoptDeferredHomepageWarmupProof` 只写进程内 `$readyGateHomepageFpcProof`，未回写 Master `homepage_fpc` → `server:status` 停在 fail-open。
fix=① Server nginx：`map` upstream `X-WLS-FPC-Status`/`X-Weline-FPC`∈{MISS,BYPASS}→`proxy_no_cache`；HTML `proxy_cache_key` 加 `|fpc2` 淘汰毒条目。② Framework：`publishResponse` 入池快照后出站加 `Cache-Control: private, no-store`（入池头不变）。③ adopt→`WorkerReadinessState::markBusinessHomepageHot`；status_report 扁平字段；`auditChildStatusReport` 回写 Master meta；deferred 成功后立刻 status_report。禁假 HIT / 禁删 namespace。
files=app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php; app/code/Weline/Server/Service/Runtime/WorkerReadinessState.php; app/code/Weline/Server/Service/ServiceOrchestrator.php; app/code/Weline/Server/bin/worker.php; app/code/Weline/Server/bin/worker_ssl.php; app/code/Weline/Framework/Router/FullPageCacheCoordinator.php; app/code/Weline/Framework/Runtime/WlsRuntime.php; +UT/开发日志/module.php
tests=phpunit FullPageCachePublishMissNoStoreEdge + WlsRuntimeAdoptHomepageFpcMeta + ManagedNginxConfigWriterStaticEdgeCache + WorkerReadinessHomepageStatusReport → OK (6 tests, 47 assertions)
version=Framework 2.5.146; Server 2.0.63
reload_needed=yes（请项目经理批 `server:reload` 以重写 nginx 并加载 Worker；**禁止本席自 reload**。期望：公网 cookieless `/products` 见 `x-weline-fpc:HIT`/`x-wls-fpc-status:HIT`（edge 可为 MISS→HIT 或 BYPASS 后 HIT）；`server:status` homepage hit=true reason 含 `deferred-warmup:adopted`）
setup_upgrade=skipped_not_required

## msg-23 | 2026-09-22T12:34:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=escalate
verdict=fail
wave1_final=fail
review=meetings/性能检查-review.md「正式复审（四次 reload · B3 2.5.146）」

### 证据摘要（Worker 98447）
- hosts=:9555 OK；**incomplete** homepage-not-ready；**无 adopted**
- `/` cookies=0 但 **probe FPC MISS**；`/products` probe MISS；`/en_US/` 301 **槽回潮**
- 公网 `/`+`/products`(+变体)：皆 `x-wls-fpc-status:MISS` + edge **STALE**（禁当 pass）
- Worker 直连同 Host：亦 **MISS** · TTFB 2.8s/1.0s · `Cache-Control: private, no-store` → **进程 FPC 未 HIT**
- `server:status` hit=false/fail-open（与 incomplete 一致，非假阴性）
- **运行 nginx.conf 无 `|fpc2`、无 MISS→proxy_no_cache**（源码有、落盘无）→ B3 未生效于运行面
- R1 代码仍 pass

@项目经理：请立刻组队解决

### options
A. B4：nginx 配置真正写出并生效 + 修进程 FPC probe MISS  
B. 默认语过滤回潮  
C. A 为主、B 为辅

### recommendation
C（以 nginx 落盘+进程 HIT 为主）

### suggested_seats
后端、项目经理、性能检查工程师（复测·禁自排）

## msg-24 | 2026-09-22T12:35:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（msg-23 · recommendation=C）。

**B4 主线**（Worker 98447 权威失败窗）：
1. **进程 FPC**：warmup/公网/Worker 直连 `/` 与 `/products` 须真实 HIT（当前 probe MISS + Cache-Control private,no-store；勿假 HIT）
2. **nginx 落盘生效**：确认 `var/server/nginx/conf/nginx.conf` 含 `|fpc2` + MISS 禁存，且 **nginx 进程已 reload** 吃到新配置（源码有≠运行生效）
3. **辅**：默认语 `/en_US/` 301 槽过滤回潮

禁自 reload；修完 channel 回报 → PM 批 reload（含 nginx）→ 性能复测。

## msg-26 | 2026-09-22T12:43:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B4 **patched**（msg-25 · Framework `2.5.147` / Server `2.0.64`）：默认语对齐 en_US；warmup 钉 variant；`server:reload` 顺带写/reload nginx `|fpc2`。

项目经理已批准并执行 **第五次** `server:reload`（等待模式）。请正式复测：

1. conf 含 `|fpc2` + MISS 禁存；warmup 无 `/en_US/` 301；`/`+`/products` probe/公网 HIT
2. adopt + status 非假阴性
3. 更新 review；verdict pass|fail；fail escalate

## msg-27 | 2026-09-22T12:44:00+08:00 | from:项目经理 | to:后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:note

第五次 `server:reload`：**Worker 成功**；托管 Nginx **刷新失败**：
`Managed Nginx owner is not bound to an immutable certificate generation.`
另批 `php bin/w server:nginx:reload` 同错（exit 1）。
运行 `var/server/nginx/conf/nginx.conf` 仍无 `|fpc2` / `wls_edge_skip_fpc_miss_`（mtime 仍旧）。

→ 后端 B4 续：解开 Nginx owner↔证书代次绑定，或提供可写 conf + 生效路径；禁假 HIT。
→ 性能：可先核 **进程 FPC**（Worker 直连 Host=:9555）；公网 edge 在 nginx 生效前不得标 pass。

## msg-24 | 2026-09-22T12:35:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（msg-23 · recommendation=C）。**B4**：

1. 根治进程 FPC 对 `/`+`/products` warmup/直连 HIT（查 publishResponse no-store 是否误伤；键/vary；probe 二次仍 MISS）
2. 保证 ManagedNginx 配置写出 + nginx 真正 reload；可写明需 PM 批的命令顺序
3. 修默认语路径过滤回潮
4. UT + 升版 + 开发日志；channel 回报；**禁止自 server:reload**

## msg-25 | 2026-09-22T12:42:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=① Website 共享 snapshot 冷时，`resolveDeferredWarmupWebsiteDefaults` 误用 Env `lang=zh_Hans_CN` 冒充站默认，而 App 301/店面默认实为 `en_US` → `/en_US/` 槽回潮；`/`+`/products` 两次 warmup 的 FPC variant/key 分叉（body 长度漂移）→ probe 仍 MISS；`/ar_SA/` 路径语稳定故可 HIT。出站 `private, no-store` 在入池快照之后，不阻断二次 HIT。② `server:reload` 只滚 Worker，从不调用 `ManagedNginxService::reload()` → `|fpc2`/MISS→proxy_no_cache 源码有、运行 `nginx.conf` 无。
fix=① defaults 对齐 App（website.language / WELINE_WEBSITE_* / snapshot）+ `State::resolveWebsiteDefaultLanguage/Currency`；禁 Env `lang`；warmup 请求钉住 WELINE_WEBSITE_*；Accept-Encoding→gzip。② Index/Product provider 同权威。③ 等待模式 `server:reload` 成功后顺带 `ManagedNginxService::reload()`（写出+nginx -t/-s reload）；`-n` 提示另批 nginx:reload。
files=app/code/Weline/Framework/Runtime/WlsRuntime.php; app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeDeferredWarmupDefaultLocalePathContractTest.php; app/code/Weline/Framework/etc/module.php; app/code/Weline/Framework/doc/开发日志.md; app/code/Weline/Server/Console/Server/Reload.php; app/code/Weline/Server/Test/Unit/Console/Server/ServerReloadRefreshesManagedNginxContractTest.php; app/code/Weline/Server/etc/module.php; app/code/Weline/Server/doc/开发日志.md; app/code/Weline/Index/Api/View/ViewWarmupContributionProvider.php; app/code/Weline/Index/Test/Unit/Api/View/ViewWarmupContributionProviderTest.php; app/code/Weline/Index/etc/module.php; app/code/Weline/Index/doc/开发日志.md; app/code/Weline/Product/Api/View/ViewWarmupContributionProvider.php; app/code/Weline/Product/Test/Unit/Api/View/ViewWarmupContributionProviderTest.php; app/code/Weline/Product/etc/module.php; app/code/Weline/Product/doc/开发日志.md
tests=phpunit DeferredWarmupDefaultLocale + PublishMissNoStore + ServerReloadRefreshesManagedNginx + ManagedNginxStaticEdge + FailOpenDeferred + PublicOriginWarmup + Index/Product ViewWarmupContribution → OK (14 tests, 77 assertions)；本机反射 defaults=en_US/CNY，canonicalize(/en_US/)→/
version=Framework 2.5.147; Server 2.0.64; Index 1.0.4; Product 1.0.290
reload_needed=yes
pm_command_order=1) `php bin/w server:reload`（默认等待模式；成功后应打印「托管 Nginx 已重写并 reload」）2) 核验 `var/server/nginx/conf/nginx.conf` 含 `|fpc2` 与 `wls_edge_skip_fpc_miss_` 3) 若仅需重刷 Edge：`php bin/w server:nginx:reload` 4) 再唤醒性能复测 `/`+`/products` warmup adopted + 公网 HIT
setup_upgrade=skipped_not_required

## msg-28 | 2026-09-22T12:45:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=waiting_peer
verdict=fail（非正式最终；edge 未更新前不标 pass）
process_fpc=warm2_HIT
edge=blocked
review=meetings/性能检查-review.md「抽检基线（五次 reload · B4 部分 · nginx 未生效）」

### 抽检
- Worker 29401/29414；warmup paths **无 `/en_US/`**（默认语过滤 **pass**）
- warmup：**incomplete**（`/` probe 仍 MISS · cookies=0）；locale probe HIT；**无 adopted**；status 仍 fail-open
- Worker 直连 `Host=…:9555`→`:19655`：`/` 与 `/products` **#2 均 `X-Weline-Fpc: HIT`**（#1 冷 MISS）
- 公网仍 MISS+edge STALE；运行 conf **无 `|fpc2`**（与 msg-27 一致）→ **edge=blocked**

等后端续 nginx 证书绑定/写出生效；建议并行查 warmup `/` probe 与直连 warm2 不一致。本席禁 reload。

## msg-29 | 2026-09-22T12:46:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:relay

性能 msg-28：默认语过滤已过；直连 warm2 **HIT**；warmup `/` 仍 incomplete；edge 仍 blocked。

B4 续（已在编，不重复排班）须闭环：
1. nginx owner↔证书代次 → conf 写出 `|fpc2` 并生效
2. 并行：warmup `/` probe MISS vs 直连 #2 HIT 不一致（否则 adopt 仍挂）

禁自 reload；修完回报。

## msg-31 | 2026-09-22T12:47:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B4 续 **patched**（msg-30 · Server `2.0.65`）：owner 按公网域重绑 immutable cert gen。

项目经理已批并执行 `php bin/w server:nginx:reload`（必要时再 `server:reload`）。请正式复测：

1. conf 含 `|fpc2` + `wls_edge_skip_fpc_miss_`
2. warmup `/`+`/products` adopt；公网真实 FPC HIT（edge 可区分）
3. 更新 review；verdict pass|fail；fail escalate

## msg-28 | 2026-09-22T12:50:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=运行 `managed-nginx.owner.json` 已写 `certificate_generation_managed=false`（历史经 `app/etc/ssl` 可变源启动），字段存在后 `resolveOwnerCertificateGeneration` 对 `!== true` **硬抛**「not bound to an immutable certificate generation」→ `server:reload`/`server:nginx:reload` 无法 `write()`，|fpc2 永不落盘。本机 `ProjectCertificateGenerationStore::active(p05113ef3.test.weline.com)=gen=30` 且 leaf 指纹与 owner `ssl_certificate_sha256` 一致 → 属绑定缺口，非无证书。
fix=`managed!==true`（及缺字段）时按 server_names 重绑 active immutable generation；成功则 reload 写出 conf 并把 owner 升为 managed=true；无 active 时回退 legacy ssl（允许 Edge-only conf），不再永久卡死。进程 FPC/默认语（2.5.147）保留不动。
files=app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxService.php; app/code/Weline/Server/Test/Unit/Service/Edge/Nginx/ManagedNginxOwnerCertificateRebindContractTest.php; app/code/Weline/Server/etc/module.php; app/code/Weline/Server/doc/开发日志.md
tests=phpunit ManagedNginxOwnerCertificateRebind + ServerReloadRefreshesManagedNginx + ManagedNginxStaticEdge → OK (4 tests, 36 assertions)；本机反射 unmanaged owner → gen=30
version=Server 2.0.65
reload_needed=yes
reload_commands_for_pm=
1. `php bin/w server:nginx:reload`（优先；仅刷 Edge）**或** `php bin/w server:reload`（等待模式，顺带刷 Nginx）
2. 核验：`rg 'fpc2|wls_edge_skip_fpc_miss' var/server/nginx/conf/nginx.conf` 须命中
3. 核验 owner：`certificate_generation_managed=true` 且 `certificate_generation≥1`（`var/server/nginx/run/managed-nginx.owner.json`）
4. 再唤醒性能复测（公网 edge + 进程 FPC）
setup_upgrade=skipped_not_required

## msg-32 | 2026-09-22T12:48:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已按 msg-28/30 批 `php bin/w server:nginx:reload` → **仍失败**：
`unable to prove old nginx worker generation drain; last-known-good config and live generation restored`

核验仍坏：
- `nginx.conf` **无** `|fpc2`
- owner：`certificate_generation_managed=False` · `certificate_generation=0` · `certificate_domain=` 空

请继续：重绑须持久化；drain 证明失败不得永久吞掉新 conf。禁自 reload；回报可执行命令。

## msg-32 | 2026-09-22T12:51:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已批 `php bin/w server:nginx:reload` → **失败**：
`unable to prove old nginx worker generation drain; last-known-good config and live generation restored`

核验仍坏：
- `nginx.conf` **无** `|fpc2`
- owner：`certificate_generation_managed=False` · `certificate_generation=0` · `certificate_domain` 空

请再修：重绑须持久化到 owner；drain 证明失败不得吞掉新 conf。禁自 reload；给 PM 命令。

## msg-33 | 2026-09-22T13:22:00+08:00 | from:项目经理 | to:后端,性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

继续检查（PM 本机核验）：

**已改善**：owner `managed=true` gen=30；conf 含 `|fpc2` + MISS 禁存。

**当前失败窗（Worker 3343）**：
- warmup：`/` ready=true · fpc=MISS · cookies=0 → **incomplete `homepage-ready-but-receipt-missing`**（非 cookies）
- 即 sample 已 ready，但 `adoptDeferredHomepageWarmupProof` 对 `https://{host}/` 取不到 `resolveRootHomepageProcessReceipt`
- 公网 `/`+`/products`：出站 `Cache-Control: private, no-store` + `x-wls-fpc-status:MISS`（edge MISS）
- status 仍 hit=false/fail-open

**B5（后端）**：
1. 根治 receipt 缺失：warmup 写入的 root receipt 键与 adopt 查找的 `https://host:9555/` 必须一致（port/scheme/Host）
2. 确认二次请求进程 HIT；评估出站 no-store 是否导致公网永远见 MISS 头（HIT 路径应出可缓存/正确 HIT 头）
3. 禁假 HIT；禁自 reload；channel 回报

性能：B5 后复测。

## msg-34 | 2026-09-22T13:24:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=escalate
verdict=fail
home=HIT
products=MISS
review=meetings/性能检查-review.md「正式复审（msg-33 窗）」

### 证据
- 公网 cookieless：`/` **x-weline-fpc:HIT** + edge HIT（TTFB≈7ms）；`/products` **双次** `x-wls-fpc-status:MISS` + edge MISS（TTFB≈0.50–0.54s）
- warmup：10569 **incomplete homepage-ready-but-receipt-missing**；最新 11669 `/` probe HIT → **adopted**，但 errors 含 **`/products` probe MISS**
- `server:status` 仍 hit=false/fail-open（与 adopted 日志不一致）
- conf 已有 `|fpc2`（运行面改善）

@项目经理：请立刻组队解决

### options
A. B5 主线：`/products` 公网+warmup 双次 MISS  
B. receipt-missing 根治 + status meta 回写  
C. A 为主、B 为辅

### recommendation
C（以 products HIT 为主）

### suggested_seats
后端、项目经理、性能检查工程师（复测·禁自排）

## msg-35 | 2026-09-22T13:25:00+08:00 | from:项目经理 | to:后端 | thread:pm-arrange-wave1 | kind:assign

已接住性能检查 escalate（msg-34 · recommendation=C）。**B5 已在编**（勿重复排班）。

主线：公网+warmup `/products` 双次须 HIT。  
辅：receipt-missing 根治 + status 与 adopted 一致。  

对照：warmup 11669 已 adopted，但 `/products` probe 仍 MISS。禁自 reload。

## msg-37 | 2026-09-22T13:29:00+08:00 | from:项目经理 | to:性能检查工程师 | thread:pm-arrange-wave1 | kind:assign

后端 B5 **patched**（msg-36 · Framework `2.5.148`）：路径序 `/`→`/products`→locales；`/` HIT 后立刻 adopt；Shared hydrate receipt。

项目经理已批准并执行 **第六次** `server:reload`。请正式复测：

1. warmup `adopted`（无 receipt-missing）
2. 公网 `/` 与 `/products` 双次 HIT（尤其 `#2`）
3. status 与 adopted 一致（尽量）
4. 更新 review；verdict pass|fail；fail escalate

## msg-34 | 2026-09-22T13:35:00+08:00 | from:后端 | to:项目经理,架构师,性能检查工程师 | thread:pm-arrange-wave1 | kind:result

Team:后端:
result=patched
root_cause=① `/` probe 已 HIT（ready=true），但 adopt 等全部路径结束后才查 root receipt；后续多 MB locale SSR 挤掉 Process L1 → receipt 被删 → `homepage-ready-but-receipt-missing`。② `/products` 排在 locale 之后，warmup probe 常 MISS，公网冷窗易 #1/#2 皆 MISS；直连冷热仍为 MISS→HIT（进程可入池）。出站 no-store 仅冷 SSR，HIT 头正常。
fix=① deferred 路径序 `/`→`/products`→locales；② `/` probe HIT 后立刻 adopt；③ sample 记 full_uri，adopt 优先该 URI+host 候选；④ receipt Process miss 时 Shared hydrate 回填，不删 receipt。
files=app/code/Weline/Framework/Runtime/WlsRuntime.php; app/code/Weline/Framework/Router/FullPageCacheCoordinator.php; app/code/Weline/Framework/Test/Unit/Runtime/WlsRuntimeAdoptHomepageFpcMetaContractTest.php; app/code/Weline/Framework/Test/Unit/Router/FullPageCacheHomepageReceiptSharedRehydrateContractTest.php; app/code/Weline/Framework/etc/module.php; app/code/Weline/Framework/doc/开发日志.md
tests=phpunit AdoptHomepageFpcMeta + HomepageReceiptSharedRehydrate + DeferredWarmupDefaultLocale + PublishMissNoStore → OK (6 tests, 36 assertions)
version=Framework 2.5.148
reload_needed=yes（请项目经理批 `php bin/w server:reload`；**禁止本席自 reload**。期望：warmup `adopted`；paths 含 `/` 后紧接 `/products`；公网 `/products` #2 `x-weline-fpc:HIT`/`x-wls-fpc-status:HIT`；status homepage hit/adopted）
setup_upgrade=skipped_not_required

## msg-38 | 2026-09-22T13:30:00+08:00 | from:性能检查工程师 | to:项目经理,后端 | thread:pm-arrange-wave1 | kind:result

Team:性能检查工程师:
result=done
verdict=pass
home=HIT
products=HIT（#2）
adopted=yes
wave1_final=pass
review=meetings/性能检查-review.md「正式复审（六次 reload · B5 2.5.148）」

### 证据（Worker 22126）
- warmup paths=`/`,`/products`,locales；**adopted**（无 receipt-missing）；`/`+`/products` probe **HIT**
- 公网：`/` #1/#2 **HIT**；`/products` #1 冷 MISS · **#2 HIT**（`x-weline-fpc`+`x-wls-fpc-status`）
- status 仍 fail-open（与 adopted 不一致）→ **残留备注**，按 msg-37「尽量」不否决本波

R1 代码仍 pass。本席禁 reload。建议下一波修 status meta 回写。

## msg-39 | 2026-09-22T13:31:00+08:00 | from:项目经理 | to:全体 | thread:pm-arrange-wave1 | kind:closeout

已接住性能检查 **verdict=pass**（msg-38）。wave1 收口。

完成定义：R1 真批量；Host/cookies/默认语/nginx `|fpc2`；warmup early adopt；公网 `/` HIT；`/products` #2 HIT。

残留（不阻断）：`server:status` 仍可能 fail-open —— 下一波修 status meta。R4 放大器与主题非阻塞项可另排。
