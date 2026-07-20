# Weline_Cdn 模块使用文档

## 概述

Weline_Cdn 是一个多适配器 CDN 管理模块，支持多种 CDN 提供商（如 Cloudflare），提供统一的缓存清理、规则管理和预热功能。

## 功能特性

- ✅ **多适配器支持**：通过编译型 `cache.edge_adapter.*` Provider Registry 接入 Cloudflare、WLS Memory 及第三方适配器
- ✅ **账户管理**：管理多个 CDN 账户，支持设置默认账户
- ✅ **域名管理**：为每个域名配置独立的 CDN 设置
- ✅ **缓存清理**：支持多种清理模式（全部、URL、主机、标签、缓存键）
- ✅ **规则管理**：管理 CDN 缓存规则，支持全局和域名级别的规则
- ✅ **缓存预热**：自动或手动预热 CDN 缓存，提升访问速度
- ✅ **HTTP API**：提供 RESTful API 接口，支持程序化调用
- ✅ **命令行工具**：提供 CLI 工具，支持批量操作

## 安装配置

### 1. 安装模块

确保模块已正确注册并启用：

```bash
php bin/console module:enable Weline_Cdn
php bin/console setup:upgrade
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

### 2.1 配置 Cloudflare OAuth（一键授权，可选）

若使用 OAuth 一键授权（而非手工填 API Token）：

1. 打开系统配置 → 模块 **Weline_Cdn** → **Cloudflare OAuth 应用**
2. 在 Cloudflare 按 **Weline 必选** 创建 OAuth Client（响应类型 **Code**，授权类型 **Authorization Code**，认证方法 **Client Secret Post/Basic**）
3. 回调 URL 填：`https://{域名}/{后台key}/cdn/backend/oauth/callback`
4. 将 Client ID / Secret / Scopes 填回本站配置

字段组合、错误选型与排错见：`doc/Cloudflare-OAuth-Client-Setup.md`。

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

## 快速开始

### 清理缓存

#### 方式一：后台操作

1. 进入 **CDN管理 > 域名管理**
2. 找到目标域名，点击"清理缓存"按钮

#### 方式二：HTTP API

```bash
curl -X POST https://your-domain.com/api/cdn/clear \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -d '{
    "domain": "example.com",
    "mode": "everything"
  }'
```

#### 方式三：命令行

```bash
php bin/console cdn:cache:clear --domain=example.com --mode=everything
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
- `Authorization: Bearer YOUR_API_TOKEN`

**请求参数**：
```json
{
  "domain": "example.com",      // 域名（必填）
  "mode": "everything",          // 清理模式（必填）
  "data": {                      // 额外数据（根据模式不同）
    "urls": ["url1", "url2"],    // mode=urls 时使用
    "hosts": ["host1", "host2"], // mode=hosts 时使用
    "tags": ["tag1", "tag2"],    // mode=tags 时使用
    "cache_keys": ["key1"]       // mode=cache_keys 时使用
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
php bin/console cdn:domain:add --site-id=1 --domain=example.com --adapter=cloudflare
```

参数：
- `--site-id`：网站 ID（必填）
- `--domain`：域名（必填）
- `--adapter`：适配器代码（必填）

### 导入规则

```bash
php bin/console cdn:rules:import --domain-id=1
```

参数：
- `--domain-id`：域名 ID（必填）

### 清理缓存

```bash
php bin/console cdn:cache:clear --domain-id=1 --mode=everything
```

参数：
- `--domain-id`：域名 ID（必填）
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
    'urls' => ['url1', 'url2'],
    'site_id' => 1,
    'domain_id' => 1
]
```

### Weline_Server::security::attack_detected / attack_recovered

WLS 攻击检测与恢复信号属于 `Weline_Server`；`Weline_Cdn` 是可选监听方，根据域名账户调用对应的 Edge Cache Adapter。详见 [CDN 攻击检测信号](event/CDN攻击检测信号.md)。

`Weline_Cdn::security::attack_detected` 和 `Weline_Cdn::security::attack_recovered` 仅保留一个版本的兼容监听别名；新集成必须使用 Server-owned 事件名。

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
   php bin/w server:reload
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
| credentials | text | 凭据（JSON） |
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
| credentials | text | 凭据覆盖（JSON） |
| rules_override | text | 规则覆盖（JSON） |
| warmup_interval_seconds | int | 预热间隔（秒） |
| enabled | tinyint | 是否启用 |
| created_at | int | 创建时间 |
| updated_at | int | 更新时间 |

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
