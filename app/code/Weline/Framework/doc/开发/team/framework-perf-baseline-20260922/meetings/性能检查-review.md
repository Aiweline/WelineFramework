# 性能检查 — wave1 开发后复审（R1+R2+R3）

- seat: 性能检查工程师（独立子智能体）
- date: 2026-09-22
- team: `framework-perf-baseline-20260922`
- against: `meetings/性能检查-design.md` · `channel/pm-arrange-wave1.md` msg-2…37 · `meetings/主题-R3-design.md` · `surfaces.md`
- architect_joint: true（设计期已冻结；本波仅复审，不私改禁区）
- verdict: **pass**（六次 reload / B5 2.5.148：warmup adopted；公网 `/` HIT；`/products` **#2 HIT**；无 receipt-missing。status 展示仍滞后作残留备注）

---

## 业务特性摘要（复审口径）

| 项 | 结论 |
|----|------|
| 面 | 店面公共路径 `/` 与 `/products`；Framework CachePool / WLS deferred FPC；Theme 已发布布局结构 |
| Host | 公网验收 `https://p05113ef3.test.weline.com:9555`（禁回环 Host 当权威） |
| 个性化 | 草稿 / page target 禁入共享结构池；FPC 仅匿名公共页 |
| 完成定义对照 | R1 真批量非空壳；R2 fail-open⇒deferred 强制且 `/` 首槽、`max_paths≥6`、公网真实 HIT；R3 published layout HotCache + 草稿禁入 |

---

## 代码抽检

### R1 — `BatchCacheAdapterInterface` 真批量

| 检查 | 结果 | 证据 |
|------|------|------|
| 接口真实存在且非空壳 | **pass** | `Framework/Cache/Contract/BatchCacheAdapterInterface.php` 声明 `getMultiple` / `setMultiple` |
| `CachePool` 优先走批量 | **pass** | `CachePool.php` L323–347 / L372–378：`instanceof BatchCacheAdapterInterface` → 一次 Adapter 批量；否则逐键 fallback |
| Adapter 实现 | **pass** | `Server/Cache/Adapter/WlsMemoryAdapter` `implements BatchCacheAdapterInterface`；远程 `getCacheMultiple` / `setCacheMultiple`（`SharedCacheBatchStateInterface`） |
| UT | **pass** | `CachePoolBatchAdapterContractTest`：7 tests / 34 assertions OK |

对照 design：原「foreach 伪批量」缺口已在代码层关闭。运行时 WLS RPC 次数未做本机插桩计量（本波以契约 UT + 路径抽检为准）。

### R2 — fail-open ⇒ deferred 强制

| 检查 | 结果 | 证据 |
|------|------|------|
| fail-open 时强制 deferred | **pass（代码）** | `WlsRuntime::shouldRunDeferredStorefrontCriticalWarmup`：`isHomepageReadyGateFailOpen() \|\| !proof.hit` 时即使 `storefront_deferred_warmup_enabled` 默认 `0` 也调度 |
| `/` 首槽 | **pass（代码）** | `runDeferredStorefrontCriticalWarmup` 在 fail-open/未 HIT 时先 `$paths['/']='/'`，再合并 provider 路径 |
| `max_paths≥6` | **pass（代码）** | 默认 `wls.worker.storefront_deferred_warmup_max_paths=6`，slice `max(1,min(8,maxPaths))` |
| UT | **pass** | `WlsRuntimeFailOpenDeferredWarmupContractTest` + Theme 相关契约合计 6 tests / 29 assertions OK |
| **运行时完成定义** | **fail** | 见下节 |

### R3 — 已发布布局 HotCache / 草稿禁入

| 检查 | 结果 | 证据 |
|------|------|------|
| Policy | **pass** | `publishedLayoutStructurePolicy`：resource `theme.layout.published`，pool `weline_theme_published_layout_structure`，scope `channel`，`vary=[]`，deps `theme`，`staleTtl=0` |
| 逻辑键 | **pass** | `pub_layout|{area}|{theme}|{page_type}|{layout_option}|{scope}|{target_type}|{target_id}` |
| 草稿/page target 禁入 | **pass** | `$cacheablePublished = !$isDraft && !$hasTargetIdentity`；仅此时 `rememberPolicy` |
| Cleaner | **pass** | `ThemeRuntimeCacheCleaner` 含 `published_layout_structure_hot_cache` |
| 私有静态袋 | **接受（本波）** | 纪要声明 L1 短 TTL 保留；跨 Worker 走 Policy — 未判作平行袋越界 |
| 店面主链 HIT 率（B4） | **未测** | Entity 填槽本就不走 `getLayoutData`；无本机 structure HIT 率探针 → 不宣称 R3 冷瀑布已缩短 |

