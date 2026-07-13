# CDN 攻击检测与恢复信号

## 事件所有权

权威事件由 `Weline_Server` 发布：

- 攻击检测：`Weline_Server::security::attack_detected`
- 攻击恢复：`Weline_Server::security::attack_recovered`

`Weline_Cdn` 是可选集成方：监听 Server-owned 事件，再根据域名账户和适配器 capability 开启或关闭边缘防护。Server 不引用 Cdn 模块的内部类。

下列旧名称只在 Cdn 的 `etc/event.xml` 中保留一个版本的兼容监听别名：

- `Weline_Cdn::security::attack_detected`
- `Weline_Cdn::security::attack_recovered`

新代码和第三方集成必须监听或发布 Server-owned 名称。不要同时发布新旧两个名称，否则兼容期内 Cdn Handler 会重复执行。

## 拓扑无关的处理流程

```text
WLS Dispatcher/Direct Worker 安全策略
→ Server 攻击信号状态
→ AttackSignalMonitor
→ Weline_Server::security::attack_detected / attack_recovered
→ Cdn Observer（可选）
→ 域名 + 账户的 adapter code
→ 编译型 cache.edge_adapter.* Provider Registry
→ enableAttackMode() / disableAttackMode()
```

该链路不依赖 Dispatcher 作为 HTTP 代理：Windows Dispatcher 和 POSIX Direct 都使用 Server-owned 事件与同一 Cdn 集成契约。

## 攻击检测负载

`Weline_Server::security::attack_detected` 的 `DataObject` 包含：

```php
[
    'signal' => [
        'type' => 'rate_limit',
        'domain' => 'example.com',
        'ip' => '1.2.3.4',
        'timestamp' => 1738742400,
        'reason' => '请求频率过高',
    ],
    'summary' => [
        'total' => 15,
        'by_type' => [
            'rate_limit' => 10,
            'path_scan' => 5,
        ],
        'recent_ips' => ['1.2.3.4', '5.6.7.8'],
        'domains' => ['example.com'],
    ],
    'domain' => 'example.com',
    'attack_type' => 'rate_limit',
    'attacker_ip' => '1.2.3.4',
    'timestamp' => 1738742400,
    'reason' => '请求频率过高',
    'all_signals' => [],
]
```

字段可能为空；监听方必须使用安全默认值，不得假设某个域名或 IP 一定存在。

## 攻击恢复负载

`Weline_Server::security::attack_recovered` 的 `DataObject` 包含：

```php
[
    'domains' => ['example.com'],
    'started_at' => 1738742400,
    'recovered_at' => 1738742700,
    'duration' => 300,
]
```

## 攻击类型

| 类型 | 说明 |
|---|---|
| `rate_limit` | 请求频率超过限制 |
| `path_scan` | 路径扫描行为 |
| `malicious_pattern` | SQL 注入、XSS 等恶意特征 |
| `bad_user_agent` | 恶意 User-Agent |
| `protected_path` | 命中受保护路径 |
| `slowloris` | Slowloris 慢连接攻击 |

## 监听示例

```xml
<!-- etc/event.xml -->
<event name="Weline_Server::security::attack_detected">
    <observer name="Vendor_Module::edge_attack_handler"
              instance="Vendor\Module\Observer\EdgeAttackHandler"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```

恢复处理使用 `Weline_Server::security::attack_recovered`。观察者从 `$event->getData('data')` 取得 Server 发布的 `DataObject`。

## Adapter 契约

Adapter 通过编译型 `cache.edge_adapter.*` Provider Registry 发布，并实现 `Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface` 或兼容契约 `Weline\Cdn\Api\AdapterInterface`。攻击模式相关签名为：

```php
public function enableAttackMode(
    string $zoneId,
    array $credentials,
    array $attackData = [],
): array;

public function disableAttackMode(
    string $zoneId,
    array $credentials,
): array;

public function supportsAttackMode(): bool;
```

Cdn 只会对 `supportsAttackMode() === true` 的适配器调用开启和关闭方法。Cloudflare 当前支持攻击模式；WLS Memory 只是本地缓存适配器，返回 `supportsAttackMode() === false`。

Adapter 注册、编译和 WLS 重载流程见 [Weline_Cdn 模块扩展文档](../../extends.md)。

## 相关实现

- `Weline\Server\Cron\AttackSignalMonitor` - 发布 Server-owned 检测/恢复事件
- `Weline\Server\Service\AttackSignalFileService` - 攻击信号和攻击模式状态
- `Weline\Cdn\Observer\AttackSignalHandler` - 开启 CDN 攻击防护
- `Weline\Cdn\Observer\AttackRecoveryHandler` - 关闭 CDN 攻击防护
- `Weline_Cdn/etc/event.xml` - 权威事件监听和一版兼容别名
