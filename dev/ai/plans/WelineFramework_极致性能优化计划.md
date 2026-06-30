# WelineFramework 极致性能优化计划

> 核心边界：`Weline_* -> Weline_Framework` 属于正常依赖，不作为模块耦合问题处理。重点治理其他业务模块之间的横向依赖、循环依赖、隐式依赖，以及 WLS 常驻运行时的请求热路径开销。

## 1. 优化目标

生产环境的 WLS 请求热路径应尽可能满足以下条件：

- 不扫描模块目录。
- 不解析 XML、JSON 或 Composer 元数据。
- 不执行反射构造和反射式状态清理。
- 不执行 `filemtime()` 等运行时文件变更检查。
- 不动态猜测类名、Observer、Plugin 或 QueryProvider。
- 不启动无必要的 Session。
- 不记录全量性能阶段数据，只进行采样。
- 不在多个全局容器中重复保存请求状态。
- 所有路由、DI、事件、插件和模块清单均在部署阶段编译。
- 请求结束后能够彻底释放请求级状态，避免常驻 Worker 数据污染。

建议的最终请求链路：

```text
Socket
  -> HTTP Parser
  -> RequestContext
  -> Compiled Router
  -> Compiled Container
  -> Controller / Application Service
  -> Structured Response
  -> Socket
```

---

## 2. 模块依赖边界

### 2.1 合法依赖

```text
Weline_A -> Weline_Framework
Weline_A -> Weline_B\Api
Integration_AB -> Weline_A\Api + Weline_B\Api
```

### 2.2 应禁止的依赖

```text
Weline_A -> Weline_B\Model
Weline_A -> Weline_B\Controller
Weline_A -> Weline_B\Helper
Weline_A -> Weline_B\Setup
Weline_A -> Weline_B\View
Weline_A <-> Weline_B
Weline_A 未声明依赖却直接引用 Weline_B
Weline_A 直接读写 Weline_B 所拥有的数据表
```

### 2.3 跨模块流程的正确组织方式

业务模块不应相互双向引用。需要同时协调多个领域时，应增加上层编排模块：

```text
Checkout
  |- Order\Api
  |- Payment\Api
  `- Shipping\Api
```

可选集成应放入桥接模块：

```text
Customer
Payment
CustomerPaymentIntegration
  |- Customer\Api
  `- Payment\Api
```

### 2.4 依赖检测

构建三张依赖图：

1. Composer 安装依赖图。
2. PHP 运行时代码引用图。
3. Event、Plugin、Hook、QueryProvider、模板和数据访问扩展图。

删除所有 `Weline_* -> Weline_Framework` 边后，对剩余图执行强连通分量检测。发现两个及以上业务模块形成环时，CI 直接失败。

---

## 3. 第一阶段：建立真实性能基线（P0）

在优化代码之前，增加以下固定测试路由：

| 路由 | 用途 |
|---|---|
| `/__bench/raw` | 服务器直接返回文本，不经过完整框架 |
| `/__bench/framework` | 路由、容器、Controller 返回文本 |
| `/__bench/di` | 构造 10～20 层依赖对象 |
| `/__bench/json` | 返回固定 JSON |
| `/__bench/session` | 读取和写入一次 Session |
| `/__bench/db` | 按主键查询一条固定数据 |
| `/__bench/template` | 渲染固定模板 |
| `/__bench/event` | 触发固定数量监听器 |

### 测试矩阵

| 维度 | 建议值 |
|---|---|
| Worker 数量 | 1、CPU 物理核心数 |
| 并发数 | 1、10、100、500 |
| 请求总数 | 10 万、100 万 |
| 模式 | 无 Session、Session、数据库、模板、事件 |
| 指标 | QPS、P50、P95、P99、CPU、RSS、错误率 |

### 基准执行示例

```bash
composer install --no-dev -o
php bin/w setup:upgrade
composer dump-autoload -a

php bin/w server:start perf -p 9981 -c 1

php bin/w server:benchmark \
  -p 9981 \
  --path /__bench/framework \
  -c 100 \
  -n 100000
```

### 结果保留

```text
benchmark-before.json
benchmark-after.json
profile-before.svg
profile-after.svg
memory-before.json
memory-after.json
```