---

## 本机运行证据（只读 · 未 reload Worker）

实例：`default` · Master 13956 · Workers 2（14008/14009）· Started **2026-09-22 03:38:23**（早于本波 R1/R2 落码回报；本席按纪律**未**滚动重载）。

公网 Host：`https://p05113ef3.test.weline.com:9555` · `Cache-Control: no-cache`。

| 样本 | HTTP | TTFB | total | 字节 | FPC / edge |
|------|------|------|-------|------|------------|
| `/` #1 | 200 | 0.009s | 0.018s | 1,283,504 | `x-weline-fpc: HIT` · `x-wls-edge-cache: STALE` |
| `/` #2 | 200 | 0.008s | 0.016s | 1,283,504 | HIT · STALE |
| `/products` #1 | 200 | 0.009s | 0.025s | 2,135,209 | **`x-wls-fpc-status: MISS`** · STALE |
| `/products` #2 | 200 | 0.008s | 0.019s | 2,135,209 | **MISS** · STALE |

Worker 身份（`server:status` 只读）：

```text
Homepage warmup: state=warm, hit=false, ..., reason=homepage-fpc:deferred-after-ready:fail-open
（两 Worker 相同）
```

READY 日志（`var/log/wls-ready-gate-stage.log`，Worker 启动窗 03:38:27）：

- `runtime_homepage_fpc_deferred_before_ready reason=fail-open-default`
- `runtime_storefront_fpc_skipped`
- **未见** deferred critical warmup `begin` / paths 含 `/`+目录代表 / `adopted` 证明
- `var/log/wls-storefront-warmup.log` 本窗为空

解读（检查纪律）：

1. 暖 TTFB≈8–9ms **不能**写成「R2 已达标」：与 design 基线暖路径同量级，且 `/products` 仍明确 **FPC MISS**。
2. `/` 的 `x-weline-fpc: HIT` + edge `STALE` **不能**等同 Worker deferred adopt：身份仍 `hit=false` / `fail-open`，无 `homepage-fpc:deferred-warmup:adopted`。
3. 运行中 Worker **未加载**本波 R2 新码（启动早于施工）；在禁止本席自 reload 前提下，**无法**用冷首击验证「fail-open⇒deferred 强制」运行闭环。
4. **禁止**跨样本伪加速比；不与 03:43 design 冷≈2.3s 做倍数宣传。

---

## 与 design / msg-2 对照

| 冻结项 | 复审 |
|--------|------|
| R1 真批量 | 代码 **pass** |
| R2 保留 fail-open + deferred 硬耦合 | 代码 **pass**；运行完成定义 **fail** |
| R2 `/` 首槽 + max_paths≥6 | 代码 **pass**；运行无 paths 日志佐证 |
| R2 公网 Host 真实 HIT（含 `/products`） | **fail**（`/products` MISS；homepage proof 未 adopt） |
| R3 Policy + 草稿禁入 | 代码 **pass**；冷瀑布收益未证 |
| 禁假 HIT / 平行袋 / 跨模块直调 | 抽检未见本波新增越界 |

---

## 结论

**verdict=pass**（见文末「正式复审（六次 reload · B5 2.5.148）」· wave1 最终）

- R1：真批量 **pass**。
- R2/B2–B5：Host、cookies、adopt、公网 `/` HIT、`/products` **#2 HIT**、路径序 **pass**；无 receipt-missing。
- status 与 adopted 不一致 → **残留**（不否决本波）。
- R3：结构 HotCache 代码合规；冷瀑布体积不在本波关闭范围内。

---

## escalate 附件（给项目经理）

@项目经理：请立刻组队解决

### options（≥2）

A. **受控滚动 reload Worker（保留脏改）** → 等 deferred 完成 → 性能席复测 `/`+`/products` 冷/暖 + Worker proof `hit=true`/`adopted`。若仍 MISS → 后端修 warmup 路径贡献/Host authority。  
B. **后端立刻排查** `/products` FPC MISS + `runtime_storefront_fpc_skipped`：provider 路径是否含目录代表、owner Worker 选举、公网 Host 解析；修完再由 PM 安排 reload+复测。  
C. **并行**：A 验证 R2 运行闭环 + 主题/性能抽 Entity structure HIT（B4）；R4 仍下一波。

### recommendation

选 **C（以 A 为主门禁）**：先让运行加载 2.5.141 再谈数字；并行 B 若 reload 后仍 MISS。

### suggested_seats

