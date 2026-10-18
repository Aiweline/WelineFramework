# Smtp 本机域名健康（列表双徽章 / fail-closed 门禁 / 同步跳过）

status: implemented（P6 UC×4 pass · 待 PM 汇审）  
module: Weline_Smtp（UI 面 Weline_Mail 企业邮局 Smtp Tab）  
plan_id: main  
updated: 2026-10-18  
SESSION: `dev/session/smtp-local-domain-health.md`  
team: `dev/team/smtp-local-domain-health/`  
issuer_seat: Team:需求分析:  
doc_owner: Team:文档工程师:

---

## 1. 问题陈述（已修）

### 生产举证

在 **aiweline.com** 企业邮局 → Smtp 列表中，卡片「本机 Smtp · mail.changanhanfu.com」曾显示绿标 **「本机启用」**（并 `active`）。  
该主机 **并不在当前 weline 服务器** 上 → 绿标被理解为「本机邮局已真实接管」→ **假阳性健康态**。

### 根因（已钉）

| 概念 | 旧行为 | 本波语义 |
|------|--------|----------|
| `MODE_SELF` | 直接渲染 success「本机启用」 | **登记意图**：「按本机启用路径登记」；文案 **「本机登记」**，tone=`info` |
| 本机健康 | 无独立信号 | **健康徽章**消费 TLS/池四态（`ready` / `needs_dns` / `needs_cert` / `error`） |
| 新建/启用/同步 | DNS 过即可落库；import 盲建 | **同一健康源** fail-closed；import **跳过**非本机 |

---

## 2. 目标与非目标（冻结 · 已落地）

### 目标

1. **列表双徽章**：模式徽章（本机登记 / 外部填入）与健康徽章分离；`MODE_SELF` 不得再以单一 success 绿标冒充健康。  
2. **新建/启用 fail-closed（G1）**：`!is_local` 或 `status=needs_dns` → `block_create=true`，拒绝落库/启用。  
3. **同步导入跳过（G3）**：`importSelfServicesFromMailDomains` 对非本机 hostname skip，禁止盲建假绿 `MODE_SELF`。  
4. **跨模块契约**：健康真相只经 `w_query('mail', getSmtpTlsStatus|getSmtpTlsStatusBatch)`；禁止 Smtp/Mail 业务层直调 DomainPool Model / TLS 同步服务类。

### 非目标（本波未做）

- 不删历史脏 `MODE_SELF` 行（G4：列表 warning 即可）。  
- 不改 `PublicDnsHostnamePoolEnsureService` 入池硬标 `is_local_server=true`（另债）。  
- 不改 Stalwart 证书导入算法本体。  
- 不手改 `generated/**`。

---

## 3. 实现架构（as-built）

### architecture_review

```text
mechanism: Service + Query
  - Service: Weline\Smtp\Service\SmtpLocalDomainHealthService
  - Query: mail.getSmtpTlsStatus（单主机）+ mail.getSmtpTlsStatusBatch（列表批量）
  - 既有启用链路: websites.ensurePublicDnsHostnamesInPool / mail.syncSmtpTlsFromDomainPool
owning_module: Weline_Smtp
  - 健康聚合、createSelfService / enable / import 门禁归属 Smtp
  - Weline_Mail：企业邮局 UI 只消费 Controller assign；TLS 真相仍由 Mail::StalwartTlsCertificateSyncService 经 mail.* Query 对外
not_to_do:
  1. 禁止仅用 MODE_SELF / 单一绿标「本机启用」表示本机已健康接管
  2. 禁止模板内探测 DomainPool / DNS / Stalwart
  3. 禁止 Smtp/Mail 业务层 new/load DomainPool 判 IS_LOCAL_SERVER
  4. 禁止 WlsRuntime/编排层硬编码站名特例
  5. 禁止列表 N 服务无去重串行打满（须 batch + 请求内 memo）
  6. 禁止手改 generated/**
```

### 3.1 `SmtpLocalDomainHealthService`

路径：`app/code/Weline/Smtp/Service/SmtpLocalDomainHealthService.php`

| API | 用途 |
|-----|------|
| `summarizeForServices(iterable)` | 列表：对 `MODE_SELF` 主机去重后 `prefetchBatch`，再按服务 id 出袋 |
| `summarizeFromService(SmtpService)` | 单服务 |
| `summarizePreview(mode, hostname)` | 新建/启用/导入预检（`smtp_service_id=0`） |

**健康袋 `SmtpLocalHealthSummary`（冻结形状）**：

| 字段 | 说明 |
|------|------|
| `smtp_service_id` | 列表有 id；预检为 `0` |
| `mode` | `self` / `external` |
| `hostname` | 规范化小写 |
| `mode_label_key` | `本机登记` / `外部填入` |
| `mode_tone` | `info` / `primary` |
| `health_applicable` | 仅 `self` 为 true |
| `status` | `ready` / `needs_dns` / `needs_cert` / `error` / `n/a` |
| `is_local` | 池本机性（来自 TLS Query） |
| `pool_https` / `message` | 透传 |
| `tone` | `success` / `warning` / `danger` / `muted` |
| `label` | 证书已就绪 / DNS 未指向本机 / 证书需要配置 / 证书同步失败 |
| `block_create` | **G1**：`!$is_local \|\| status===needs_dns` → true |
| `source` | `batch` 或 `mail.getSmtpTlsStatus` |

**健康源**：只经 `w_query('mail', …)`；batch 失败则逐主机回退单查；请求内 `$tlsMemo` 去重。

### 3.2 Query（Weline_Mail）

