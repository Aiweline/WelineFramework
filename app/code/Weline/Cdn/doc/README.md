# Weline_Cdn 模块使用文档

## 概述

Weline_Cdn 是一个多适配器 CDN 管理模块，支持多种 CDN 提供商（如 Cloudflare），提供统一的缓存清理、规则管理和预热功能。

## 功能特性

- ✅ **多适配器支持**：通过编译型 `cache.edge_adapter.*` Provider Registry 接入 Cloudflare、WLS Memory 及第三方适配器
- ✅ **账户管理**：管理多个 CDN 账户，支持设置默认账户
- ✅ **Scope 授权账户（P1D-002）**：按 Website/Store + `store_mode` 绑定授权账户；test/normal 隔离；`restoreInheritance` 恢复父级
- ✅ **凭据密封**：`setCredentialsArray` 落库为 `secret_ref:v1:`；Query/API 仅返回 `has_credentials`
- ✅ **媒体 URL COW**：Scope 可覆盖媒体基址，未覆盖则继承共享基址
- ✅ **域名管理**：为每个域名配置独立的 CDN 设置
- ✅ **缓存清理**：支持多种清理模式（全部、URL、主机、标签、缓存键）
- ✅ **规则管理**：管理 CDN 缓存规则，支持全局和域名级别的规则
- ✅ **缓存预热**：自动或手动预热 CDN 缓存，提升访问速度
- ✅ **HTTP API**：提供 RESTful API 接口，支持程序化调用
- ✅ **命令行工具**：提供 CLI 工具，支持批量操作

### Scope 账户绑定（开发入口）

| 类 | 职责 |
|---|---|
| `ScopedAccountBindingService` | 通过仓储持久化 storageScope + store_mode + adapter 绑定，按 Channel→Store→Website→Global 解析 |
| `AccountManager::bindAccountToScope` | 写前验证账户存在、active 且 adapter 一致 |
| `AccountManager::resolveAuthorizedAccount` | 只返回当前 Scope 已授权且 active 的账户 |
| `MediaUrlCowResolver` | COW 媒体基址 |
| `UrlSiteResolver::resolveDomainByUrl($url, $scope)` | 按 website_id（含 0）过滤 |
| `CdnQueryProvider` | Browser：`bindAccountToScope` / `resolveBinding` / `restoreScopeInheritance` / `resolveCowMediaUrl` / `resolveAuthorizedAccount`（响应无 credentials） |
| `OrmScopedAccountBindingRepository` | 表 `cdn_scoped_account_binding` 持久化（跨请求） |

E2E：`Test/e2e/backend/plan-p1d02-scoped-binding.spec.js`（TEST-P1D-02）。

凭据密封说明见：`app/code/Weline/Framework/doc/3-开发/secret_ref凭据密封.md`。

## 安装配置

### 1. 安装模块

确保模块已正确注册并启用：

```bash
php bin/w setup:upgrade
```

### 2. 配置 CDN 账户

1. 进入后台：**CDN管理 > 账户管理**
2. 点击"添加账户"
3. 选择适配器（如 Cloudflare）
4. 填写账户信息：
   - 账户名称
   - API Token（或其他凭据，根据适配器要求）
   - 描述（可选）
5. 保存账户

Cloudflare API Token 权限说明见：`doc/Cloudflare-API-Token-Permissions.md`。

编辑账户时，API Token 留空保留已保存的密封凭据；填写新值才替换。后台表单不回显已保存的 Token。保存后可点击“测试已保存账户连接”，可选填写目标 Zone ID：只验证 Token 状态和 Zone 可访问性，不执行清缓存，也不能据此证明 Cache Purge 权限。实际清理以服务商返回结果为准。

### 2.1 配置 Cloudflare OAuth（一键授权，推荐）

平台管理员在 Cloudflare 创建一次机密 OAuth Client，回调 URL 使用：

https://{域名}/{后台key}/cdn/backend/oauth/callback