- Team:项目经理:（组队 / 批准受控 reload）
- Team:后端:（R2 运行闭环；必要时路径/Host）
- Team:架构师:（若需收紧 Host/FPC authority 表态）
- Team:性能检查工程师:（reload 后复测；禁自排）

---

## reload 后基线（2026-09-22 · 非正式最终 pass）

- status: **waiting_peer**（后端 B：Host `:19655` / adopt 未修完）
- final_pass: **否** — 本波正式 review 仍 **fail**，待 B 闭环后再复测
- reload: 项目经理已执行 `php bin/w server:reload`（msg-9）；本席禁再次 reload
- Workers: PID **60470** / **60547**（Port 19655）；Master 仍 13956

### server:status Homepage warmup

两 Worker 相同：

```text
state=warm, hit=false, source=, status=0, fpc=, reason=homepage-fpc:deferred-after-ready:fail-open
```

→ deferred 已触发编排，但 **proof 未 adopt**（与 msg-9 一致）。

### 公网探针（禁缓存头）

Host：`https://p05113ef3.test.weline.com:9555`  
Headers：`Cache-Control: no-cache` · `Pragma: no-cache`

| 样本 | HTTP | TTFB | total | 字节 | FPC / edge |
|------|------|------|-------|------|------------|
| `/` #1 | 200 | **0.041s** | 0.053s | 1,283,504 | `x-weline-fpc: HIT` · `x-wls-edge-cache: STALE` |
| `/` #2 | 200 | **0.015s** | 0.034s | 1,283,504 | HIT · `UPDATING` |
| `/products` #1 | 200 | **0.150s** | 0.229s | 2,135,209 | **`x-wls-fpc-status: MISS`** · STALE |
| `/products` #2 | 200 | **0.031s** | 0.059s | 2,135,209 | **MISS** · UPDATING |

纪律：`/` 公网 HIT + edge STALE/UPDATING **≠** Worker `hit=true`/`adopted`；`/products` 公网仍 MISS。禁止伪加速比。

### `var/log/wls-storefront-warmup.log`（reload 后完整 2 行）

1. **begin**（pid 60470，worker_id=1）：  
   `paths=["/","/en_US","/ar_SA","/products","/en_US/products","/ar_SA/products"]`（`/` 首槽、`max_paths=6` OK）  
   **`hosts=["p05113ef3.test.weline.com:19655"]`** ← 内部 Worker 口，非验收 `:9555`（B 主因）

2. **failed**（elapsed≈34.9s，`warmed=2` `failed=4`）：  
   - `/`：200 · body≈1.29MB · **cookies=4** · `fpc_status=MISS` · `ready=false`  
   - `/en_US`、`/en_US/products`：301 · ready=false  
   - `/ar_SA`：200 · cookies=4 · MISS · ready=false  
   - `/products`：内部探针二次 `fpc_status=HIT` · `ready=true`（仅 `:19655` 键域；**公网 `:9555` 探针仍 MISS**）  
   - 无 `done` / `homepage-fpc:deferred-warmup:adopted`

### 基线解读（给后端 B）

| 项 | 基线结论 |
|----|----------|
| 路径集合 | pass（含 `/`+`/products`+locale 变体） |
| Host authority | **fail**（warmup 写 `:19655`；公网 `:9555` `/products` 未 HIT） |
| adopt / Worker proof | **fail**（仍 fail-open · hit=false） |
| 公网 `/` HIT | 存在 edge/FPC HIT，但不得冒充 deferred adopt 闭环 |

下一动作：等后端 B 回报 → 项目经理安排（必要时再 reload）→ 本席正式复测；本席 **waiting_peer**，禁自排施工。

---

## 正式复审（二次 reload · Host B 后 · 2026-09-22）

- trigger: channel msg-13/14；后端 B Host patched（Framework `2.5.143`）；PM 第二次 `server:reload`（Worker **81503/81568**）
- 本席：**禁 reload**；只读探针 + 日志
- **verdict: fail**（完成定义未全过）

### 分项对照（msg-13 完成定义）

| 项 | 结果 | 证据 |
|----|------|------|
| warmup hosts = 公网 `:9555`（非 `:19655`） | **pass** | `wls-storefront-warmup.log` pid **81503** begin：`hosts=["p05113ef3.test.weline.com:9555"]`（相对前基线 Host 错位已关） |
| Worker proof `hit=true` / `deferred-warmup:adopted` | **fail** | 81503：`stage=failed` → `stage=incomplete` `reason=homepage-not-ready`；**无** `adopted`；homepage_sample **`set_cookie_count=4`** · fpc MISS · ready=false |
| 公网 `/` + `/products` 真实 FPC HIT | **fail** | 见下表：`/` 可 HIT；**`/products` 仍 MISS** |

