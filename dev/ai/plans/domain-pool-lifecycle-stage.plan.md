# 域名池分阶段生命周期 — 优化方案（结合实际代码）

## 1. 现状与问题

### 1.1 域名池（DomainPool）并行状态过多

| 字段 | 用途 | 问题 |
|------|------|------|
| `resolve_status` | pending/resolving/resolved/error | 与「是否到源站」拆开，任务要组合判断 |
| `is_local_server` | 是否指向本机 | 与 resolved 组合才等价「可进证书」 |
| `dns_status` / `cdn_status` | NS/托管展示 | **与证书队列已解耦**（`getDomainsNeedCertificate` 已不再强制 READY），但 **`calculateSiteReady()` 仍要求二者 READY**（`DomainPool.php` 435–441），导致：证书已 valid、解析已 local，**site_ready 仍可能为 0** — 逻辑分裂 |
| `https_status` | 证书阶段 | 合理，但任务里还要再 AND resolve/is_local |
| `site_ready` | 可建站 | 应由单一阶段推导，现为多字段 AND |

### 1.2 定时任务与「阶段」的对应关系（当前）

| 任务 | 实际在做的阶段事 | 选取条件（简化） |
|------|------------------|------------------|
| `DomainPoolResolveCheck` | 解析检测、可选代写 DNS、**推进到「待证书」** | `site_ready=0` + 解析检测节流 |
| `DomainPoolCertificateRequest` | **仅证书** | resolved + is_local + https in (none,pending,error,expired) |
| `DomainPoolCertificateVerify` | 证书落盘校验、回退 | https=valid |
| `DomainNsCheck` | 根域 NS → **回写池子 dns/cdn_status** | 全根域 |

结论：**业务上本就是流水线**，但代码用多字段表达，任务用多条件 SELECT，**缺少「当前卡在哪一阶段」的唯一真源**。

---

## 2. 目标模型：单一阶段字段 + 附属事实字段

### 2.1 建议新增：`pool_lifecycle_stage`（varchar，索引）

**阶段枚举（仅顺序推进 + 明确回退规则）：**

| stage | 含义 | 允许处理的任务 |
|-------|------|----------------|
| `registered` | 已入池，尚未完成「源站可达」判定 | **仅** `DomainPoolResolveCheck`（解析检测、可代写 A/AAAA） |
| `awaiting_origin` | 已能解析到 IP，但**未**判定指向本机源站 | 同上（重试解析/代写）；**禁止**申请证书 |
| `origin_ready` | 已判定指向本机（权威或公网记录一致） | **可**标记 https pending；**仅** `DomainPoolCertificateRequest` 从此阶段取数申请证书 |
| `cert_pending` | 已排队/正在向 CA 申请 | **仅** 证书任务写状态 |
| `cert_valid` | 证书有效 | `DomainPoolCertificateVerify` 校验；可建站计算 |
| `site_live` | 已绑定站点（可选，可与 `site_created=1` 等价） | 一般不再跑解析/证书自动化 |
| `blocked` | 需人工或超过重试（解析或证书长期失败） | 仅告警/后台处理，定时任务默认跳过 |

**不回退到前一阶段**，除非业务明确（如证书过期 → `origin_ready` + https 重试）。

### 2.2 保留字段（事实数据，不替代阶段）

- `resolved_ip` / `resolved_ipv6`、`resolve_error`、`resolve_checked_at`
- `https_status`、`cert_id`、`https_expires_at`、`https_error`
- `site_created`（业务占用）
- `dns_provider`（展示）；`dns_status`/`cdn_status` 可逐步**降级为展示缓存**，不再参与 `site_ready` 核心逻辑

### 2.3 `site_ready` 与阶段对齐

推荐：

- **`site_ready = 1` ⇔ `pool_lifecycle_stage === cert_valid` 且 `site_created === 0`（可选再加 status=active）**
- 或保留 `calculateSiteReady()` 但 **去掉对 dns_status/cdn_status 的强制**，与证书队列、手动 DNS 场景一致。

---

## 3. 阶段转移规则（状态机）

```
registered
  → 解析检测：无 IP / 未 local → 保持 registered 或 awaiting_origin
  → 有 IP 且未 local → awaiting_origin
  → 有 IP 且 local → origin_ready（并 set https pending 若需证书）

awaiting_origin
  → local 成立 → origin_ready
  → 长期失败 → blocked（可选，带 resolve_error）

origin_ready
  → 证书任务开始 → cert_pending
  → 证书成功 → cert_valid
  → 证书失败可重试 → 仍 origin_ready 或 cert_pending

cert_valid
  → site_created=1 → site_live（或不改 stage，仅用 site_created 区分）

cert_valid + 校验失败（Verify 任务）→ https 回退 → origin_ready 或 registered
```

