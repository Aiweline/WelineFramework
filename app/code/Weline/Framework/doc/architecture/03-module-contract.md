# 模块契约与零耦合规则

## 当前合规快照（2026-07-12）

`architecture:check` 当前覆盖 83 个模块、3,955 个 PHP 文件和 7,169 个 PHP 引用点；`Framework -> Module`、未声明模块引用、实际依赖循环、跨模块内部 API 引用、请求可达原生 `sleep/usleep` 以及 Composer/module manifest gate 均为 0。

这是静态契约检查结果，不替代“仅安装 Framework 和本模块 `requires` 即可独立启动”以及“移除任一 `optional` 不产生类加载错误”的运行期隔离验收。新增或调整模块契约后仍须重新执行 `framework:compile` 与 `architecture:check`。

## 权威清单

每个已注册模块必须提供 `etc/module.php`：

```php
return [
    'name' => 'Vendor_Module',
    'version' => '2.0.0',
    'requires' => ['Weline_Framework' => '^2.0'],
    'optional' => ['Vendor_Peer' => '^1.0'],
    'provides' => [Contract::class => Implementation::class],
];
```

- `name/version/requires/optional/provides` 皆以该文件为权威来源。
- `register.php` 参数只是第三方模块迁移桥，生产启动不得反向解析它。
- 架构门禁不把 `register.php` / `etc/module.php` 中的 `Vendor_Module` 资源字符串当作 PHP 内部 API；依赖以 `etc/module.php` 为准，两类文件里的真实 PHP 类引用仍然必须经过边界检查。
- Composer `require/suggest` 必须与 `requires/optional` 同步；PHP 基线为 `^8.4`。
- `requires` 的目标必须存在、启用、版本匹配且整个声明图无环。

## 跨模块规则

1. 模块可直接使用 `Weline\Framework\*`。
2. 使用另一模块时，必须声明 `requires` 或 `optional`。
3. PHP 调用只能面向目标模块 `Weline\<Module>\Api\*` 契约。
4. 禁止跨模块调用对方 `Service/Model/Controller/Helper/Block/Observer/Taglib`。
5. 可选集成位于 `Integration/<Target>`，没有目标模块时不能发生类加载错误。
6. Setup/Migration 只操作本模块 schema；跨模块协作使用 Event、Hook、Query 或 Queue。
7. Framework 不得引用任何具体模块；共享缓存、Session、日志、Runtime 和 BinQuery 鉴权都由 Provider 注入。

## 服务生命周期

`ContainerInterface` 支持四种显式范围：

- `process`：不可变、无用户态的进程级服务。
- `request`：请求结束后必须销毁。
- `fiber`：只在当前 Fiber 内共享，不得跨并发请求。
- `prototype`：每次解析创建新实例。

Weline 自带模块不得在业务热路径中把 ObjectManager 当服务定位器。ObjectManager 仅作为第三方兼容桥，并由架构门禁逐步缩小 allowlist。

Phase 1 容器清单由 `ContainerServiceCatalog` 显式维护，不从文件夹扫描或从
ObjectManager 运行记录反推服务。`framework:compile` 生成
`generated/framework/container.php`，产物不含时间戳或随机顺序，相同输入必须得到
相同 SHA-256。当前每种范围各用一个真实 Framework 服务验证：

- `ServiceProviderRegistry` 是 `process`；
- `Response` 是 `request`；
- `RequestScope` 是 `fiber`；
- `DataObject` 是 `prototype`。

`request` 和主 Fiber 的实例以 request id 分区，并由 `RequestContext` cleanup
回调删除；并发 Fiber 使用 `WeakMap` 隔离。`prototype` 永不进容器缓存。
PROD/WLS 不允许在编译索引之外回退 ObjectManager；DEV 桥接只用于分批迁移。
添加服务到清单前必须先定义生命周期，并保证所有必需构造依赖也在清单中；
未声明依赖和循环依赖使编译直接失败。

第一个生产代码迁移点是 `Response::setData()` 的临时 `DataObject`：它现在经
编译容器 `create()` 走 prototype factory，不再进入 ObjectManager 动态反射。
这个迁移不改变 Response 的 JSON/XML/string 选择和 body 语义。

## Provider 示例

Framework 定义 `RequestResetterInterface`；模块在 `Api/Runtime/RequestResetter.php` 实现，并通过 capability 注册：

```php
'provides' => [
    'request_resetter.Vendor_Module' => \Vendor\Module\Api\Runtime\RequestResetter::class,
],
```

Framework 只枚举 `request_resetter.` capability，不知道模块名或内部清理对象。

Setup Provider 也必须自述所属模块。例如 EAV schema Provider 通过
`EavSchemaProviderInterface::ownerModuleName()` 返回自身 `Vendor_Module`，Framework 只把该值传入通用 DDL 事件，不得硬编码任何具体模块名。

运行期主题选择使用 Framework 的 `ThemeContextProviderInterface`。Theme 模块通过
`provides` 注册实现，Meta、Backend 等中立消费者只经 `RuntimeProviderResolver` 获取
`object` 并读取必要身份，不得引用 Theme Service 或 Model。该契约只负责解析已经存在的
主题上下文，不允许 Framework 反向探测或加载任何具体主题模块。

## QueryProvider 编译契约

QueryProvider 是跨模块读契约，不是运行期扫描插件。`framework:compile` 会在控制面
实例化 Provider 一次，合并旧 descriptor 和 BinQuery Attribute，然后生成
`generated/framework/query_providers.php` format v2：