### warmup（81503 · PM 二次 reload 权威窗）

- begin：paths=`/`,`/en_US/`,`/ar_SA/`,`/products`,… · **hosts=`…:9555`** · max_paths=6
- failed：warmed=2 failed=4 · elapsed≈65.3s  
  - `/`：200 · **cookies=4** · fpc MISS · ready=false  
  - `/en_US/`、`/en_US/products`：301  
  - `/ar_SA/`：cookies=4 · MISS · ready=false  
  - `/products`：内部二次 probe 可 ready:HIT（warmup Host 域）；**不替代**公网探针 HIT
- incomplete：`homepage-not-ready`（homepage_sample cookies=4）→ **阻断 adopt**

### 公网探针（禁缓存头 · `https://p05113ef3.test.weline.com:9555`）

本席复审窗曾遇他席 `server:stop/start`（Master/Worker 短暂 All Stopped；本席未启停）。恢复后采证：

| 样本 | HTTP | TTFB | FPC / edge |
|------|------|------|------------|
| `/` #1 | 200 | 0.012s | `x-weline-fpc: HIT` · edge UPDATING |
| `/` #2 | 200 | 0.008s | HIT · UPDATING |
| `/` 复核 | 200 | 0.129s | HIT · UPDATING |
| `/products` #1 | 200 | 0.025s | **`x-wls-fpc-status: MISS`** · STALE |
| `/products` #2 | 200 | 0.015s | **MISS** · UPDATING |
| `/products` 复核 | 200 | 0.010s | **MISS** · STALE |

纪律：公网 `/` HIT **≠** 81503 deferred adopt 闭环（该窗 incomplete）；`/products` 持续 MISS → 完成定义失败。

### 附注（他席 full start · pid 89407 · 非本席动作）

同日志稍后出现 begin→failed→**`adopted`**（`homepage-fpc:deferred-warmup:adopted`）：`/` 已 cookies=0 · fpc HIT（非冷 cookies=4 路径）。**不得**据此宣称 B2 已关——`/ar_SA/` 仍 cookies=4；locale 仍 301；公网 `/products` 仍 MISS；与 PM 指定二次 reload（81503）权威失败窗不一致。

### 结论

| 维度 | 结果 |
|------|------|
| Host 权威（相对前基线） | **pass** |
| adopt / 运行闭环 | **fail**（根因：**`/` Set-Cookie×4 → FPC 拒收 → homepage-not-ready**） |
| 公网 `/products` HIT | **fail** |
| R1 / R3 代码（前节） | 仍 pass（本波未回退） |
| **总 verdict** | **fail** |

本席不自排施工 → escalate 后端继续 **B2**（cookies 拒收根治 + locale 301 槽浪费；禁假 HIT）。

---

## escalate（正式复审后）

@项目经理：请立刻组队解决

### options（≥2）

A. **后端 B2**：根治首页预热 `Set-Cookie×4` 导致 FPC 拒收（Session/访客 cookie 策略与预热匿名语义对齐）；修完由 PM 批 reload → 性能复测 adopt。  
B. **并行**：收敛 locale `/en_US/` 301 槽浪费；查公网 `/products` 持续 MISS（与内部 probe HIT 不一致）。  
C. A 为主门禁；B 为辅；R4/B4 仍下一波。

### recommendation

**C（以 A/B2 cookies 为主）**

### suggested_seats

- Team:后端:（B2 cookies + locale/products）
- Team:项目经理:（组队 / 批 reload）
- Team:性能检查工程师:（修后再正式复测；禁自排）

---

## 正式复审（三次 reload · B2 2.5.145 · 2026-09-22）

- trigger: channel msg-17/18；PM 第三次 `server:reload`；Worker **59632** / **59707**
- 本席：禁 reload / 禁自排
- **verdict: fail**（完成定义第 3 条未过）

### 分项对照

| # | 完成定义 | 结果 | 证据 |
|---|----------|------|------|
| 1 | hosts=`:9555` | **pass** | 59632 begin `hosts=["p05113ef3.test.weline.com:9555"]` |
| 2 | `/` cookies 不阻断；adopt 真实 | **pass（运行）** / **status 展示滞后** | 见下 |
| 3 | 公网 `/` 与 `/products` FPC HIT | **fail** | `/` HIT；**`/products` MISS** |
| 4 | locale 不再占 301 失败槽 | **pass（本窗）** | paths 无 `/en_US/`；`done` warmed=6 failed=0 |

### warmup（pid 59632 · 权威窗）

