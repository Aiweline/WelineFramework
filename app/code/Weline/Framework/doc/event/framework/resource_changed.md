# `Weline_Framework::resource_changed` v1

## 用途与发布边界

`resource_changed` 是版本化、不可变的资源变更信封。Producer 必须在 Framework 默认主库的同一受管事务内完成业务写入、revision、critical sync Observer 和可选 Outbox；进程快照、HTTP、CDN 与 WLS IPC 位于 afterCommit 或 async Delivery。

```php
$revision = $resourceRevision->next('website', $websiteId);
$change = $factory->create(
    resourceType: 'website',
    resourceId: $websiteId,
    action: 'upsert',
    revision: $revision,
    websiteId: $websiteId,
    websiteCode: $websiteCode,
    before: $before,
    after: $after,
    changedFields: $changedFields,
    impact: $impact,
    origin: ['entry' => 'website.edit'],
);
w_changed($change);
```

`w_changed()` 只接受 `ResourceChange` DTO，不接受 Model、DataObject 或任意数组；Observer 不得替换 DTO。`ResourceRevisionService::next()` 要求默认主库已有活动受管事务，否则抛 `UnsupportedAsyncTransactionConnectionException`。

## v1 envelope

```php
[
    'schema_version' => 1,
    'event_id' => '32 位小写十六进制随机 ID',
    'event_name' => 'Weline_Framework::resource_changed',
    'occurred_at' => '2026-07-22T12:34:56.123456Z',
    'resource' => [
        'type' => 'website',
        'id' => '0',
        'action' => 'upsert', // upsert|delete|publish|unpublish
        'revision' => 18,
    ],
    'website' => [
        'id' => 0,
        'code' => 'default',
        'previous_code' => null,
        'site_id' => 0,
    ],
    'impact' => [
        'namespaces' => ['website/default'],
        'previous_namespaces' => [],
        'urls' => [],
        'previous_urls' => [],
    ],
    'changed_fields' => ['name'],
    'before' => [],
    'after' => [],
    'origin' => [
        'area' => 'backend',
        'entry' => 'website.edit',
        'request_id' => '',
        'instance' => '',
        'trigger_by' => ['type' => 'admin', 'id' => 1],
    ],
    'context' => [
        'website_id' => 0,
        'website_code' => 'default',
        'lang' => 'zh_Hans_CN',
        'currency' => 'CNY',
        'area' => 'backend',
        'timezone' => 'Asia/Shanghai',
        'user' => ['type' => 'admin', 'id' => 1],
    ],
]
```

### 不变量

- `event_id` 匹配 `^[a-f0-9]{32}$`，`occurred_at` 是 UTC 微秒时间。
- `resource.type` 是小写稳定类型，`id` 一律字符串，`revision>=1`。
- `resource_key` 是 type、NUL 分隔符和 string id 的 SHA-256。Revision 在同一事务中 CAS +1，最多重试 8 次；delete 不删 tombstone。
- `website.id=0` 和 `code=default` 是正常系统默认站，不能用真值判断当成缺失。
- delete 必须 `after=null`，并保留 before、previous namespace 和 previous URL。
- `before/after` 仅允许业务白名单；不得放入 token、secret、cookie、Session、CSRF 或完整请求。
- `context.user` 只是审计 `type/id`，Worker 不用它恢复授权。
- `coalesced_event_ids` 只由 latest coalesce 产生，每项仍必须是合法 event ID。

`ResourceChangePayloadMapper` 再次验证 DTO、Context 与 Canonical JSON；payload 上限 49,152 bytes、深度 16，`payload_sha256` 基于关联键递归排序后的规范 JSON。

## 当前 Observer

| 模块 | Observer | 策略 | 作用 |
|---|---|---|---|
| Framework | `cache_namespace` | sync + critical, sort 10 | 对 current/previous namespace 执行 DB `bumpMany()` |
| SEO | `seo_resource_changed` | sync + critical, sort 20 | 在主库事务中登记 SEO 目标 |
| CDN | `cdn_resource_changed` | async + standard + latest, timeout 30 | 在 Delivery Worker 中消费 URL 影响 |

`event.async.producer_enabled` 默认 false，因此 CDN async 声明不等于默认会产生 Outbox。critical sync Observer 仍照常执行。

## Outbox、Delivery 和 coalesce

`weline_framework_event_outbox` 以 `event_id` 唯一，保存 payload/context/observer target manifest 和统一 SHA-256，状态为 `pending|relaying|expanded|dead`。Observer target 只保存 `observer_key/module/name/instance_hash/retry/coalesce/timeout/max_attempts/coalesce_key`，不保存可由 Worker 实例化的 class 字段。

Relay 对每个 target 创建 Delivery，`event_id + observer_key` 唯一：

```text
pending|retry_wait -> provisioning -> queued -> running
running -> succeeded | retry_wait | dead
pending|retry_wait|provisioning -> superseded
旧 revision -> skipped
```

Transport 幂等键为 `delivery:{delivery_id}:attempt:{attempt_no}`，Queue handle 为 `queue:{queue_id}`，Queue content 仅含 `delivery_id/attempt_no`。Transport 建件失败不消耗 Observer attempt；已 queued 但 dispatch 失败时保留同一 Queue 等待 scanner。

`retry=none` 最多 1 次，`standard` 最多 6 次。超时由维护扫描 running lease，只在 Transport 确认 terminate 后才进入 retry/dead；三次仍无法确认时 dead=`transport_termination_unconfirmed`，不并发下一 attempt。PHP `max_execution_time` 不是 Delivery timeout。

`coalesce=latest` 仅合并相同 module/name、coalesce key、schema、resource type/id 的 upsert；delete/publish/unpublish 是 barrier。新 Delivery 保留最早 before、最新 after/revision/context，合并 changed fields，并把旧/previous/current URL 与 namespace 正确归并到 previous 集合。已 queued/running 不改写；超过 payload 上限时两条独立保留，不截断。

CoalesceSlot 使用完整 observer/coalesce key + 双 SHA-256 唯一键，命中后仍校验完整值。

## 死信、重放与保留

- 无效 schema/payload/context、Observer 缺失/禁用/类型不符、Observer 不支持 schema 为不可重试失败。
- dead 原行不改回 pending。安全重放新建 event ID、Outbox 和单 Observer target，并写 replay 审计链。
- succeeded/superseded/skipped 保留 30 天；dead 与 replay 记录保留 180 天。GC 不删未终态、被 replay 引用的 Delivery，或仍有 Delivery 的 Outbox。

运维细节见 [异步事件死信运维](../../3-开发/异步事件死信运维.md)。

## 注册与验证

```bash
php bin/w event:rebuild -m Weline_Framework
php bin/w event:rebuild -m Weline_Cdn
php bin/w event:rebuild -m Weline_Seo
php bin/w framework:compile
php bin/w queue:collect
```

这些命令只检查注册/编译。未在目标 DB 上观察业务回滚、Outbox/Delivery、Queue attempt 和消费者副作用前，不得声称可靠异步端到端通过。
