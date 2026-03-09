---
name: unified-query-provider
description: |
  Weline Framework 统一查询器（QueryProviderInterface）。
  模块间数据通信/查询必须使用此机制，而不是为每个查询操作创建独立事件。

  MUST use when:
  - 模块间查询/获取数据（跨模块读取）
  - 模块间操作数据（跨模块CRUD）
  - 模块间通信/数据交换
  - 需要为其他模块提供数据查询能力
  - 需要从其他模块获取信息
  - 调用其他模块的服务/接口

  Keywords (中文): 查询器, 模块间查询, 跨模块, 统一查询, 模块间通信, 模块间数据, 模块间交互,
  数据查询, 获取数据, 提供数据, 注册查询器, 调用其他模块, 从另一个模块, 模块A查模块B,
  跨模块获取, 跨模块调用, 跨模块读取, 跨模块操作, 模块间接口, 模块间服务,
  查询其他模块, 请求其他模块, 访问其他模块, 模块数据交换, 模块协作

  Keywords (English): QueryProvider, FrameworkQueryService, QueryProviderInterface, w_query,
  inter-module, cross-module, module query, module communication, module interaction,
  query from module, get data from module, call another module, access other module,
  module A query module B, cross module data, inter-module data, module interface,
  provider, operation, introspect, extends Query
globs:
  - "**/extends/module/Weline_Framework/Query/*.php"
  - "**/Service/Query/**/*.php"
alwaysApply: false
---

# 统一查询器技能 (Unified Query Provider)

## 核心原则

**模块间查询数据 → 用 QueryProvider，不用事件！**

| 场景 | 正确做法 | 错误做法 |
|------|----------|----------|
| 模块 A 想从模块 B **读数据** | B 注册 QueryProvider，A 调用 `execute(provider, operation)` | ❌ 为每个查询创建独立事件 + 观察者 |
| 模块 A 想让模块 B **做操作**（CRUD） | B 注册 QueryProvider，A 调用 `execute(provider, operation)` | ❌ 创建事件 + 观察者 |
| 某事**发生了**需通知其他模块 | ✅ 使用事件 dispatch（如订单创建后通知） | - |
| 多模块需要**协作响应**同一事件 | ✅ 使用事件 + 多个观察者 | - |

### 什么时候用事件？什么时候用查询器？

```
事件 (Event)     → 通知型："发生了什么" 或 "请响应这个动作"（可多模块监听）
查询器 (Provider) → 查询型："给我数据" 或 "帮我操作"（一对一、有返回值）
```

---

## 1. 查询器的注册方式

### 在模块中注册查询器

路径：`app/code/Vendor/Module/extends/module/Weline_Framework/Query/XxxQueryProvider.php`

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class XxxQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'xxx';  // 查询器标识，全局唯一
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getList'  => $this->getList($params),
            'getDetail' => $this->getDetail($params),
            'save'     => $this->save($params),
            'delete'   => $this->doDelete($params),
            default    => throw new \InvalidArgumentException(
                (string)__('不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'xxx',
            'name'        => __('XXX 查询'),
            'description' => __('提供 XXX 的查询和操作能力'),
            'module'      => 'Vendor_Module',
            'operations'  => [
                [
                    'name'        => 'getList',
                    'description' => __('获取列表'),
                    'params'      => [
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => __('页码')],
                    ],
                ],
                // ...更多 operation
            ],
        ];
    }

    private function getList(array $params): array { /* ... */ }
    private function getDetail(array $params): array { /* ... */ }
    private function save(array $params): array { /* ... */ }
    private function doDelete(array $params): array { /* ... */ }
}
```

### 注册后执行

```bash
php bin/w extends:rebuild   # 重建 extends 注册表
php bin/w s:up              # 或用 setup:upgrade
```

### WLS 场景补充（常见漏项）

在 WLS 常驻模式下，仅重建 extends 还不够，Worker 可能仍持有旧注册表：

```bash
php bin/w extends:rebuild
php bin/w server:reload
```

若遇到 `未注册的查询器：xxx`，优先按以上两步处理，再检查 provider 文件位置与 `getProviderName()` 是否正确。

---

## 2. 调用查询器

### 方式一：w_query() 全局函数（推荐 ⭐）

**最简洁的调用方式**，对应前端 JS 的 `window.w_query()`：

```php
// 查询 Widget 列表
$widgets = w_query('widget', 'getAvailableList', ['page_type' => 'homepage']);

// 查询域名列表
$domains = w_query('websites', 'getDomainList', ['account_id' => 123]);

// 使用 CRUD 通用查询
$products = w_query('crud', 'list', [
    'model'     => 'WeShop\\Product\\Model\\Product',
    'page'      => 1,
    'page_size' => 20,
]);

// 查询所有已注册的 provider（自省）
$providers = w_query('framework', 'introspect', ['what' => 'providers']);