- begin paths=`["/","/ar_SA/","/bn_BD/","/es_ES/","/fr_FR/","/products"]` · max_paths=6  
  → **`/products` 仍在槽内**（非被挤掉）；默认语 301 槽已换成非默认 locale 家页
- **done**：warmed=**6** failed=**0** · elapsed≈14.6s  
  - `/`：cookies=**0** · set_cookie_names=[] · probe HIT · ready=true  
  - locale 家页同形 cookies=0 ready（日志 samples 截断前 4 条；warmed=6 含其余路径）
- **adopted**：`reason=homepage-fpc:deferred-warmup:adopted` · `full_uri=https://p05113ef3.test.weline.com:9555/`

### `server:status` hit=false 判定

两 Worker 仍显示：

```text
state=warm, hit=false, ..., reason=homepage-fpc:deferred-after-ready:fail-open
```

**结论：展示滞后 / 身份未回写 Master，不是「adopt 日志造假」。**

依据：

1. `adoptDeferredHomepageWarmupProof` 仅写 Worker 进程内 `$readyGateHomepageFpcProof`（hit=true / reason=adopted），并打 warmup `stage=adopted`。
2. `server:status` 读的是 READY 时 IPC 落库的 `homepage_fpc` meta（fail-open 快照）；adopt **未**再发 `worker_ready` / 更新 Master meta。
3. 故 CLI 可长期停在 fail-open，同时进程内 proof + FPC 已 HIT。

→ 记为 **B3 辅线**：adopt 后应回写 `homepage_fpc`（或 status 另读 live proof）；**不**单独否决「adopt 真实」。

### 公网探针（禁缓存头）

Host：`https://p05113ef3.test.weline.com:9555`

| 样本 | HTTP | TTFB | FPC / edge |
|------|------|------|------------|
| `/` #1 | 200 | 0.030s | `x-weline-fpc: HIT` · STALE |
| `/` #2 | 200 | 0.004s | HIT · UPDATING |
| `/products` #1 | 200 | 0.007s | **`x-wls-fpc-status: MISS`** · STALE |
| `/products` #2 | 200 | 0.004s | **MISS** · UPDATING |
| `/products/` | 200 | 0.718s | **MISS** |
| `/zh_Hans_CN/products` | 200 | 0.005s | **MISS** |
| `/ar_SA/products` | 200 | 1.884s | **MISS** |

### 总评

| 维度 | 结果 |
|------|------|
| Host 权威 | **pass** |
| B2 cookies / `/` adopt | **pass**（日志+公网 `/` HIT；status 展示另议） |
| 公网 `/products` HIT | **fail**（主否决） |
| **verdict** | **fail** |

---

## escalate（三次 reload 后）

@项目经理：请立刻组队解决

### options（≥2）

A. **后端 B3**：查公网 `/products` 持续 MISS（warmup 含 `/products` 且内部可 ready，但公网头 MISS——键/vary/locale/尾斜杠/边缘 vs Process 错位）。  
B. **辅**：adopt 后回写 Master `homepage_fpc`，消除 status `hit=false`/`fail-open` 假阴性。  
C. A 为主门禁；B 为辅；R4 仍下一波。

### recommendation

**C（以 A/`/products` 公网 HIT 为主）**

### suggested_seats

- Team:后端:（B3 products HIT + 可选 status 回写）
- Team:项目经理:（组队 / 批 reload）
- Team:性能检查工程师:（修后再复测；禁自排）

---

## 正式复审（四次 reload · B3 2.5.146 / Server 2.0.63 · 2026-09-22）

- trigger: channel msg-21/22；PM 第四次 `server:reload`；Worker **98447** / **98478**
- 本席：禁 reload / 禁自排；**不**把 edge STALE 当 pass
- **wave1 最终 verdict: fail**

### 1) warmup（pid 98447 · 完整 samples）

| 项 | 结果 |
|----|------|
| hosts | **pass** `…:9555` |
| paths | `["/","/en_US/","/ar_SA/","/products","/en_US/products","/ar_SA/products"]` → **默认语 301 槽回潮**（相对 B2 成功窗回归） |
| stage | **failed** warmed=2 failed=4 → **incomplete** `homepage-not-ready` · **无 adopted** |

Samples 全表：

| path | status | cookies | first fpc | probe fpc | ready | reason |
|------|--------|---------|-----------|-----------|-------|--------|
| `/` | 200 | **0** | MISS | **MISS** | **false** | `probe fpc=MISS body=1293624` |
| `/en_US/` | 301 | 0 | — | — | false | 301 |
| `/ar_SA/` | 200 | 0 | MISS | HIT | **true** | ready:fpc-hit |
| `/products` | 200 | 0 | MISS | **MISS** | **false** | `probe fpc=MISS body=2000127` |