服务器通过 WELINE_CLOUDFLARE_OAUTH_CLIENT_ID 和 WELINE_CLOUDFLARE_OAUTH_CLIENT_SECRET 保存客户端配置；最小 scopes 为 zone.read、dns.write、offline_access。企业邮箱用户随后只需在每域名面板点击“连接或重新授权 Cloudflare”。授权 state 为一次性、会话绑定且只存哈希；用户令牌通过 Cdn Account 的 secret_ref 加密边界保存并自动刷新。

邮箱 DNS 写命令只管理当前域名的 mail A/AAAA（强制 DNS-only）、根 MX、根 SPF、实际 DKIM 选择器和 DMARC。它会先预览，检测 Email Routing 锁定记录，明确确认后才写入；失败时反向回滚并报告残留变更。smtp CNAME、其他 TXT 和其他域名不会被删除。PTR/rDNS 不属于 Cloudflare DNS，仍需云服务器厂商配置。

详细字段与安全边界见：doc/Cloudflare-OAuth-Client-Setup.md。

### 3. 添加域名

1. 进入后台：**CDN管理 > 域名管理**
2. 点击"添加域名"
3. 填写域名信息：
   - 选择网站
   - 选择适配器
   - 域名名称（如：example.com）
   - Zone ID（CDN 提供商的 Zone ID）
   - 关联账户（可选，默认使用适配器的默认账户）
   - 预热间隔（秒）
4. 保存域名

同一个 Cloudflare Zone 可配置在多个网站和域名记录中。例如 `www.example.com` 与 `shop.example.com` 可以使用 `example.com` 的 Zone ID；清理请求仍保留原始完整 URL。已配置独立子域 Zone 时，URL 优先匹配网站内最具体的域名。域名映射以 `website_id` 隔离，`0` 是合法默认网站。

### 变更自动清理（1.0.4）

- 网站资源变更按公开基址的 Host/Prefix 范围清理；商品 `product_search_projection` 的 `impact.urls / previous_urls` 通过既有 ResourceChange 消费，只清理商品 URL，缺少 URL 时不扩大清理范围。
- 店铺/渠道的既有保存事件清理当前与保存前公开 URL 对应的范围，覆盖所有页面的 SEO Head：独立主机按 Host、带路径的店铺基址按 `host/path` Prefix；店铺 URL 为空时继承网站 URL，默认网站和店铺 ID `0` 均保留。适配器不支持 Prefix 时准确返回失败，不扩大为整 Zone 清理。
- `Weline_Cdn::request` 的 `purge_urls` 逐 URL 匹配当前网站域名并分组；未匹配 URL 返回失败信息但不阻止其他匹配组清理；`purge_scope` 按公开基址的 host/path 清理范围；`purge_all` 与无 URL 的网站资源变更按已绑定主机清理。显式管理 API/CLI 的 `mode=everything` 仍表示整个 Zone。
- Cloudflare 的 URL、Host、Tag、Prefix 每批最多 100 项。只有 HTTP 2xx 且 JSON `success === true` 才累计成功；任一批失败整体返回失败，并保留 `purged_count`、`requested_count`、`purge_ids`，不宣称全部完成。成功表示服务商接受清理，实际命中需从 CDN 响应观察。

定向开发回归：`php app/code/Weline/Cdn/Test/Regression/CdnDeliveryRegression.script.php`。此脚本替换 HTTP/ORM 边界，不访问账户或清理外部缓存；不能替代真实后台与 CDN 验收。

## 快速开始

### 清理缓存

#### 方式一：后台操作

1. 进入 **CDN管理 > 域名管理**
2. 找到目标域名，点击"清理缓存"按钮

#### 方式二：HTTP API

```bash
curl -X POST https://your-domain.com/api/cdn/clear \
  -H "Content-Type: application/json" \
  -b 'YOUR_BACKEND_SESSION_COOKIE' \
  -d '{
    "domain": 1,
    "mode": "everything"
  }'
```

#### 方式三：命令行

```bash
php bin/w cdn:cache:clear --domain=example.com --mode=everything
```

### 管理规则