- `providers`：真正执行 operation 时使用的类定义；
- `descriptors`：以 provider name 为 key 的最终不可变 descriptor；
- `operations`：`provider -> operation -> descriptor` 哈希索引；
- `external_areas`：按 `frontend/backend` 分区的 BinQuery provider、operation 和 summary 索引。

Provider 必须保持 `getProviderName()` 与 descriptor 中的 `provider` 一致，operation name
在本 Provider 内唯一。descriptor 只能包含有限浮点数、标量和数组，禁止 Closure、
资源和服务对象；违反约束必须让编译失败，不能生成延迟 Provider。

PROD/WLS 只从 format v2 读 descriptor 和 area 索引，不得读 Provider PHP、反射
Attribute 或线性遍历 operation。DEV 可在索引缺失/过期时使用动态迁移桥；
该桥不是生产 fallback。Provider、descriptor、Attribute 或 i18n 文案改变后都必须
重新执行 `php bin/w framework:compile`。

## 模板缓存策略 Provider

模板 Hook 的缓存语义也由拥有输出的模块自述。模块在 `Api/View` 实现
`TemplateCachePolicyProviderInterface`，并以唯一 capability 注册：

```php
'provides' => [
    'template_cache_policy.Vendor_Module' => \Vendor\Module\Api\View\TemplateCachePolicyProvider::class,
],
```

Provider 只能返回纯标量描述符，不得携带 Closure、服务对象、动态类调用，也不得在请求热路径读取 ORM、Session 或 ObjectManager。`framework:compile` 会校验 Provider 冲突、上下文定义和聚合完整性，并生成不可变的 `generated/framework/template_cache_policies.php`：

- Worker READY 后和模板热路径只读取编译数组，不实例化模块 Provider。
- 未声明、非法或聚合不完整的输出 fail closed 为“不缓存、正常渲染”。
- 单文件和聚合缓存 key 都包含策略 digest 与 Hook Manifest digest，策略或 Hook 变化不会复用旧 L2。
- 动态身份、请求路径和语言上下文默认禁止跨请求异步 SWR；只有显式静态策略才能开启。
- `render_once_group` 使用 request-scoped Context，禁止用进程级 `$GLOBALS` 保存渲染状态。

Hook 名、模板路径等资源描述仍会进入架构扫描；出现跨模块资源引用时，应把归属下沉到资源拥有模块或编译期 Manifest，不能通过字符串变形或放宽扫描隐藏耦合。

## Worker 视图预热贡献

持久 Worker 的高频模板、Tag 模板、静态文件与模块所有的 FPC 公开路径使用 Framework 的
`ViewWarmupContributionProviderInterface` 提交数据描述：

```php
'provides' => [
    'view_warmup_contribution.Vendor_Module'
        => \Vendor\Module\Api\View\ViewWarmupContributionProvider::class,
],
```

- 资源拥有模块返回只读 `ViewWarmupContribution`，不得提交 Closure、对象图或动态回调。
- `fpcPaths` 是模块拥有的公开绝对 URL path 列表，必须以 `/` 开头，不得携带 scheme、authority、空白或跨模块业务路径。
- 模块只能通过 `etc/module.php` 的 `view_warmup_contribution.<Module_Name>` capability 贡献；`framework:compile` 将 Provider 写入编译索引，`ViewWarmupContributionRegistry` 在 Worker 启动时只解析一次、合并去重并缓存不可变结果。
- Framework/Theme 核心默认只预热首页 `/`；任何额外 FPC 路径都必须由资源拥有模块通过上述编译 Provider 显式贡献。
- Theme 只负责执行预热，不得硬编码 Customer、Order 等业务模块路径，也不得在请求阶段枚举 Provider、扫描目录或查询 ObjectManager。
- 预热贡献不再经运行期 Server 事件注册，Framework、Theme 和 Server 都不得硬编码 `WeShop_*` 或其他可选业务模块的预热路径。
- 静态文件使用仓库相对路径，模板和 Hook 资源使用现有模块资源标识；删除资源时必须同步 Provider 并重新编译。

具体资源格式和生命周期见 `Weline_Theme/doc/worker-view-warmup-contributions.md`。

## 缓存适配器 Provider

Framework 只内置 file、redis、memcached、apcu 等中立驱动。运行时专属适配器由模块以
`cache.adapter_provider.*` capability 发布 `CacheAdapterProviderInterface`，Provider 只返回
不可变 `CacheAdapterDescriptor(driver, creatorClass)`；禁止 Closure、服务对象或请求期扫描。

`AdapterFactory` 在冷初始化时读取编译后的 `ServiceProviderRegistry`，校验无重复 driver 和
Creator 契约，并按 Provider 实现 digest 做进程级复用。Adapter 的 get/set 热路径不查询
ObjectManager、Provider Registry 或反射。可选 Provider 缺失/非法时核心 file 驱动仍可用；
显式选择相应扩展 driver 时必须明确失败。新增或修改 Provider 后执行
`php bin/w framework:compile`。

模块共享运行时状态必须按能力拆分，不能引用 Server 门面：普通原子 KV 使用
`SharedCacheStateFactoryInterface`；需要 append、批量读取和递减计数的批缓冲使用
`SharedBufferStateFactoryInterface`。两种 Factory 都是 optional Provider，调用模块必须在
Provider 缺失、类型错误或连接失败时保留自身明确的降级路径，不得用字符串类名、
`class_exists` 或动态 ObjectManager 探测具体模块。