（`/en_US/products` 在 errors 中 301，未进截断 samples。）

### 2) 公网探针 vs 进程 FPC

Host：`https://p05113ef3.test.weline.com:9555` · cookieless · no-cache

| 路径 | HTTP | TTFB | 进程/FPC 头 | edge |
|------|------|------|-------------|------|
| `/` #1 | 200 | 0.025s | **`x-wls-fpc-status: MISS`**（无 `x-weline-fpc:HIT`） | **STALE** |
| `/` #2 | 200 | 0.004s | MISS | UPDATING |
| `/products` #1/#2 | 200 | ≈5ms | **MISS** | STALE/UPDATING |
| `/products/` | 200 | ≈5ms | MISS | STALE |
| `/zh_Hans_CN/products` | 200 | ≈5ms | MISS | STALE |
| `/ar_SA/products` | 200 | ≈5ms | MISS | STALE |

**禁把毒 STALE 当 pass**：edge 在供 STALE，同时 FPC 头仍 MISS。

Worker 直连诊断（`Host=…:9555` → `http://127.0.0.1:19655`，绕开 edge）：

| 路径 | TTFB | 头 |
|------|------|-----|
| `/` | **2.82s** | `X-Wls-Fpc-Status: MISS` · `Cache-Control: private, no-store…` |
| `/products` | **1.03s** | 同上 MISS · private no-store |

→ **进程 FPC 本身未 HIT**（非仅 edge 毒缓存）。B3「Worker 直连已 HIT」在本窗 **未复现**。

### 3) `server:status` Homepage warmup

两 Worker：`hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`  
与 incomplete（无 adopt）一致 → **本窗不是「展示滞后假阴性」**，而是 adopt **未发生**。

### 4) B3 nginx 落盘核验（严重）

`ManagedNginxConfigWriter.php` 源码含 `|fpc2` 与 MISS→`proxy_no_cache` map；  
**运行中** `var/server/nginx/conf/nginx.conf` 仍为：

- `proxy_cache_key "$scheme$request_method$host$request_uri"`（**无 `|fpc2`**）
- `proxy_no_cache` 仅 `$wls_edge_bypass`（cookie/auth/upgrade）——**无**按 upstream FPC MISS 禁存

→ 第四次 reload **未**把 B3 nginx 改写入生效配置（或写入路径/重载链路缺口）。

### 5) R1

`CachePool` 仍 `instanceof BatchCacheAdapterInterface` → **pass**（本波未回退）。

### 总评

| 维度 | 结果 |
|------|------|
| Host :9555 | pass |
| warmup done/adopted | **fail**（incomplete） |
| 公网 `/`+`/products` 真实 FPC HIT | **fail** |
| 进程 FPC HIT | **fail** |
| status hit/adopted | **fail** |
| B3 nginx `|fpc2` 生效 | **fail**（未落盘） |
| R1 代码 | pass |
| **wave1 verdict** | **fail** |

---

## escalate（四次 reload / B3 后）

@项目经理：请立刻组队解决

### options（≥2）

A. **后端 B4**：确认 ManagedNginx 配置 **实际写出并 nginx -s reload**（`|fpc2` + MISS 禁存须出现在运行 conf）；修进程 FPC 首暖后 probe 仍 MISS（`|fpc2` 键 / publish / private no-store 出站是否误伤入池）。  
B. **并行**：默认语过滤回潮（`/en_US/` 再进槽）——核对 deferred 时 Website default snapshot；Index/Product provider 硬编码 fallback。  
C. A 为主门禁；B 为辅；清毒 edge 缓存仅在 conf 生效后由 PM 批操作。

### recommendation

**C（以 A/nginx 落盘 + 进程 HIT 为主）**

### suggested_seats

- Team:后端:（B4 nginx 生效 + 进程 FPC）
- Team:项目经理:（组队 / 批 reload·必要时清 edge）
- Team:性能检查工程师:（复测；禁自排）

---

## 抽检基线（五次 reload · B4 部分 · nginx 未生效 · 2026-09-22）

- trigger: channel msg-25/26/27；PM 第五次 `server:reload`：**Worker 新码成功**；托管 Nginx **刷新失败**（证书代次绑定）
- 本席：禁 reload；**非正式最终 pass**；公网 edge **不得**作 pass 依据
- status: **waiting_peer**（等后端续 nginx B4）
- Workers: **29401** / **29414**

### 1) warmup（pid 29401）