### 基础验收标准

- P99 不得退化超过 3%。
- 错误率必须为 0。
- 100 万请求后，Worker RSS 相对稳定值增长不超过 5%。
- 无 Session 路由不能产生 Session IO 或 `Set-Cookie`。
- 生产请求不能出现目录扫描、XML 解析和反射构造。

---

## 4. 第二阶段：压缩 WLS 每请求热路径（P0）

### 4.1 删除请求内反射式 Reset

避免使用以下方式清理静态状态：

```php
$ref = new ReflectionClass(ProcessUrlCache::class);
$prop = $ref->getProperty('staticCache');
$prop->setValue(null, null);
```

改为显式方法：

```php
final class ProcessUrlCache
{
    public static function resetRequestState(): void
    {
        self::$staticCache = null;
    }
}
```

部署阶段生成固定重置表：

```php
return [
    Request::class => 'clearStaticUrlPathCache',
    ProcessUrlCache::class => 'resetRequestState',
    AclService::class => 'resetRequestCache',
];
```

生产请求中禁止：

- `ReflectionClass`
- `ReflectionProperty`
- `setAccessible()`
- 动态探测重置器
- 每请求注册新的 reset callback

更理想的方向是将所有请求状态放入 `RequestScope`，请求结束后直接丢弃整个作用域。

### 4.2 性能追踪改为采样模式

建议增加三级模式：

```php
enum TraceMode
{
    case Off;
    case Sampled;
    case Full;
}
```

生产默认策略：

```text
99.9% 请求：只记录总耗时
0.1% 请求：记录完整阶段耗时
慢请求：强制记录完整阶段耗时
开发环境：全量记录
```

非采样请求只保留：

```php
$startedAt = hrtime(true);
```

以下逻辑仅在采样时执行：

- 阶段性时间戳。
- 生命周期 Span。
- 性能响应头。
- URI、IP、重定向等诊断字段。
- 请求级详细日志。
- 调试面板。
- Telemetry 注入。

### 4.3 减少请求状态重复同步

避免在以下位置重复保存同一份 URI、语言、货币、区域和方法信息：

```text
$_SERVER
WelineEnv
RequestContext
Request 对象
Router 状态
```

建议使用唯一数据源：

```php
final class RequestContext
{
    public string $uri;
    public string $method;
    public string $area;
    public string $locale;
    public string $currency;
    public ?int $websiteId;
    public Request $request;
    public Response $response;
}
```

新模块直接依赖 `RequestContext`。旧模块通过只读兼容层访问 `WelineEnv` 或 `$_SERVER`。

### 4.4 修复 `App::Env()` 假值写入问题

不能使用值的真假性判断当前操作是读还是写，否则 `false`、`0`、空字符串等值可能无法正确写入。

建议实现：

```php
public static function Env(string $key = '', mixed $value = null): mixed
{
    self::$_env ??= Env::getInstance();

    if ($key === '') {
        return self::$_env;
    }

    if (func_num_args() === 1) {
        return self::$_env->getConfig($key);
    }

    return self::$_env->setConfig($key, $value);
}
```

这既是正确性问题，也是常驻 Worker 请求状态无法复位的潜在风险。

---

## 5. 第三阶段：DI 容器完全编译化（P0）

### 5.1 生产环境禁止反射回退

增加严格编译模式：

```php
'container' => [
    'strict_compiled' => true,
]
```

生产环境未找到已编译工厂时直接失败：

```php
if (!isset($compiledFactories[$class])) {
    throw new UncompiledServiceException($class);
}
```

开发环境才允许反射回退。

### 5.2 生成直接调用代码

避免在热路径中解析通用参数数组、闭包或反射元数据。生成明确的构造代码：

```php
final class CompiledContainer
{
    public function getUserService(): UserService
    {
        return $this->requestScope->getOrCreate(
            UserService::class,
            fn() => new UserService(
                $this->getUserRepository(),
                $this->getLogger()
            )
        );
    }
}
```

进一步优化时可去除闭包，生成独立私有工厂方法。

### 5.3 明确对象生命周期

```text
process：Worker 生命周期共享
request：单个请求内共享
transient：每次获取都创建
```

构建阶段禁止：

