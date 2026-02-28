# Weline_Websites 域名管理功能扩展计划

> 最后更新：2026-02-27  
> 状态：规划中  
> 总计划链接：[.cursor/plans/域名模型重构计划_db9c4f72.plan.md](../../../../../.cursor/plans/域名模型重构计划_db9c4f72.plan.md)

## 一、概述

扩展域名管理功能，增加 CDN 账户关联、DNS 服务商检测、DNS 解析记录管理、自动解析、解析状态检测、HTTPS 证书自动申请等功能，实现域名从同步到建站的完整流程。

**注意**：PageBuilder 模块（`GuoLaiRen_PageBuilder`）的域名管理功能引用 `Weline_Websites` 的服务，因此本计划的所有后端功能实现后，需要同步更新 PageBuilder 的前端界面，确保两个模块的域名管理体验一致。

### ⚠️ 核心职责边界（重要）

| 模型 | 职责 | 状态检测 |
|------|------|---------|
| **Domain（根域）** | 存储从域名商同步的根域名基本信息 | 只检测 **NS 归属**（nameservers 指向哪个 DNS 服务商） |
| **DomainPool（域名池）** | 存储可建站的具体域名（含子域名） | 检测 **解析状态**、**HTTPS 状态**、**建站就绪** |
| **WebsiteDomain** | 网站与域名的关联关系 | 通过 `pool_id` 关联 DomainPool |

**建站选择的是域名池子，并非根域**。根域解析产生子域名后入池，域名池内的域名才是建站的选择对象。

### 1.1 当前界面状态

**Weline_Websites 域名管理**（`/websites/admin/domain/index`）：
- 现有 Tab1：域名商管理 ✓
- 现有 Tab2：域名购买 ✓
- ❌ **缺少**：域名列表（同步的域名、DNS 管理、解析状态、证书状态）
- 证书管理通过 Hook 由 Server 模块注入

**GuoLaiRen_PageBuilder 域名管理**（`/pagebuilder/backend/domain-management`）：
- 现有 Tab1：供应商账户 ✓
- 现有 Tab2：域名列表 ✓
- 现有 Tab3：域名注册 ✓
- ❌ **缺少**：证书管理 Tab

### 1.2 代码现状分析（需要重构）

> ⚠️ **当前代码与计划目标存在偏差，需要重构**

#### 1.2.1 `DomainPool` 模型现状

**当前字段**（`Model/DomainPool.php`）：

```php
public const fields_ID = 'pool_id';
public const fields_DOMAIN = 'domain';
public const fields_ROOT_DOMAIN = 'root_domain';
public const fields_DESCRIPTION = 'description';
public const fields_STATUS = 'status';
```

**缺少计划中的字段**：
- ❌ `parent_domain_id` - 关联 Domain.domain_id
- ❌ `resolve_status` - 解析状态
- ❌ `resolved_ip` / `resolved_ipv6` - 解析 IP
- ❌ `is_local_server` - 是否指向本服务器
- ❌ `resolve_checked_at` / `resolve_error`
- ❌ `https_status` / `https_expires_at` / `cert_id`
- ❌ `site_ready` - 建站就绪状态

#### 1.2.2 `DomainPool` API 现状

**当前代码**（`Controller/Backend/Api/DomainPool.php`）：

```php
class DomainPool extends BaseController
{
    private Domain $domainModel;  // ❌ 注入的是 Domain，而非 DomainPool
}
```

**问题**：控制器名字叫 `DomainPool`，但实际查询的是 `Domain` 表。

#### 1.2.3 `DomainResolveService` 现状

**当前代码**（`Service/DomainResolveService.php`）：

```php
public function checkResolve(Domain $domain): array  // ❌ 接收 Domain，而非 DomainPool
```

**问题**：解析检测应该针对 `DomainPool`，但当前 Service 操作 `Domain`。

#### 1.2.4 Cron 任务现状

| 文件 | 当前状态 | 问题 |
|------|---------|------|
| `Cron/DomainResolveCheck.php` | 查询 `Domain` 模型 | ❌ 应检测 `DomainPool` |
| `Cron/DomainAutoResolve.php` | 查询 `Domain` 模型 | ❌ 应操作 `DomainPool` |
| `Cron/DomainCertRequest.php` | **不存在** | ❌ 需要创建 |
| `Cron/DomainNsCheck.php` | **不存在** | ❌ 需要创建 |
| `Cron/DomainPoolResolveCheck.php` | **不存在** | ❌ 需要创建 |