| 项 | 结果 |
|----|------|
| hosts | **pass** `…:9555` |
| 默认语过滤 | **pass** paths=`["/","/ar_SA/","/bn_BD/","/es_ES/","/fr_FR/","/products"]`（**无** `/en_US/`） |
| done/adopted | **fail** → `failed` warmed=5 failed=1 → **incomplete** `homepage-not-ready` · **无 adopted** |
| `/` | cookies=0 · first MISS · **probe MISS** · ready=false（body≈1301497） |
| locale 家页 | probe **HIT** · ready=true（samples 前几条） |
| `/products` | errors 仅列 `/` 失败；warmed=5 → **推断 `/products` 已计入 warmed**（日志 samples 截断未印出） |

### 2) 进程 FPC（Worker 直连 · 权威诊断）

`Host: p05113ef3.test.weline.com:9555` → `http://127.0.0.1:19655`

| 路径 | #1 | #2（紧邻） |
|------|----|------------|
| `/` | MISS · TTFB≈3.79s · `private, no-store` · 1.30MB | **`X-Weline-Fpc: HIT`** · TTFB≈12ms |
| `/products` | MISS · TTFB≈1.31s · 2.06MB | **`X-Weline-Fpc: HIT` + `X-Wls-Fpc-Status: HIT`** · TTFB≈44ms |

→ **process_fpc=warm2_HIT**（二次可真 HIT）；warmup 窗 `/` 二次 probe 仍 MISS → adopt 未闭环（与直连 warm2 改善并存）。

### 3) 公网（edge=blocked · 仅旁证）

| 路径 | 头 |
|------|-----|
| `/` | `x-wls-fpc-status:MISS` + **edge STALE** |
| `/products` | MISS + **STALE** |

运行 `nginx.conf` 仍无 `|fpc2` / `wls_edge_skip_fpc_miss_`（与 msg-27 一致）。**禁止**据此标 pass。

### 4) `server:status`

仍 `hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`（与 incomplete 一致）。

### 口径

| 维度 | 本窗 |
|------|------|
| 默认语过滤 | pass |
| 进程 FPC（直连 warm2） | **pass（二次 HIT）** |
| warmup `/` adopt | **fail** |
| edge / 公网 | **blocked**（nginx 未更新） |
| 非正式最终 | **waiting_peer** |

下一动作：等后端解开 Nginx owner↔证书代次绑定并写出 `|fpc2`；建议并行修 warmup `/` probe 仍 MISS；PM 批生效后本席再正式复测。

---

## 正式复审（msg-33 窗 · 2026-09-22）

- Workers: **11669** / **13254**；nginx conf **已含** `|fpc2` + MISS 禁存（运行面改善）
- 本席：禁 reload
- **verdict: fail**（完成定义要求公网 `/` **与** `/products` 真实 FPC HIT）

### 公网双探针（禁缓存 · cookieless）

Host：`https://p05113ef3.test.weline.com:9555`

| 样本 | HTTP | TTFB | size | FPC / edge |
|------|------|------|------|------------|
| `/` #1 | 200 | 0.008s | 625,816 | **`x-weline-fpc: HIT`** · edge **HIT** |
| `/` #2 | 200 | 0.007s | 625,816 | **HIT** · edge HIT |
| `/products` #1 | 200 | **0.505s** | 2,053,334 | **`x-wls-fpc-status: MISS`** · edge **MISS** |
| `/products` #2 | 200 | **0.544s** | 2,053,334 | **MISS** · edge MISS |

→ **home=HIT**；**products=MISS**（双次冷量级，非毒 STALE 假象）。

### warmup

| pid | stage | 要点 |
|-----|-------|------|
| **10569**（前窗） | failed → **incomplete `homepage-ready-but-receipt-missing`** | 与 PM msg-33 观测一致（sample ready 但 receipt 缺失） |
| **11669**（最新） | begin paths 无 `/en_US/` · hosts=:9555 → failed warmed=3 failed=3 → **`adopted`** `homepage-fpc:deferred-warmup:adopted` | `/` probe **HIT** ready=true；**`/products` probe MISS**（errors）；locale 部分 MISS |

### `server:status`

两 Worker 仍：`hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`  
（与 11669 日志 `adopted` **不一致** → 回写 Master meta 仍可能缺口，作辅线。）

### 总评

| 项 | 结果 |
|----|------|
| 公网 `/` HIT | **pass** |
| 公网 `/products` HIT | **fail**（主否决） |
| adopt（最新日志） | pass（11669）；前窗 receipt-missing 仍需 B5 根治防回归 |
| status 身份 | **fail**/滞后 |
| **wave1** | **fail** |