```text
process singleton -> request scoped service
```

否则进程级单例可能持有当前用户、Request、Session、语言、币种或权限数据。

### 5.4 优化 Fiber 请求作用域

不建议使用：

```php
WeakMap<Fiber, array<class-string, object>>
```

建议改为：

```php
WeakMap<Fiber, RequestScope>
```

```php
final class RequestScope
{
    private array $instances = [];

    public function get(string $id): ?object
    {
        return $this->instances[$id] ?? null;
    }

    public function set(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function clear(): void
    {
        $this->instances = [];
    }
}
```

请求结束后删除整个 `RequestScope`，避免逐个对象清理和反复写入 `WeakMap`。

---

## 6. 第四阶段：路由编译与 Session 延迟启动（P0）

### 6.1 生产环境取消 `filemtime()` 检查

生产模式应一次加载生成路由表：

```php
private static function loadGeneratedRouterFile(string $file): array
{
    return self::$cache[$file] ??= require $file;
}
```

生产代码更新通过 Worker reload 生效。开发环境才保留文件时间检查。

### 6.2 编译路由 Trie 或分段哈希表

生成结构示例：

```php
return [
    'GET' => [
        'api' => [
            'user' => [
                'profile' => [
                    'service_id' => 142,
                    'method' => 'profile',
                    'session' => false,
                    'acl' => false,
                    'events' => false,
                    'telemetry' => false,
                ],
            ],
        ],
    ],
];
```

匹配过程：

```text
HTTP Method
  -> Area
  -> Path Segment 1
  -> Path Segment 2
  -> Route Metadata
```

部署阶段同时确定：

- Controller Service ID。
- Controller Method。
- 参数转换器。
- 是否需要 Session。
- 是否需要 ACL。
- 是否需要模板。
- 是否需要事件。
- 是否需要 Telemetry。
- 是否允许页面缓存。

### 6.3 Session 完全惰性启动

```php
if ($route->requiresSession) {
    $session->start();
}
```

区分三种模式：

```text
none：完全不接触 Session
read：读取后尽早释放锁
write：请求结束时提交
```

REST API、健康检查、公开页面和静态响应默认不启用 Session。

---

## 7. 第五阶段：模块清单与 Composer 自动加载优化（P0/P1）

### 7.1 部署时生成模块清单

```php
return [
    'enabled' => [...],
    'boot_order' => [...],
    'dependencies' => [...],
    'events' => [...],
    'queries' => [...],
    'routes' => [...],
    'plugins' => [...],
    'resets' => [...],
];
```

生产请求期间不得扫描：

```text
app/code/*
register.php
composer.json
event.xml
extends.php
hook.php
```

### 7.2 每个模块只注册自己的命名空间

```json
{
  "require": {
    "php": "^8.4",
    "weline/framework": "^x.y"
  },
  "autoload": {
    "psr-4": {
      "Weline\\Customer\\": ""
    }
  }
}
```

完成迁移后，应删除根项目中的广域自动加载兜底：

```json
"Weline\\": ["app/code/Weline/"],
"": ["app/code/", "generated/code/"]
```

这些兜底会扩大文件系统搜索范围，并隐藏未声明的模块横向依赖。

### 7.3 Composer 生产配置

```json
{
  "config": {
    "optimize-autoloader": true,
    "classmap-authoritative": true,
    "apcu-autoloader": true
  }
}
```

构建顺序：

```bash
composer install --no-dev
php bin/w setup:upgrade
php bin/w compile
composer dump-autoload -a
```

生成类必须先完成，再生成 authoritative classmap。

---

## 8. 第六阶段：事件、Plugin、AOP 与 QueryProvider 编译（P1）

### 8.1 事件表编译

```php
return [
    'request.before' => [
        [101, 'execute'],
        [205, 'beforeRun'],
    ],
];
```

无监听器时立即返回：

```php
if (!isset($listeners[$event])) {
    return $payload;
}
```

### 8.2 热路径要求

- 不读取 `event.xml`。
- 不扫描目录。
- 不执行反射。
- 尽量不使用 `call_user_func_array()`。
- 无监听者时不创建事件对象。
- 无 Plugin 的类不生成拦截器。
- Plugin 顺序、重复、冲突和循环在编译阶段报错。