1. 进入 **CDN管理 > 规则管理**
2. 选择域名（或选择"全局默认规则"）
3. 编辑规则（JSON 格式）
4. 点击"保存规则"
5. 点击"推送到 CDN"使规则生效

### 缓存预热

1. 进入 **CDN管理 > 预热管理**
2. 查看待预热的 URL 列表
3. 点击"执行预热"按钮手动触发预热
4. 或等待定时任务自动执行预热

## API 文档

### 清理缓存接口

**接口地址**：`POST /api/cdn/clear`

**请求头**：
- `Content-Type: application/json`
- 有效的后台 REST 会话 Cookie；当前 Controller 不解析 Bearer API Token

**请求参数**：
```json
{
  "domain": "example.com",      // 域名（必填）
  "mode": "everything",          // 清理模式（必填）
  "data": {                      // 额外数据（根据模式不同）
    "urls": ["url1", "url2"],    // mode=urls 时使用
    "hosts": ["host1", "host2"], // mode=hosts 时使用
    "tags": ["tag1", "tag2"],    // mode=tags 时使用
    "keys": ["key1"]             // mode=cache_keys 时使用
  }
}
```

**清理模式**：
- `everything`：清理所有缓存
- `urls`：清理指定 URL
- `hosts`：清理指定主机
- `tags`：清理指定标签
- `cache_keys`：清理指定缓存键

**响应示例**：
```json
{
  "success": true,
  "message": "缓存清理成功",
  "data": {
    "domain": "example.com",
    "mode": "everything",
    "result": "success"
  }
}
```

## 命令行工具

### 添加域名

```bash
php bin/w cdn:domain:add --site=default --adapter=cloudflare --zone-id=optional-zone-id
```

参数：
- `--site`：网站 code（必填）；`default` 会正确命中 `website_id=0`
- `--adapter`：适配器代码（可选，默认 `cloudflare`）
- `--zone-id`：Zone ID（可选；未给时使用默认账户尝试查询）

### 导入规则

```bash
php bin/w cdn:rules:import --domain=1
```

参数：
- `--domain`：域名 ID 或名称（必填；多站点建议数字 ID）

### 清理缓存

```bash
php bin/w cdn:cache:clear --domain=1 --mode=everything
```

参数：
- `--domain`：域名 ID 或名称（必填；多站点建议数字 ID）
- `--mode`：清理模式（必填），可选值：everything, urls, hosts, tags, cache_keys

## 事件系统

模块提供以下事件，供其他模块监听：

### Weline_Cdn::clear

缓存清理事件，在清理缓存时触发。

**事件数据**：
```php
[
    'domain' => 'example.com',
    'mode' => 'everything',
    'data' => []
]
```

### Weline_Cdn::send_warmup

预热 URL 提交事件，在提交预热 URL 时触发。

**事件数据**：
```php
[
    'module' => 'ModuleName',
    'provider' => 'provider_name',
    'site_id' => 0, // 可选的默认站点；0 是合法默认站
    'urls' => [
        [
            'url' => 'https://example.com/page',
            'site_id' => 0,   // 可逐 URL 覆盖
            'domain_id' => 1, // 只在 URL item 内生效
        ],
    ],
    'dedupe' => true,
]
```

`site_id` 显式出现时必须是非负整数，`0` 代表系统默认站点；只有字段缺失或
`null` 才表示未提供站点。字符串 URL 继承顶层站点，数组 URL 可以逐项覆盖。
`domain_id` 必须写在对应 URL item 中，并且必须属于该精确站点；不会退回其他站点的域名。

### Weline_Framework::resource_changed

Website 生产者发布 `ResourceChange v1`。2026-09-05 已核对当前宿主：
`Event.dispatch` 直接执行观察者，没有接入异步调度器；XML 中既有
`delivery="async" retry="standard" coalesce="latest"` 元数据不会改变这条执行路径。
因此 CDN 调用当前即时执行，可能处于业务调用或事务内，不具备已验证的 Outbox 持久化、
后台投递或自动重试保证。执行时只查询 `ResourceChange.website_id` 对应的启用域名：