---

## escalate（msg-33 复测后）

@项目经理：请立刻组队解决

### options（≥2）

A. **后端 B5 主线**：公网/warmup `/products` 双次 MISS（键/vary/variant 钉死、publish、与 `/` 不对称）。  
B. **并行**：`homepage-ready-but-receipt-missing` 根治（warmup 写入 vs adopt 查找 root receipt 一致）；adopt 后 status meta 回写。  
C. A 为主门禁；B 为辅。

### recommendation

**C（以 `/products` HIT 为主）**

### suggested_seats

- Team:后端:（products + receipt）
- Team:项目经理:（组队）
- Team:性能检查工程师:（复测；禁自排）

---

## 正式复审（六次 reload · B5 2.5.148 · 2026-09-22）· wave1 最终

- trigger: channel msg-36/37；PM 第六次 `server:reload`（含「托管 Nginx 已重写并 reload」）
- Workers: **22126** / **22155**
- 本席：禁 reload
- **wave1 最终 verdict: pass**

### 1) warmup（pid 22126）

| 项 | 结果 |
|----|------|
| hosts | **pass** `…:9555` |
| paths 序 | **pass** `["/","/products","/ar_SA/","/bn_BD/","/es_ES/","/fr_FR/"]`（`/`→`/products`→locales） |
| receipt-missing | **无** |
| adopted | **pass** · `stage=adopted` reason=`homepage-fpc:deferred-warmup:adopted` · `full_uri=https://p05113ef3.test.weline.com:9555/`（先于后续 locale failed 完成） |
| `/` probe | HIT · ready=true · cookies=0 |
| `/products` probe | **HIT** · ready=true · cookies=0 |
| 后续 | failed warmed=4 failed=2（locale `/ar_SA/`、`/es_ES/` probe MISS）— **不否决** 主路径完成定义 |

### 2) 公网双探针（禁缓存 · cookieless）

Host：`https://p05113ef3.test.weline.com:9555`

| 样本 | HTTP | TTFB | size | FPC / edge |
|------|------|------|------|------------|
| `/` #1 | 200 | 0.021s | 1,295,043 | **`x-weline-fpc: HIT`** · edge STALE |
| `/` #2 | 200 | 0.004s | 1,295,043 | **HIT** · UPDATING |
| `/products` #1 | 200 | 1.319s | 2,060,085 | MISS · edge MISS · `private, no-store`（冷 SSR 预期） |
| `/products` #2 | 200 | **0.047s** | 2,060,091 | **`x-weline-fpc: HIT` + `x-wls-fpc-status: HIT`** · edge MISS |

→ **home=HIT**；**products=#2 HIT**（完成定义「尤其 #2」满足；#1 冷 MISS 允许）。

### 3) `server:status` Homepage

仍：`hit=false` · `reason=homepage-fpc:deferred-after-ready:fail-open`  
与日志 `adopted` **不一致** → **残留（尽量项未达标）**，**不单独否决** wave1（msg-37「尽量」）。建议下一波回写 meta。

### 4) 对照 R1

`CachePool`→`BatchCacheAdapterInterface` 仍在 → **pass**。

### 总评

| 完成定义 | 结果 |
|----------|------|
| Host :9555 | pass |
| `/` cookies 不阻断 + adopt 真实 | pass |
| 无 receipt-missing | pass |
| 公网 `/` HIT | pass |
| 公网 `/products` #2 HIT | pass |
| paths `/`→`/products` | pass |
| status=adopted（尽量） | **残留 fail**（已由 wave2 B6 关闭，见下节） |
| R1 真批量 | pass |
| **wave1** | **pass** |

---

## wave2 B6 抽检（status overlay · 2026-09-22）

- channel: `channel/pm-arrange-wave2-status.md` msg-2/3/4
- Server **2.0.66**；PM `server:reload` 后本席只读抽检
- **verdict: pass**

| 项 | 结果 |
|----|------|
| Worker#1（warmup owner 62830） | **pass** `state=hot, hit=true, source=process, fpc=HIT, reason=homepage-fpc:deferred-warmup:adopted` |
| Worker#2（62858） | **pass/expected** `fail-open`（非 deferred storefront warmup owner；禁为对齐而假 HIT） |
| warmup | adopted + done warmed=6 failed=0；`/`+`/products` probe HIT；paths `/`→`/products`→locales |
| 公网 `/` #1/#2 | **HIT** |
| 公网 `/products` #1/#2 | **HIT** |

→ wave1「status 与 adopted 不一致」残留 **关闭**（owner Worker）；peer Worker fail-open **接受为预期**。