| Query | 入参 | 出参要点 |
|-------|------|----------|
| `mail.getSmtpTlsStatus` | `hostname` | `{status,is_local,message,pool_https,…}`（`StalwartTlsCertificateSyncService::getStatus`） |
| `mail.getSmtpTlsStatusBatch` | `hostnames: string[]` | `{success, items[], by_hostname{hostname→status}}`；内部去重后循环单查并 memo |

落点：`Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php`。

### 3.3 门禁落点（Weline_Smtp）

| 入口 | 行为 |
|------|------|
| `SmtpServiceCatalogService::createSelfService` | `summarizePreview(MODE_SELF, host)` → `block_create` 则 `success=false`，不落库 |
| `SmtpServiceEnableService::enable` | 同健康源；禁止「已启用」却非本机 |
| `SmtpServiceCatalogService::importSelfServicesFromMailDomains` | `block_create` → **skip**（`skipped++` + `skipNotes`），禁止盲建假绿 |

### 3.4 UI 双徽章（Weline_Mail）

| 层 | 落点 |
|----|------|
| Controller | `Mail\Controller\Backend\Index` smtp 分支：`assign('smtp_local_health_by_service', $health->summarizeForServices(...))` |
| 模板 | `Mail/view/templates/backend/Index/enterprise.phtml` |
| 模式徽章 | `.mail-smtp-mode-badge` · `data-testid=mail-smtp-mode-badge-{id}` · 文案「本机登记」/`info` 或「外部填入」/`primary` |
| 健康徽章 | 仅 `MODE_SELF`：`.mail-smtp-health-badge` · `data-testid=mail-smtp-health-badge-{id}` · `data-status` / `data-is-local` · tone/label 来自健康袋 |
| Token | 模块特异性绑回 foundation `w-badge` tone Token；禁止私造 hex / `!important` |

说明页文案已写明：模式标只表示登记路径；本机健康看旁侧健康徽章。

---

## 4. 真机用例（对齐冻结 · P6 已 pass）

| id | 要点 | P6 |
|----|------|-----|
| `UC-列表告警` | 非本机 `MODE_SELF` 不得假绿；可见 warning 健康徽章 | pass |
| `UC-新建本机启用门禁` | 非本机新建 fail-closed | pass |
| `UC-域名池非本机提示` | `needs_dns` /「DNS 未指向本机」与 Config 语义一致 | pass |
| `UC-同步导入不造假绿` | 同步跳过非本机，不造假绿目录行 | pass |

纪要：`dev/team/smtp-local-domain-health/meetings/p6-test.md`。  
**禁止**仅凭契约/UT 宣称需求完成；向用户完成汇报须 PM 汇审。

---

## 5. EARS（已实现）

### EARS-01 · 列表不得假绿

WHEN 企业邮局 Smtp 列表渲染 `MODE_SELF` 且 `getSmtpTlsStatus*` 为 `needs_dns` 或 `is_local=false`  
THEN 系统 SHALL 展示非 success 健康徽章，SHALL NOT 仅用 success「本机启用」表示已健康接管。

### EARS-02 · 新建/启用门禁

WHEN 管理员提交本机启用/启用 AND 主机经健康源判定 `block_create`  
THEN 系统 SHALL fail-closed，SHALL NOT 落库或启用为「看起来已接管」。

### EARS-03 · 同步导入

WHEN 「从邮件服务同步」遇到非本机 hostname  
THEN 系统 SHALL 跳过该主机并记入跳过说明，SHALL NOT 静默新建假绿 `MODE_SELF`。

### EARS-04 · 跨模块

WHILE 健康判定  
THEN Smtp/Mail SHALL 经 `mail.getSmtpTlsStatus*` 读取真相，SHALL NOT 跨模块直调 DomainPool Model。

---

## 6. 源码锚点（as-built）

| 锚点 | 职责 |
|------|------|
| `Weline/Smtp/Service/SmtpLocalDomainHealthService.php` | 健康聚合 + batch 预取 + `block_create` |
| `Weline/Smtp/Service/SmtpServiceCatalogService.php` | create / import 门禁 |
| `Weline/Smtp/Service/SmtpServiceEnableService.php` | enable 门禁 |
| `Weline/Mail/.../MailQueryProvider.php` | `getSmtpTlsStatus` / `getSmtpTlsStatusBatch` |
| `Weline/Mail/Controller/Backend/Index.php` | `smtp_local_health_by_service` assign |
| `Weline/Mail/view/templates/backend/Index/enterprise.phtml` | 双徽章渲染 |
| `Weline/Smtp/test/Unit/SmtpUnitContractsTest.php` | `SmtpLocalDomainHealth::testHealthServiceAndGates`（契约锁，≠交付） |

---

## 7. 架构隐患 / 周边（未扩修）

| 级 | 发现 | 状态 |
|----|------|------|
| 架构级债 | `PublicDnsHostnamePoolEnsureService` 入池硬标 `is_local_server=true` 与真判定冲突 | **本波不做**（PM 冻结） |
| 周边 | 后台 theme 全局 `.w-badge` 可能盖 foundation tone | 本波仅模块特异性绑回；Mode A 另裁 |
| 历史数据 | 已存在脏 `MODE_SELF` 行不删 | G4；靠列表 warning |

AOCI：多席 `aoci_search`/`aoci_overview` 超时或 0 hit → 文档与实现以源码为准（`aoci_unavailable`）。

---

## 8. 状态

- 需求分析规格 → 架构/对齐冻结 → 后端/查询/前端/UI/主题施工 → P6 UC×4 pass。  
- 本文件由 **Team:文档工程师:** 按 as-built 统一执笔（2026-10-18）；其它开发席禁私改本耐久 spec。  
- 待 **项目经理汇审** 后方可向用户宣称需求完成。