#### 1.2.5 `Domain` 模型现状

**当前包含的字段**（`Model/Domain.php:43-53`）：

```php
// v1.5.0 新增字段 - 这些应该迁移到 DomainPool
public const fields_RESOLVE_STATUS = 'resolve_status';
public const fields_RESOLVED_IP = 'resolved_ip';
public const fields_HTTPS_STATUS = 'https_status';
public const fields_SITE_READY = 'site_ready';
```

**问题**：这些字段按照职责划分应该在 `DomainPool` 上，而非 `Domain`。

#### 1.2.6 缺失的服务

- ❌ `Service/SubdomainGeneratorService.php` - 子域名自动产生服务

### 1.3 改造目标

| 模块 | 需要添加 |
|------|----------|
| Weline_Websites | 新增 Tab3：**域名列表**（含 DNS 管理、解析状态、证书状态、建站状态） |
| GuoLaiRen_PageBuilder | 新增 Tab4：**证书管理**（SSL 证书申请、状态、续期） |

## 二、目标

1. **CDN 账户关联**：记录域名使用的 CDN 服务商账户信息
2. **DNS 服务商检测**：检测域名 DNS 是否指向原供应商还是其他 CDN 供应商，并给出明确提示
3. **DNS 解析记录管理**：查看、添加、修改、删除 DNS 解析记录
4. **自动解析功能**：定时任务自动将域名解析到本服务器公网 IP
5. **解析状态检测**：自动检测域名解析是否生效、是否指向本服务器
6. **HTTPS 证书自动申请**：解析正常且指向本服务器的域名，自动申请 HTTPS 证书
7. **建站就绪状态**：只有完全正常的域名才能进行建站

## 三、功能详细设计

### 3.1 CDN 账户关联

**目标**：记录域名使用的 CDN 服务商账户信息

**Domain 表新增字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `cdn_provider` | VARCHAR(50) | CDN 供应商代码（如 cloudflare, cloudfront） |
| `cdn_account_id` | INT | 关联的 CDN 账户 ID（可复用 Weline_Cdn 模块） |
| `dns_provider` | VARCHAR(50) | 实际 DNS 服务商（域名商/CDN 供应商） |

---

### 3.2 DNS 服务商检测与提示

**目标**：检测域名 DNS 是否指向原供应商还是其他 CDN 供应商

**检测逻辑**：

```
同步域名时：
1. 获取 nameservers（如 ns1.cloudflare.com）
2. 识别 NS 归属（cloudflare/gname/aliyun/azure 等）
3. 对比域名注册商与 NS 服务商
4. 若不一致 → 标记为"托管到其他 DNS 服务商"
```

**显示规则**：

| 场景 | 显示 |
|------|------|
| DNS = 域名商 | ✅ 绿色"使用原注册商 DNS" |
| DNS = Cloudflare | ⚠️ 橙色"托管到 Cloudflare" |
| DNS = 其他 CDN | 🔴 红色"DNS 已迁移到：XXX（原供应商：GName）" |

**新增服务**：`Service/DnsProviderDetector.php`

已知 NS 特征库：

| 供应商 | NS 特征 |
|--------|---------|
| Cloudflare | `*.ns.cloudflare.com` |
| GName | `*.gname.com`, `*.gname.net` |
| Aliyun | `*.alidns.com`, `*.hichina.com` |
| AWS Route53 | `*.awsdns-*.*` |
| Azure | `*.azure-dns.*` |
| DNSPod | `*.dnspod.net` |

---

### 3.3 DNS 解析记录管理

**目标**：查看、添加、修改、删除 DNS 解析记录

**新增数据模型** `Model/DomainDnsRecord.php`：