**约束：**

- `DomainPoolCertificateRequest` **只处理** `pool_lifecycle_stage in (origin_ready, cert_pending)` 且 https 非 valid。
- `DomainPoolResolveCheck` **只处理** `stage in (registered, awaiting_origin)` 以及 **cert_valid 时仅刷新 site_ready**（或拆成极薄逻辑）。

---

## 4. 分阶段落地（不改一次全库）

### 阶段 A：只加计算层（零迁移风险）

1. 新增 **`DomainPoolLifecycleService::getStage(DomainPool $p): string`**  
   - 用**现有字段**推导阶段（与上表一致），不写库。
2. Cron 内先 `getStage()`，**不满足阶段则 return**，逻辑集中，便于单测。
3. 修正 **`calculateSiteReady()`**：与 `getStage() === cert_valid` 或「resolved + is_local + https_valid」一致，**不再要求 dns/cdn READY**（与当前证书入队策略一致）。

### 阶段 B：持久化 `pool_lifecycle_stage`

1. DB：`pool_lifecycle_stage` varchar(32) default `registered`，索引。
2. `setup:upgrade` 回填：按 `getStage()` 批量 UPDATE。
3. 各任务在**成功推进**时写 stage（单一写入点：`DomainPoolLifecycleService::transition($pool, $to, $context)`）。

### 阶段 C：查询改按 stage

1. `getDomainsNeedResolveCheck` → `where stage in (registered, awaiting_origin)`（可保留时间节流）。
2. `getDomainsNeedCertificate` → `where stage in (origin_ready, cert_pending)` + https 条件可收紧。
3. 逐步废弃多字段组合 WHERE，文档标明 **stage 为准**。

---

## 5. 关键代码锚点（实施时改这些）

| 文件 | 动作 |
|------|------|
| `Model/DomainPool.php` | 新常量 + `pool_lifecycle_stage` 列；`calculateSiteReady()` 与阶段对齐 |
| 新建 `Service/DomainPoolLifecycleService.php` | `getStage()`、`transition()`、允许转移表 |
| `Cron/DomainPoolResolveCheck.php` | 入口校验 stage；推进时 `transition()` |
| `Cron/DomainPoolCertificateRequest.php` | 仅 origin_ready/cert_pending；写完证书后 `transition(cert_valid)` |
| `Cron/DomainPoolCertificateVerify.php` | 失败回退 stage + https |
| `Cron/DomainNsCheck.php` | 仍可写 dns/cdn_status，**不参与 stage 计算**（或仅影响展示） |
| `Service/DomainPoolResolveService.php` | checkResolve 末尾可选同步 stage |

---

## 6. 验收要点

- 任意池行：**阶段唯一**；列表页可按阶段筛选。
- 证书任务**绝不会**在 `registered/awaiting_origin` 上跑 CA。
- 手动 DNS、无 CDN 账户：**仍能** origin_ready → cert_valid → site_ready。
- 与现有 `site_created`、绑定站点流程兼容。

---

## 7. 小结

- **优化核心**：用 **`pool_lifecycle_stage` 表达「卡在哪一段」**，定时任务**按阶段订阅**，避免多字段 AND。
- **先服务层推导、再落库、再改查询**，风险可控。
- **立刻可做的最小修复**：调整 `calculateSiteReady()` 与证书/解析业务一致（去掉 dns/cdn 硬门槛），与阶段方案同向。

---

*文档版本：与当前仓库 Websites 模块代码一致；证书入队条件以 `DomainPool::getDomainsNeedCertificate` 为准。*

---

## 8. 已落地实现（摘要）

- 表字段：`pool_lifecycle_stage`（默认 `registered`），`Setup/Upgrade` 启动时 `backfillAllPoolStages()`。
- **解析任务** `DomainPoolResolveCheck`：仅 `registered`/`awaiting_origin`；结束只推进到 `origin_ready`，**不**写 `https pending`；另段刷新 `cert_valid` 的 `site_ready`。
- **证书任务** `DomainPoolCertificateRequest`：仅 `origin_ready`/`cert_pending`；开始时 `cert_pending`+https pending；成功 `cert_valid`，失败回 `origin_ready`。
- `calculateSiteReady()`：不再依赖 dns/cdn infra，仅 resolved + local + https valid + active。
- 部署后请执行模块升级以加列并回填阶段。
