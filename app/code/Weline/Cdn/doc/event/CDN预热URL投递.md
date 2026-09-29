# Weline Cdn 模块 - 事件文档

## 概述

本文档说明 `Weline_Cdn::send_warmup` 事件：将各 **WarmupProvider** 产出的 URL 写入 `cdn_warmup_url` 队列。编排层（`WarmupCollectService` / Cron）按 **Provider FQCN 分批** 投递；Domain 范围过滤只在编排层完成，Provider `execute()` 保持无参。

## 事件列表

### 1. Weline_Cdn::send_warmup - CDN预热URL投递事件

#### 基本信息

- **事件名称**：`Weline_Cdn::send_warmup`
- **事件类型**：CDN操作事件
- **触发时机**：收集预热 URL 入队时（管理页收集、Cron 分批收集）
- **触发位置**：`app/code/Weline/Cdn/Service/WarmupCollectService.php`
- **观察者**：`Weline\Cdn\Observer\WarmupSend`
- **配置文件**：`app/code/Weline/Cdn/etc/event.xml`

#### 功能说明

事件数据包含源模块名、**Provider FQCN**、URL 列表等。主要用于：

- 按 Provider 分批入队
- URL 校验与站点/域名解析
- 去重（键：`url + module`）
- 回写 `inserted_count` / `updated_count`

#### 分批投递示例（编排层）

```php
// WarmupCollectService::collectProvider — 每个 Provider 单独 dispatch（必须传 array，禁传 Event 对象）
$eventData = [
    'module' => 'Weline_Cdn', // 源模块：内置=Weline_Cdn；Product Extends=Weline_Product
    'provider' => \Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls::class, // FQCN，列宽 255
    'urls' => [
        [
            'url' => 'https://example.com/blog/hello',
            'site_id' => 0,
            'domain_id' => 1,
        ],
    ],
    'dedupe' => true,
];
$this->eventsManager->dispatch('Weline_Cdn::send_warmup', $eventData);
$result = $eventData['result'] ?? null; // inserted_count / updated_count
```

**禁止** `new Event(...)` 再 `dispatch(..., $event)` 后 `$event->getData()`：`EventsManager` 对非 array 引用会回写内层 data，调用方 `$event` 会变成 array 导致收集崩溃。

**禁止**整包 `provider=scanner`；Cron / 管理页均按 FQCN 分批。

#### Domain 范围

- Provider `execute()` **无参**，返回绝对 URL（+ 可选 `site_id`）。
- 编排层按当前 `target_scope`→`site_id`（Website）或兼容 `domain_id` 过滤：未命中范围的 URL **不写库**，计入 `filtered_count`。UI 使用官方 `<w:scope>`（`global,website`），禁止手写 Domain `<select>`。
- 管理页「执行预热 / 执行本范围预热」只调用 `WarmupRunner::run($limit, $domainId, $providerFqcn?)`，**不再**在 execute 路径里 collect。

#### 使用方法

在模块的 `etc/event.xml` 中注册观察者（通常仅 CDN 内置 `WarmupSend` 即可）：

```xml
<event name="Weline_Cdn::send_warmup">
    <observer name="Your_Module::send_warmup"
              instance="Your\Module\Observer\SendWarmupObserver"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

#### 事件数据

```php
[
    'module' => string,      // 源模块名（非 FQCN）
    'provider' => string,    // Provider FQCN（255）
    'urls' => array,         // 字符串 URL 或 URL item 列表（须绝对 URL）
    'dedupe' => bool,        // 是否按 url+module 去重
    'site_id' => int,        // 顶层默认站点（可选）
]
```

URL item：

```php
[
    'url' => 'https://example.com/page',
    'site_id' => 0,
    'domain_id' => 1, // 可选；编排层亦可解析
]
```

#### 站点与域名解析规则

- `site_id=0` 是系统默认站点，必须保留。
- 去重键：**`url + module`**（两 Provider 不同源模块时，同 URL 可分轨）。
- 未给 `domain_id` 时，按 URL host 在启用域名中精确匹配。

#### 内置 / Extends Provider

| Provider | 源模块 | 说明 |
|----------|--------|------|
| `Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls` | `Weline_Cdn` | `@Extra type=fpc` + Blog/FAQ/Policy Sitemap；**按站点已启用语种展开**（`LocalizedUrlBuilder`）；跳过商品通配 |
| `Weline\Product\Extends\Module\Weline_Cdn\ProductHeatUrls` | `Weline_Product` | `bestSellerCards` Top N × **站点已启用语种**；本迭代无 Visitor 流量热度 |

语种规则：`WebsiteLanguage::getWebsiteLanguageCodes`（默认语优先）+ `WarmupLocaleUrlExpander`；默认语路径省略 locale 段，其它语种为 `/en_US/...` 等形式。单 Provider 入队上限 `WarmupCollectService::PER_PROVIDER_CAP`（3000，覆盖 TopN×多语）。Visitor「哪门语言更热」另开 + Team:数据分析。

Scanner = **内置 FQCN ∪** `extends/module/Weline_Cdn/`（勿 self-extends 假装内置）。

#### BinQuery（资源 `cdn`）

| 操作 | 作用 | ACL |
|------|------|-----|
| `listWarmupProviders` | Provider 列表 + 本范围计数 | `cdn_warmup_list` |
| `listWarmupUrls` | 某 FQCN 已入队分页 | `cdn_warmup_list` |
| `collectWarmup` | 单 Provider 收集（`target_scope` / `site_id` / 兼容 `domain_id`） | `cdn_warmup_collect` |
| `executeWarmup` | **只跑 Runner**（`target_scope` / `site_id` / 兼容 `domain_id` / `provider`） | `cdn_warmup_execute` |

#### 相关文件

- 事件配置：`etc/event.xml`
- 编排：`Service/WarmupCollectService.php`
- 观察者：`Observer/WarmupSend.php`
- Runner：`Service/WarmupRunner.php`（`skipped` 不计 `success`）
- 扩展约定：[`extends.md`](../../extends.md)

## 更新日志

- **2026-09-28**：FQCN 主键、分批 payload、Domain 编排过滤、去重 `url+module`、BinQuery 四操作
- **2026-07-22**：明确 URL item 字段层级与站点域名解析
- **2024-12-19**：初始版本