| 字段 | 类型 | 说明 |
|------|------|------|
| `record_id` | INT PK | 记录 ID |
| `domain_id` | INT | 域名 ID |
| `type` | VARCHAR(10) | 记录类型（A/AAAA/CNAME/MX/TXT/NS） |
| `name` | VARCHAR(255) | 主机记录（@/www/*） |
| `value` | VARCHAR(500) | 记录值（IP/域名） |
| `ttl` | INT | TTL 秒数 |
| `priority` | INT | 优先级（MX 用） |
| `status` | VARCHAR(20) | 状态（active/pending/error） |
| `remote_id` | VARCHAR(100) | 远程记录 ID（API 返回） |
| `synced_at` | DATETIME | 最后同步时间 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

**接口扩展** `Api/DomainRegistrarInterface.php` 新增方法：

```php
// 获取 DNS 解析记录
public function getDnsRecords(string $domain, array $credentials): array;

// 添加 DNS 解析记录
public function addDnsRecord(string $domain, array $record, array $credentials): array;

// 修改 DNS 解析记录
public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array;

// 删除 DNS 解析记录
public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array;
```

**前端弹窗设计**：

```
┌─────────────────────────────────────────────────┐
│  域名：example.com                    [刷新] [添加] │
├─────────────────────────────────────────────────┤
│ 类型  │ 主机记录 │  记录值           │ TTL  │ 操作 │
├───────┼─────────┼──────────────────┼──────┼─────┤
│  A    │    @    │ 123.45.67.89 ✓   │ 600  │ 编辑│
│  A    │   www   │ 123.45.67.89 ✓   │ 600  │ 编辑│
│ CNAME │   api   │ api.example.com  │ 3600 │ 编辑│
│  MX   │    @    │ mail.example.com │ 3600 │ 编辑│
└─────────────────────────────────────────────────┘
✓ = 指向本服务器    ⚠ = 指向外部    ✗ = 解析失败
```

---

### 3.4 自动解析功能

**目标**：定时任务自动将域名解析到本服务器公网 IP

**配置模型** `Model/DomainConfig.php`：

| 配置键 | 类型 | 说明 |
|--------|------|------|
| `auto_resolve_enabled` | bool | 是否开启自动解析 |
| `auto_resolve_record_type` | string | 解析类型（A/AAAA），默认 A |
| `auto_resolve_subdomains` | string | 解析子域，逗号分隔（@,www），默认 @,www |
| `server_public_ip` | string | 本服务器公网 IP（自动获取或手动填写） |
| `server_public_ipv6` | string | 本服务器公网 IPv6 |

**定时任务** `Cron/DomainAutoResolve.php`：

```
执行周期：每 5 分钟

foreach 同步到本地的域名:
    if 自动解析已开启 && 域名无解析记录:
        获取当前服务器公网 IP
        检查域名 DNS 服务商
        调用对应 DNS API 添加 A 记录（@ 和 www）
        记录解析结果
```

---

### 3.5 解析状态检测（域名池检测）

**目标**：自动检测 **域名池** 内域名解析是否生效、是否指向本服务器

> ⚠️ **注意**：解析状态检测针对的是 **DomainPool（域名池）**，而非 Domain（根域）。根域只检测 NS 归属（见 3.5.2）。

#### 3.5.1 域名池解析检测

**DomainPool 表新增字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `parent_domain_id` | INT | 关联 Domain.domain_id（根域名） |
| `resolve_status` | VARCHAR(20) | 解析状态：pending/resolved/error |
| `resolved_ip` | VARCHAR(45) | 解析到的 IP |
| `resolved_ipv6` | VARCHAR(45) | 解析到的 IPv6 |
| `is_local_server` | TINYINT(1) | 是否指向本服务器（0/1） |
| `resolve_checked_at` | DATETIME | 最后检测时间 |
| `resolve_error` | TEXT | 解析错误详情 |

**定时任务** `Cron/DomainPoolResolveCheck.php`（**新增**，替代原 DomainResolveCheck）：

```
执行周期：每 10 分钟

foreach DomainPool 域名池（status=active）:
    执行 DNS 查询（dns_get_record / gethostbyname）
    获取解析 IP
    对比本服务器公网 IP
    更新 DomainPool 状态：
        - resolved + is_local = ✅ 绿色
        - resolved + not_local = ⚠️ 橙色（指向外部 IP）
        - error = 🔴 红色（解析失败）
    计算并更新 site_ready
```

#### 3.5.2 根域 NS 归属检测

**目标**：检测根域的 nameservers 是否指向原注册商或被托管到其他 DNS 服务商

**定时任务** `Cron/DomainNsCheck.php`（**新增**）：

```
执行周期：每小时

foreach Domain 根域（status=active）:
    获取 nameservers
    调用 DnsProviderDetector 识别 NS 归属
    更新 Domain.dns_provider
    标记是否托管到外部（用于界面显示警告）
```

**状态显示**：

| resolve_status | is_local_server | 显示 |
|----------------|-----------------|------|
| resolved | 1 | ✅ 绿色"解析正常" |
| resolved | 0 | ⚠️ 橙色"指向外部 IP: xxx.xxx.xxx.xxx" |
| pending | - | 🟡 黄色"等待解析" |
| error | - | 🔴 红色"解析失败：{resolve_error}" |

---

### 3.6 HTTPS 证书自动申请

**目标**：解析正常且指向本服务器的 **域名池内域名**，自动申请 HTTPS 证书

> ⚠️ **注意**：证书申请针对的是 **DomainPool（域名池）**，而非 Domain（根域）。

**DomainPool 表新增字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `https_status` | VARCHAR(20) | 证书状态：none/pending/valid/expired/error |
| `https_expires_at` | DATE | 证书过期时间 |
| `https_error` | TEXT | 申请错误详情 |
| `https_requested_at` | DATETIME | 最后申请时间 |
| `cert_id` | INT | 关联 SslCertificate.cert_id |

**前置条件**：
1. `DomainPool.resolve_status = resolved`
2. `DomainPool.is_local_server = true`
3. `DomainPool.https_status != valid` 或证书即将过期（< 30 天）

**定时任务** `Cron/DomainCertRequest.php`（**修改**，改为筛选 DomainPool）：

```
执行周期：每小时

筛选条件（筛选 DomainPool）：
    DomainPool.resolve_status = 'resolved'
    AND DomainPool.is_local_server = 1
    AND (DomainPool.https_status != 'valid' OR DomainPool.https_expires_at < NOW() + 30 DAYS)

foreach 符合条件的域名池域名:
    调用 WLS 证书申请接口（待定义接口）
    更新 DomainPool.https_status 和 https_expires_at
    记录申请结果
```

**WLS 证书申请接口**（需与 WLS 模块协调）：

```php
// 预期接口
Weline\Server\Service\CertificateService::requestCertificate(string $domain): array
// 返回：['success' => true, 'expires_at' => '2027-02-27', 'cert_path' => '...']
```

---

### 3.7 建站就绪状态

**目标**：只有完全正常的 **域名池内域名** 才能进行建站

> ⚠️ **注意**：建站就绪状态在 **DomainPool（域名池）** 上计算，而非 Domain（根域）。

**DomainPool 表新增字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `site_ready` | TINYINT(1) | 是否可建站（0/1，计算字段） |

**建站就绪条件**（均针对 DomainPool）：

| 条件 | 说明 |
|------|------|
| `DomainPool.status = active` | 域名正常 |
| `DomainPool.resolve_status = resolved` | DNS 解析正常 |
| `DomainPool.is_local_server = true` | 指向本服务器 |
| `DomainPool.https_status = valid` | 证书有效 |

**显示**：满足所有条件 → 显示"可建站"绿色标签 + 启用"创建站点"按钮

---

### 3.8 子域名自动产生流程

**目标**：根域同步/购买后，自动产生默认子域名入域名池

**流程**：

```
根域同步/购买成功 (Domain)
       │
       ▼
调用 SubdomainGeneratorService
       │
       ▼
自动产生默认子域名（配置：@、www 等）
       │
       ▼
入域名池 (DomainPool)，设置 parent_domain_id
       │
       ▼
DomainPoolResolveCheck 定时检测解析
       │
       ▼
解析正常 + 指向本服务器
       │
       ▼
DomainCertRequest 自动申请证书
       │
       ▼
证书有效 → site_ready = 1
       │
       ▼
可被建站选择（从域名池选择，非根域）
```

**新增服务** `Service/SubdomainGeneratorService.php`：

```php
public function generateDefaultSubdomains(Domain $rootDomain): array
{
    // 配置的默认子域名前缀（如 @, www）
    $prefixes = $this->config->getDefaultSubdomainPrefixes();
    
    foreach ($prefixes as $prefix) {
        $subdomain = ($prefix === '@') 
            ? $rootDomain->getDomain() 
            : $prefix . '.' . $rootDomain->getDomain();
        
        // 入 DomainPool
        $pool = new DomainPool();
        $pool->setDomain($subdomain);
        $pool->setParentDomainId($rootDomain->getDomainId());
        $pool->setStatus(DomainPool::STATUS_ACTIVE);
        $pool->save();
    }
}
```

**配置项** `Model/DomainConfig.php`：

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `auto_generate_subdomains` | true | 是否自动产生子域名 |
| `default_subdomain_prefixes` | `@,www` | 默认子域名前缀，逗号分隔 |

---

## 四、功能流程图

```
┌──────────────────────────────────────────────────────────────────────┐
│                           域名同步流程                                │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  域名商 API ──同步──► 本地域名表                                      │
│       │                   │                                          │
│       │                   ▼                                          │
│       │         ┌─────────────────┐                                  │
│       │         │ 检测 Nameservers │                                  │
│       │         └────────┬────────┘                                  │
│       │                  │                                           │
│       │        ┌─────────┴─────────┐                                 │
│       │        ▼                   ▼                                 │
│       │   原注册商 DNS         其他 DNS                               │
│       │   (绿色标记)        (红色提示原供应商)                         │
│       │        │                   │                                 │
│       │        └─────────┬─────────┘                                 │
│       │                  ▼                                           │
│       │         ┌─────────────────┐                                  │
│       │         │ 同步 DNS 解析记录│                                  │
│       │         └────────┬────────┘                                  │
│       │                  │                                           │
│       ▼                  ▼                                           │
│  ┌──────────────────────────────────────────────────────┐            │
│  │                    域名列表页面                        │            │
│  │  ┌────┬────────┬───────┬───────┬────────┬─────────┐  │            │
│  │  │域名│DNS服务商│解析状态│HTTPS │建站状态│  操作   │  │            │
│  │  ├────┼────────┼───────┼───────┼────────┼─────────┤  │            │
│  │  │a.cn│GName ✓ │✓ 正常 │✓ 有效│ 可建站 │查看/建站│  │            │
│  │  │b.cn│CF ⚠️   │✓ 正常 │⏳申请中│ 等待中│  查看   │  │            │
│  │  │c.cn│其他 🔴 │✗ 错误 │✗ 无  │ 不可用 │修复/切换│  │            │
│  │  └────┴────────┴───────┴───────┴────────┴─────────┘  │            │
│  └──────────────────────────────────────────────────────┘            │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│                         定时任务流程                                  │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐           │
│  │ 自动解析任务  │    │ 解析检测任务  │    │ 证书申请任务  │           │
│  │ (每5分钟)    │    │ (每10分钟)   │    │ (每小时)     │           │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘           │
│         │                   │                   │                    │
│         ▼                   ▼                   ▼                    │
│  检查自动解析开关     DNS 查询所有域名     筛选解析正常域名            │
│         │                   │                   │                    │
│         ▼                   ▼                   ▼                    │
│  获取服务器公网 IP     更新 resolve_status    调用 WLS 证书 API       │
│         │                   │                   │                    │
│         ▼                   ▼                   ▼                    │
│  调用 DNS API 解析     标记 is_local_server   更新 https_status       │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 五、前端交互设计

### 5.1 域名列表页面

**顶部配置区**：

```
┌─────────────────────────────────────────────────────────────────┐
│ 域名管理                                                         │
├─────────────────────────────────────────────────────────────────┤
│ [x] 自动解析 DNS    服务器 IP: 123.45.67.89 [自动获取] [手动填写]  │
│ 解析子域: @ , www   记录类型: A ▼                                 │
└─────────────────────────────────────────────────────────────────┘
```

**批量操作工具栏**：

- [ ] 批量解析（到本服务器）
- [ ] 批量切换 DNS（选择目标 NS）
- [ ] 批量检测解析状态
- [ ] 批量申请证书

**域名列表表格**：

| 列 | 说明 |
|----|------|
| 域名 | 域名名称 |
| DNS 服务商 | 当前 NS 归属（带颜色标记） |
| 解析状态 | resolved/pending/error（带颜色） |
| 解析 IP | 当前解析到的 IP |
| HTTPS | 证书状态（带颜色） |
| 建站状态 | 可建站/等待中/不可用 |
| 操作 | 查看解析 / 建站 / 修复 |

### 5.2 DNS 解析弹窗

点击"查看解析"按钮，弹窗显示 DNS 解析记录列表，支持增删改查。

### 5.3 状态颜色规范

| 状态 | 颜色 | CSS 变量 | 图标 |
|------|------|----------|------|
| 正常/有效 | 🟢 绿色 | `--backend-color-success` | mdi-check-circle |
| 处理中/待验证 | 🟡 黄色 | `--backend-color-warning` | mdi-clock-outline |
| 警告/托管到外部 | 🟠 橙色 | `--backend-color-warning` | mdi-alert |
| 错误/失败 | 🔴 红色 | `--backend-color-danger` | mdi-close-circle |

---

## 六、涉及文件清单

### 6.1 Weline_Websites 新增文件

| 文件路径 | 说明 |
|----------|------|
| `Model/DomainDnsRecord.php` | DNS 解析记录模型 |
| `Model/DomainConfig.php` | 域名管理配置模型 |
| `Cron/DomainAutoResolve.php` | 自动解析定时任务 |
| `Cron/DomainResolveCheck.php` | 解析状态检测任务 |
| `Cron/DomainCertRequest.php` | 证书申请定时任务 |
| `Service/DnsProviderDetector.php` | DNS 服务商检测服务 |
| `Service/DomainResolveService.php` | 域名解析服务 |
| `Service/ServerIpService.php` | 服务器公网 IP 获取服务 |
| `Controller/Backend/Api/DnsRecord.php` | DNS 记录 API 控制器 |
| `Controller/Backend/Api/DomainResolve.php` | 域名解析 API 控制器 |
| `view/templates/Backend/Domain/dns-modal.phtml` | DNS 解析弹窗模板（可被 PageBuilder 复用） |

### 6.2 Weline_Websites 修改文件

| 文件路径 | 修改内容 |
|----------|----------|
| `Model/Domain.php` | 新增字段（dns_provider, resolve_status, https_status 等） |
| `Api/DomainRegistrarInterface.php` | 新增 DNS 记录操作接口方法 |
| `Adapter/GnameRegistrar.php` | 实现 DNS 记录操作（getDnsRecords, addDnsRecord 等） |
| `Adapter/AliyunDomainRegistrar.php` | 实现 DNS 记录操作 |
| `Service/DomainSyncService.php` | 同步时检测 DNS 服务商、同步解析记录，新增解析/证书相关方法 |
| `Controller/Admin/Domain.php` | 新增域名列表相关 Action、DNS/解析/证书 API |
| `view/templates/Admin/Domain/index.phtml` | 新增 Tab3（域名列表）按钮和 Hook |
| `view/templates/Admin/Domain/domain_list_tab.phtml` | **新增**：域名列表 Tab 内容（表格、筛选、批量操作） |

### 6.3 GuoLaiRen_PageBuilder 修改文件（同步更新）

| 文件路径 | 修改内容 |
|----------|----------|
| `Controller/Backend/DomainManagement.php` | 新增 DNS 记录、解析检测、证书申请相关 Action |
| `view/templates/Backend/DomainManagement/index.phtml` | 域名列表表格新增列 + 新增 Tab4（证书管理）+ 顶部配置区 + DNS 弹窗 + 批量操作按钮 |
| `view/templates/Backend/DomainManagement/dns-modal.phtml` | 复用或引入 Weline_Websites 的 DNS 弹窗组件 |
| `view/templates/Backend/DomainManagement/cert_tab.phtml` | **新增**：证书管理 Tab 内容（证书列表、申请、续期、状态） |

**PageBuilder 前端改造要点**：

1. **域名列表表格新增列**：
   - DNS 服务商（带颜色标记：绿色=原供应商，橙色=Cloudflare，红色=其他+原供应商提示）
   - 解析状态（绿色=正常，黄色=待解析，红色=错误）
   - HTTPS 状态（绿色=有效，黄色=申请中，红色=无）
   - 建站状态（可建站/等待中/不可用）

2. **顶部配置区新增**：
   - 自动解析 DNS 开关
   - 服务器公网 IP 显示/编辑
   - 解析子域配置（@,www）

3. **批量操作按钮新增**：
   - 批量解析（到本服务器）
   - 批量检测解析状态
   - 批量申请证书

4. **操作列新增**：
   - 查看解析（弹窗显示 DNS 记录）
   - 建站（仅建站就绪时可用）

5. **DNS 解析弹窗**：
   - 显示域名所有解析记录
   - 支持增删改查
   - 状态图标：✓=指向本服务器，⚠=指向外部，✗=解析失败

6. **新增 Tab4：证书管理**（PageBuilder 独有）：
   - 证书列表表格（域名、证书状态、颁发机构、有效期、操作）
   - 筛选：按状态（有效/即将过期/已过期/无证书/申请中/错误）
   - 批量操作：批量申请证书、批量续期
   - 单个操作：申请证书、续期、查看详情
   - 状态颜色：绿色=有效，黄色=即将过期（<30天），橙色=申请中，红色=已过期/错误
   - 证书详情弹窗：颁发机构、有效期、证书路径、申请历史

---

## 七、依赖与风险

### 7.1 外部依赖

| 依赖 | 说明 | 风险 |
|------|------|------|
| WLS 证书申请接口 | 需要 Weline_Server 模块提供证书申请服务 | 接口待定义 |
| 各域名商 DNS API | GName/Aliyun/AWS 等需支持 DNS 记录操作 | 部分供应商 API 可能不完善 |
| 公网 IP 获取服务 | 需要稳定的外部 IP 查询服务 | 可使用多个备用服务 |

### 7.2 风险点

1. **DNS 传播延迟**：DNS 修改后需要等待传播，检测可能出现误判
2. **API 限流**：批量操作可能触发供应商 API 限流
3. **证书申请失败**：Let's Encrypt 等 CA 可能因 DNS 未生效导致申请失败

---

## 八、开发阶段划分

### 阶段一：基础设施（预计 2-3 天）

- [ ] Domain 模型字段扩展
- [ ] DomainDnsRecord 模型创建
- [ ] DomainConfig 模型创建
- [ ] DnsProviderDetector 服务实现

### 阶段二：DNS 记录管理（预计 3-4 天）

- [ ] DomainRegistrarInterface 接口扩展
- [ ] GnameRegistrar DNS 记录操作实现
- [ ] DnsRecord API 控制器
- [ ] DNS 解析弹窗前端

### 阶段三：自动解析与检测（预计 2-3 天）

- [ ] ServerIpService 服务实现
- [ ] DomainAutoResolve 定时任务
- [ ] DomainResolveCheck 定时任务
- [ ] DomainResolveService 服务

### 阶段四：证书申请与建站就绪（预计 2 天）

- [ ] 与 WLS 模块集成（证书申请接口）
- [ ] DomainCertRequest 定时任务
- [ ] 建站就绪状态计算与显示

### 阶段五：Weline_Websites 前端整合与测试（预计 2-3 天）

- [ ] Weline_Websites 域名列表页面改造
- [ ] 批量操作功能
- [ ] 状态显示与颜色规范
- [ ] Weline_Websites 功能测试

### 阶段六：GuoLaiRen_PageBuilder 前端同步（预计 1-2 天）

- [ ] PageBuilder DomainManagement 控制器扩展（新增 DNS/解析/证书相关 Action）
- [ ] PageBuilder 域名列表表格改造（新增列：DNS服务商/解析状态/HTTPS/建站状态）
- [ ] PageBuilder 顶部配置区（自动解析开关、服务器IP、解析子域）
- [ ] PageBuilder 批量操作按钮（批量解析/检测/申请证书）
- [ ] PageBuilder DNS 解析弹窗实现
- [ ] PageBuilder 与 Weline_Websites 一致性测试

---

## 九、进度记录

| 日期 | 进度 | 说明 |
|------|------|------|
| 2026-02-27 | 计划创建 | 完成需求整理与计划文档 |

