# CDN 攻击检测信号事件

## 事件名称

`Weline_Cdn::security::attack_detected`

## 概述

当 WLS (Weline Server) Dispatcher 检测到攻击并发送信号时触发。CDN 模块收到此事件后，将广播到各 CDN 服务商开启攻击防护模式。

## 触发流程

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           攻击检测与防护流程                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   1. Dispatcher 探测攻击                                                     │
│      ├── 频率限制检测                                                        │
│      ├── 路径扫描检测                                                        │
│      ├── 恶意特征检测                                                        │
│      ├── 恶意 User-Agent 检测                                               │
│      ├── 保护路径检测                                                        │
│      └── Slowloris 检测                                                     │
│                                                                             │
│   2. 添加攻击信号头 (X-Weline-Attack-Signal)                                 │
│      └── 信号包含：类型、域名、IP、时间、原因                                   │
│                                                                             │
│   3. Server 模块监听 App::run_before 事件                                    │
│      └── AttackSignalObserver 解析 Header                                   │
│                                                                             │
│   4. 调用 CDN 事件 (Weline_Cdn::security::attack_detected)                  │
│      └── 传递攻击信号和摘要信息                                               │
│                                                                             │
│   5. CDN 模块处理                                                            │
│      ├── AttackSignalHandler 接收信号                                       │
│      ├── 判断攻击严重程度                                                    │
│      ├── 获取域名关联的 CDN 账户                                             │
│      └── 向各 CDN 服务商 API 发送开启攻击防护模式请求                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## 事件数据结构

```php
$eventData = new DataObject([
    'signal' => [
        'type' => 'rate_limit',      // 攻击类型
        'domain' => 'example.com',   // 被攻击域名
        'ip' => '1.2.3.4',           // 攻击者IP
        'timestamp' => 1738742400,   // 检测时间戳
        'reason' => '请求频率过高',   // 攻击原因描述
    ],
    'summary' => [
        'total' => 15,               // 总攻击次数
        'by_type' => [               // 按类型分组
            'rate_limit' => 10,
            'path_scan' => 5,
        ],
        'recent_ips' => [            // 最近攻击IP
            '1.2.3.4',
            '5.6.7.8',
        ],
    ],
    'domain' => 'example.com',       // 被攻击域名
    'attack_type' => 'rate_limit',   // 攻击类型
    'attacker_ip' => '1.2.3.4',      // 攻击者IP
    'timestamp' => 1738742400,       // 时间戳
    'reason' => '请求频率过高',       // 原因
]);
```

## 攻击类型

| 类型 | 说明 |
|------|------|
| `rate_limit` | 请求频率超过限制 |
| `path_scan` | 路径扫描行为（大量404） |
| `malicious_pattern` | 恶意请求特征（SQL注入、XSS等） |
| `bad_user_agent` | 恶意 User-Agent |
| `protected_path` | 访问受保护路径 |
| `slowloris` | Slowloris 慢速攻击 |

## 使用示例

### 触发事件（Server 模块内部使用）

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\DataObject\DataObject;

$eventsManager->dispatch('Weline_Cdn::security::attack_detected', new DataObject([
    'signal' => $signal,
    'summary' => $summary,
    'domain' => $signal['domain'],
    'attack_type' => $signal['type'],
    'attacker_ip' => $signal['ip'],
    'timestamp' => $signal['timestamp'],
    'reason' => $signal['reason'],
]));
```

### 监听事件（第三方模块可扩展）

```xml
<!-- etc/event.xml -->
<event name="Weline_Cdn::security::attack_detected">
    <observer name="MyModule::custom_attack_handler" 
              instance="MyModule\Observer\CustomAttackHandler" 
              disabled="false" 
              shared="true" 
              sort="100"/>
</event>
```

## CDN 适配器要求

CDN 适配器需要实现 `enableAttackMode` 方法：

```php
interface AttackModeInterface
{
    /**
     * 开启攻击防护模式
     *
     * @param string $zoneId CDN 区域ID
     * @param array $data 攻击数据
     * @return array ['success' => bool, 'message' => string]
     */
    public function enableAttackMode(string $zoneId, array $data): array;
}
```

## 相关文件

- `Weline\Server\Security\AttackDetector` - 攻击探测器
- `Weline\Server\Security\AttackSignalService` - 攻击信号服务
- `Weline\Server\Observer\AttackSignalObserver` - Server 模块事件监听
- `Weline\Cdn\Observer\AttackSignalHandler` - CDN 模块事件处理