- `website_id=0` 是合法的 `default` 站点，不得以真假值过滤。
- 合并 `impact.urls` 和 `impact.previous_urls`，再在当前网站内按最具体域名匹配。
- 网站 URL 影响按对应 Host/Prefix 范围清理；商品、CMS 与 URL 重写仅清相关 URL。非商品影响集为空时按该站点的绑定主机清理；商品无 URL 时跳过。
- 无对应站点域名时直接结束，不查“第一个启用域名”，不跨站 fallback。
- 服务商清理失败会由观察者抛出；当前路径没有已接通的异步 retry/dead-letter 处理，需检查实际调用结果。

店铺/渠道保存事件及 `Weline_Cdn::request` 也即时调用清理服务。当前环境没有可用于验证的
CDN 账户，未配置目标域名时观察者提前返回；这不表示异步队列丢失，也不能作为线上 purge
成功证据。外部验收仍需有效 Token、目标 Zone 与可缓存 URL，执行实际变更后核对服务商
响应、purge ID 和目标 URL 的缓存响应。仅开启异步配置标志不能替代完整投递链的运行验证。

`Weline_Cdn::clear` 是手工/兼容清理入口；按域名字符串查找不带站点维度。多站点集成应传递
已经从目标站点解析出的数字 `domain_id`，或使用带 `site_id`/`website_id` 的
`Weline_Cdn::request`。

### Weline_Server::security::attack_detected / attack_recovered

WLS 攻击检测与恢复信号属于 `Weline_Server`；`Weline_Cdn` 是可选监听方，根据域名账户调用对应的 Edge Cache Adapter。详见 [CDN 攻击检测信号](event/CDN攻击检测信号.md)。

`Weline_Cdn::security::attack_detected` 和 `Weline_Cdn::security::attack_recovered` 仅保留一个版本的兼容监听别名；新集成必须使用 Server-owned 事件名。

攻击检测信号的站点解析顺序是顶层 `site_id` → `signal.site_id` → 当前运行时站点。
前两者只有字段缺失才继续 fallback；显式 `null` 会被判定为缺少站点并拒绝处理。
`site_id=0` 是合法默认站。普通域名与通配符域名查询都带该精确 `site_id`，
未命中时只记录并跳过，不会向其他站点的 CDN 账户发送防护请求。

## 扩展开发

### 创建自定义适配器

1. 创建适配器类。零耦合实现优先使用 Framework 公开契约 `Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface`；已依赖 `Weline_Cdn` 的旧集成也可实现 `Weline\Cdn\Api\AdapterInterface`。

2. 在所属模块的 `etc/module.php` 中声明唯一 Provider capability：

   ```php
   'provides' => [
       'cache.edge_adapter.300.your_adapter' => \Vendor\Module\Api\Cache\YourAdapter::class,
   ],
   ```

3. 重新编译注册表，运行中的 WLS 再执行重载：

   ```bash
   php bin/w framework:compile
   php bin/w server:reload {instance-name}
   ```

Provider 清单在进程内不可变；`forceReload` 只会重建适配器实例，不会重读模块清单。请勿将适配器放入 `extends/module/Weline_Cdn/Adapter/`，也不要编辑 `generated/` 内的编译产物。完整契约、Cloudflare 和 WLS Memory 注册示例见 [模块扩展文档](../extends.md)。

### 提供预热 URL

其他模块可以提供预热 URL，只需创建 `Cdn/WarmupProvider.php` 文件：

```php
<?php
namespace YourModule\Cdn;

class WarmupProvider
{
    public function getWarmupUrls(): array
    {
        return [
            [
                'url' => 'https://example.com/page1',
                'site_id' => 1,
                'domain_id' => 1
            ],
            // ... 更多 URL
        ];
    }
}
```

## 定时任务

模块包含一个定时任务，用于自动执行缓存预热：

- **任务名称**：`Weline_Cdn::warmup`
- **执行频率**：每小时执行一次
- **任务类**：`Weline\Cdn\Cron\Warmup`