### 8.3 QueryProvider 编译

```php
return [
    'customer.get' => Weline\Customer\Query\GetCustomer::class,
    'order.get' => Weline\Order\Query\GetOrder::class,
];
```

Provider 缺失、重复或循环依赖必须在构建阶段失败，不允许请求期间动态搜索。

---

## 9. 第七阶段：ORM 和数据库优化（P1）

### 9.1 元数据编译

- Attribute 和 Schema 反射只在部署阶段执行。
- Model 字段、类型、主键和关系生成 PHP 数组。
- 常用查询生成专用 Hydrator。
- 减少热路径中的 `__call()`、动态字段拼接和重复类型转换。

### 9.2 查询优化

- 只查询实际需要的字段。
- 批量查询代替循环查询。
- 防止 N+1 查询。
- 常用查询缓存 Prepared Statement，但必须限制容量。
- 数据库连接支持断线检测和自动重连。
- 连接池必须区分 Worker、Fiber 与事务边界。

### 9.3 基准拆分

分别测试：

```text
PDO 原生查询
Weline Query Builder
Weline Model Hydrate
Model + Plugin
Model + Event
Model + Plugin + Event
```

这样才能确定开销来自数据库、ORM、容器、事件还是拦截器。

### 9.4 并发注意事项

Fiber 并发并不等于数据库 IO 自动异步。阻塞 PDO 仍会阻塞执行线程。需要评估：

- 真正的异步数据库驱动。
- 独立连接池。
- 查询调度器。
- 每 Fiber 独立事务上下文。
- 事务期间禁止连接被其他 Fiber 复用。

---

## 10. 第八阶段：Response 与服务器层优化（P1/P2）

避免先拼接完整 HTTP 字符串，再由 Server 二次解析或转换。

建议直接返回结构化响应：

```php
final readonly class ServerResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public array $cookies,
        public string|StreamInterface $body,
    ) {
    }
}
```

进一步优化：

- 文件响应使用 `sendfile`。
- 大响应使用流式写入。
- 避免复制完整 Body。
- Header 名称在编译或初始化阶段标准化。
- 常用状态行和 Header 使用预生成结果。
- JSON 响应避免中间数组重复复制。

---

## 11. 第九阶段：OPcache、Preload、事件循环与 JIT（P2）

这些工作应在热路径动态行为清理后再进行。