// 查询某 provider 的所有 operation
$ops = w_query('framework', 'introspect', ['what' => 'operations', 'provider' => 'widget']);
```

**函数签名**：
```php
function w_query(string $provider, string $operation, array $params = [], string $area = 'backend'): mixed
```

| 参数 | 类型 | 说明 |
|------|------|------|
| `$provider` | string | 提供者标识（如 `crud`、`widget`、`websites`、`saas`）或 `framework`（introspect） |
| `$operation` | string | 操作名 |
| `$params` | array | 操作参数 |
| `$area` | string | `frontend` 或 `backend`，默认 `backend` |

### 方式二：通过 FrameworkQueryService（依赖注入）

```php
use Weline\Framework\Service\Query\FrameworkQueryService;

class MyService
{
    public function __construct(
        private readonly FrameworkQueryService $queryService
    ) {}

    public function doSomething(): void
    {
        // 查询域名列表
        $domains = $this->queryService->execute('websites', 'getDomainList', [
            'account_id' => 123,
        ]);

        // 保存账号
        $result = $this->queryService->execute('websites', 'saveAccount', [
            'registrar_code' => 'gname',
            'account_name'   => '主账号',
            'api_key'        => '...',
        ]);
    }
}
```

### 方式三：通过事件 dispatch（动态路由）

```php
// dispatch '{Module}::query' 事件，ModuleQueryDispatcher 自动路由到查询器
$eventData = [
    'data' => [
        'operation' => 'getDomainList',
        'params'    => ['account_id' => 123],
        'area'      => 'backend',
    ],
];
$this->eventsManager->dispatch('Weline_Websites::query', $eventData);
$result = $eventData['data']['result'] ?? null;
```

> **Provider 名称转换规则**：`Weline_Websites` → `websites`（取模块名最后一段转小写）

### 方式四：introspect（自省 - 查看可用查询器和操作）

```php
// 列出所有查询器
$providers = w_query('framework', 'introspect', ['what' => 'providers']);

// 列出某查询器的操作
$ops = w_query('framework', 'introspect', [
    'what'     => 'operations',
    'provider' => 'websites',
]);

// 查看某操作的详细参数
$detail = w_query('framework', 'introspect', [
    'what'      => 'operation',
    'provider'  => 'websites',
    'operation' => 'getDomainList',
]);
```

---

## 3. 内置查询器

### crud（DefaultCrudProvider）

通用 CRUD 操作，支持任意 AbstractModel 子类：

```php
// 创建记录
$queryService->execute('crud', 'create', [
    'model'  => 'Weline\\Websites\\Model\\DomainRegistrarAccount',
    'data'   => ['account_name' => 'test', 'status' => 'active'],
]);

// 列表查询（分页+过滤+排序）
$queryService->execute('crud', 'list', [
    'model'       => 'Weline\\Websites\\Model\\DomainRegistrarAccount',
    'page'        => 1,
    'page_size'   => 20,
    'order_field' => 'created_at',
    'order_type'  => 'DESC',
    'filters'     => [
        ['field' => 'status', 'operator' => '=', 'value' => 'active'],
    ],
]);
```

---

## 4. 常见错误

### ❌ 错误：为每个查询操作创建独立事件

```php
// 错误！查询域名列表、查询账号、查询配置字段各建一个事件
$eventsManager->dispatch('Module::domain::query_domain_list', $eventData);
$eventsManager->dispatch('Module::domain::query_registrar_accounts', $eventData);
$eventsManager->dispatch('Module::domain::query_config_fields', $eventData);
```

### ✅ 正确：注册一个 QueryProvider，包含所有操作

```php
// 正确！一个查询器，多个操作
$queryService->execute('websites', 'getDomainList', $params);
$queryService->execute('websites', 'getRegistrarAccounts', $params);
$queryService->execute('websites', 'getConfigFields', $params);
```

### ❌ 错误：为 CRUD 操作各建事件+观察者

每个 save/delete/get 操作各建一个事件和观察者（6+ 文件），实际只需 1 个 QueryProvider 文件。

---

## 5. 关键文件

| 文件 | 路径 |
|------|------|
| **w_query() 全局函数** | `Weline/Framework/Common/functions.php` |
| QueryProviderInterface | `Weline/Framework/Service/Query/Provider/QueryProviderInterface.php` |
| FrameworkQueryService | `Weline/Framework/Service/Query/FrameworkQueryService.php` |
| QueryProviderRegistry | `Weline/Framework/Service/Query/QueryProviderRegistry.php` |
| ModuleQueryDispatcher | `Weline/Framework/Service/Query/Observer/ModuleQueryDispatcher.php` |
| DefaultCrudProvider | `Weline/Framework/Service/Query/Provider/DefaultCrudProvider.php` |
| 查询器注册位置 | `模块/extends/module/Weline_Framework/Query/*.php` |

---

## 6. 前后端对照

| 端 | 调用方式 | 示例 |
|----|----------|------|
| **前端 JS** | `window.w_query()` | `await w_query('widget', 'getAvailableList', {...})` |
| **后端 PHP** | `w_query()` | `w_query('widget', 'getAvailableList', [...])` |

前后端 API 保持一致，便于全栈开发。

---

**最后更新**: 2026-02-26
**版本**: 1.1.0