## 数据库表结构

### cdn_account

CDN 账户表。

| 字段 | 类型 | 说明 |
|------|------|------|
| account_id | int | 主键 |
| adapter | varchar | 适配器代码 |
| name | varchar | 账户名称 |
| description | text | 描述 |
| credentials | text | `secret_ref:v1:` 密封凭据；公共投影只返回 `has_credentials` |
| is_default | tinyint | 是否默认账户 |
| status | varchar | 状态（active/inactive） |
| created_at | int | 创建时间 |
| updated_at | int | 更新时间 |

### cdn_domain

CDN 域名表。

| 字段 | 类型 | 说明 |
|------|------|------|
| domain_id | int | 主键 |
| site_id | int | 网站 ID |
| adapter | varchar | 适配器代码 |
| zone_id | varchar | Zone ID |
| domain_name | varchar | 域名名称 |
| account_id | int | 账户 ID |
| inherit_default | tinyint | 是否继承默认账户 |
| credentials | text | `secret_ref:v1:` 密封凭据覆盖 |
| rules_override | text | 规则覆盖（JSON） |
| warmup_interval_seconds | int | 预热间隔（秒） |
| enabled | tinyint | 是否启用 |
| created_at | int | 创建时间 |
| updated_at | int | 更新时间 |

### cdn_scoped_account_binding

Scope 授权账户持久表。唯一键为
`(storage_scope, store_mode, adapter)`；表内只保存账户 ID、媒体基址和公开别名，
不保存 credentials、secret_ref 或任何明文密钥。

| 字段 | 类型 | 说明 |
|------|------|------|
| binding_id | int | 主键 |
| storage_scope | varchar | 规范化三段存储 Scope |
| store_mode | varchar | normal/dev/test，彼此隔离 |
| adapter | varchar | 适配器代码 |
| account_id | int | 已授权账户 ID |
| media_base_url | varchar | 可选 COW 媒体基址，仅允许 HTTP/HTTPS |
| global_alias | varchar | 可公开的全局授权别名 |
| created_at | datetime | 创建时间 |
| updated_at | datetime | 更新时间 |

### cdn_warmup_url

预热 URL 表。

| 字段 | 类型 | 说明 |
|------|------|------|
| warmup_url_id | int | 主键 |
| module | varchar | 模块名 |
| provider | varchar | 提供商 |
| url | varchar | URL |
| site_id | int | 网站 ID |
| domain_id | int | 域名 ID |
| status | varchar | 状态 |
| target_count | int | 目标次数 |
| processed_count | int | 已处理次数 |
| success_count | int | 成功次数 |
| fail_count | int | 失败次数 |
| retries | int | 重试次数 |
| enabled | tinyint | 是否启用 |
| last_warmed_at | int | 最后预热时间 |
| created_at | int | 创建时间 |
| updated_at | int | 更新时间 |

## 常见问题

### Q: 如何切换适配器？

A: 在域名管理页面，编辑域名时可以选择不同的适配器。注意：切换适配器后需要重新配置 Zone ID 和凭据。

### Q: 预热功能如何使用？

A: 预热功能需要：
1. 在域名管理中启用域名
2. 配置预热间隔
3. 其他模块通过事件或 WarmupProvider 提供 URL
4. 手动执行或等待定时任务自动执行

### Q: 如何调试 API 调用？

A: 查看日志文件：`var/log/system.log`，模块会记录所有 API 调用和错误信息。

## 技术支持

如有问题，请查看：
- 模块计划文档：`app/code/Weline/Cdn/计划.md`
- 开发文档：`docs/dev/开发文档.md`
- 常见错误：`AI-常犯错误.md`

## 更新日志

### v1.0.0 (2024-01-XX)

- 初始版本发布
- 支持多适配器架构
- 实现账户和域名管理
- 实现缓存清理功能
- 实现规则管理功能
- 实现缓存预热功能
- 提供 HTTP API 接口
- 提供命令行工具