建议验证的 OPcache 配置：

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.max_accelerated_files=50000
opcache.interned_strings_buffer=32
```

生产不可变部署中，可关闭时间戳检查，通过 Worker reload 更新代码。

### JIT 测试矩阵

```text
JIT disabled
JIT tracing
```

不能预设 JIT 一定更快。Web 框架主要包含数组、字符串、动态调用和 IO，最终应以以下数据判断：

- P99。
- CPU time/request。
- RSS。
- Worker 稳定性。
- 100 万请求后性能是否漂移。

---

## 12. 安全与稳定性改造

### 12.1 请求状态串扰

重点检查以下对象是否被错误注册为进程级单例：

- Request。
- Response。
- Session。
- 当前用户。
- ACL 上下文。
- Locale。
- Currency。
- Website / Store。
- 当前路由参数。
- 数据库事务上下文。

请求结束必须在 `finally` 中释放整个请求作用域。

### 12.2 自动加载与命名空间污染

根级空命名空间和广域 `Weline\` 映射可能导致：

- 未声明依赖被隐藏。
- 类名冲突。
- 错误模块类被优先加载。
- 生产部署与开发环境表现不一致。

应迁移到每个模块独立 PSR-4 映射。

### 12.3 Session 锁与并发

- 只读 Session 应尽早关闭写锁。
- 无 Session 路由禁止启动 Session。
- 长请求不得长时间持有 Session 锁。
- Session 数据不得缓存在进程级单例中。

### 12.4 数据库事务串扰

- 事务状态必须属于请求或 Fiber。
- 事务连接不得跨请求复用未提交状态。
- 异常路径必须回滚。
- Worker reset 时验证连接是否仍处于事务状态。

### 12.5 缓存键隔离

缓存键必须明确包含必要维度：

```text
website_id
store_id
locale
currency
customer_group
permission_scope
module_version
```

缺少维度可能造成跨租户、跨语言或跨权限数据泄漏。

---

## 13. 优先级总表

| 优先级 | 优化点 | 预期价值 |
|---|---|---|
| P0 | 建立基准路由和自动化性能回归 | 所有后续优化的基础 |
| P0 | 删除请求内 Reflection reset | 高 |
| P0 | 性能监控改为采样 | 高 |
| P0 | 减少 `WelineEnv` 和全局状态重复同步 | 高 |
| P0 | 修复 `App::Env()` 假值写入 | 正确性关键 |
| P0 | 生产 DI 强制使用已编译工厂 | 高 |
| P0 | 明确 process/request/transient 生命周期 | 高，兼顾安全 |
| P0 | `RequestScope` 替代散乱静态请求状态 | 高 |
| P0 | Router 启动一次，生产取消 `filemtime()` | 高 |
| P0 | Session 按路由惰性启动 | 高 |
| P0 | 请求结束使用 `finally` 清理作用域 | 安全关键 |
| P1 | 编译事件、Plugin、QueryProvider | 中高 |
| P1 | 编译模块清单和启动顺序 | 中高 |
| P1 | 模块横向依赖和循环检测 | 中高 |
| P1 | ORM 元数据与 Hydrator 编译 | 业务路由高 |
| P1 | 结构化 Response 和流式响应 | 中 |
| P1 | Composer authoritative classmap | 中 |
| P2 | OPcache preload | 条件性 |
| P2 | 事件循环底层替换 | 需基准验证 |
| P2 | JIT 和微观语法优化 | 需实测 |

---

## 14. 推荐提交顺序

### Sprint 1：基准和正确性

```text
1. benchmark: 增加固定基准路由与结果归档
2. runtime: 修复 App::Env() 假值写入
3. runtime: 请求结束统一使用 finally 清理
4. test: 增加 100 万请求内存稳定性测试
```

### Sprint 2：WLS 热路径

```text
1. runtime: 删除 Reflection reset
2. runtime: timing 和 telemetry 改为采样
3. runtime: RequestContext 成为唯一请求状态源
4. session: 路由级惰性启动
```

### Sprint 3：编译容器

```text
1. container: 增加 strict_compiled 模式
2. compiler: 生成直接构造工厂
3. scope: 引入 process/request/transient 生命周期
4. scope: 增加非法生命周期依赖检测
```

### Sprint 4：路由和模块编译

```text
1. router: 编译 Trie 或分段哈希路由
2. router: 生产取消 filemtime
3. module: 生成不可变模块清单
4. module: 增加横向依赖和循环检测
```

### Sprint 5：事件、Plugin 和 QueryProvider

```text
1. event: 生成静态监听表
2. plugin: 生成静态拦截链
3. query: 生成 QueryProvider 映射
4. compiler: 构建阶段检测冲突和循环
```

### Sprint 6：ORM、Response 和底层调优

```text
1. orm: 编译 Model 元数据和 Hydrator
2. db: 增加连接健康检查和事务隔离
3. response: 返回结构化 ServerResponse
4. server: sendfile 和流式响应
5. runtime: OPcache、Preload 和 JIT 对照测试
```

---

## 15. 完成标准

一个优化阶段只有同时满足以下条件才算完成：

- 有修改前后的基准数据。
- 有 P50、P95、P99，而不只看平均 QPS。
- 有 CPU 和 RSS 数据。
- 有长时间运行或至少 100 万请求稳定性数据。
- 有异常路径和请求清理测试。
- 有并发 Session 和事务隔离测试。
- 无模块横向循环依赖。
- 生产请求热路径无 XML、目录扫描、反射和动态文件检查。
- 所有生产对象都具有明确生命周期。

---

## 16. 参考地址

- WelineFramework：<https://github.com/Aiweline/WelineFramework>
- Composer 自动加载优化：<https://getcomposer.org/doc/articles/autoloader-optimization.md>
- PHP OPcache 配置：<https://www.php.net/manual/en/opcache.configuration.php>

> 本计划中的性能收益需要在可运行 WLS 的环境中通过基准测试、火焰图和长时间稳定性测试验证，不预设未经测量的 QPS 提升比例。
