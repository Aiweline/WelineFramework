# Service 层开发技能

## 触发关键词

Service, 服务, 业务逻辑, Service 层, ServiceInterface, Api 接口, 依赖注入, ObjectManager, 工厂, Factory, Manager, Registry, 服务类

## 适用场景

- 创建 Service 类
- Service 与 Controller、Model 协作
- 定义 Service 接口（Api 目录）
- 依赖注入最佳实践

---

## 1. Service 的定位

Service 是**业务逻辑层**的核心组件，位于 Controller 和 Model 之间：

```
Controller (请求处理层)
    ↓ 调用
Service (业务逻辑层)
    ↓ 操作
Model (数据访问层)
```

---

## 2. Service 类创建规范

### 2.1 基础 Service 结构

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;
use Weline\YourModule\Model\YourModel;

class YourService
{
    private ObjectManager $objectManager;
    private EventsManager $eventsManager;
    private YourModel $model;
    
    public function __construct(
        ObjectManager $objectManager,
        EventsManager $eventsManager,
        YourModel $model
    ) {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
        $this->model = $model;
    }
    
    public function doSomething(array $data): mixed
    {
        // 业务逻辑
    }
}
```

### 2.2 PHP 8+ 构造函数属性提升

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Service;

class YourService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly EventsManager $eventsManager,
        private readonly YourModel $model
    ) {
    }
}
```

### 2.3 完整 Service 示例（带事件）

```php
<?php
declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

class OrderService
{
    private ObjectManager $objectManager;
    private EventsManager $eventsManager;
    
    public function __construct(
        ObjectManager $objectManager,
        EventsManager $eventsManager
    ) {
        $this->objectManager = $objectManager;
        $this->eventsManager = $eventsManager;
    }
    
    public function createOrder(array $data): Order
    {
        // 1. 验证数据
        $this->validateOrderData($data);
        
        // 2. 开始事务
        $connection = $this->getOrderModel()->getConnection();
        $connection->beginTransaction();
        
        try {
            // 3. 创建订单
            $order = $this->getOrderModel()->reset();
            $order->setData($data);
            $order->save();
            
            // 4. 提交事务
            $connection->commit();
            
            // 5. 触发事件
            $eventData = [
                'order' => $order,
                'order_id' => $order->getId(),
            ];
            $this->eventsManager->dispatch('Weline_Order::order_created', $eventData);
            
            return $order;
            
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }
    
    private function getOrderModel(): Order
    {
        return $this->objectManager->getInstance(Order::class);
    }
    
    private function validateOrderData(array $data): void
    {
        if (empty($data['customer_id'])) {
            throw new \InvalidArgumentException(__('客户ID不能为空'));
        }
    }
}
```

---

## 3. Service 接口定义（Api 目录）

### 3.1 接口位置

```
app/code/Vendor/Module/Api/XxxInterface.php
```

### 3.2 接口定义示例

```php
<?php
declare(strict_types=1);

namespace Weline\TranslationService\Api;

interface TranslationServiceInterface
{
    /**
     * 翻译文本
     */
    public function translate(
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): string;

    /**
     * 批量翻译
     */
    public function batchTranslate(
        array $texts,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): array;

    /**
     * 检测语言
     */
    public function detectLanguage(string $text, ?string $providerCode = null): string;

    /**
     * 检查语言支持
     */
    public function supportsLanguage(string $languageCode, ?string $providerCode = null): bool;

    /**
     * 获取可用提供者
     */
    public function getAvailableProviders(): array;
}
```

### 3.3 接口实现

```php
<?php
declare(strict_types=1);

namespace Weline\TranslationService\Service;

use Weline\TranslationService\Api\TranslationServiceInterface;

class TranslationService implements TranslationServiceInterface
{
    public function translate(
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'auto',
        ?string $providerCode = null,
        array $options = []
    ): string {
        // 实现翻译逻辑
    }
    
    // ... 实现其他接口方法
}
```

---

## 4. Service 命名规范

### 4.1 目录结构

```
app/code/Vendor/Module/
├── Service/
│   ├── XxxService.php              # 主服务类
│   ├── XxxManager.php              # 管理器类
│   ├── XxxFactory.php              # 工厂类
│   ├── XxxRegistry.php             # 注册表类
│   ├── SubFeature/                 # 子功能目录
│   │   ├── SubService.php
│   │   └── SubFactory.php
│   └── Provider/                   # 提供者目录
│       └── AccountService.php
├── Api/
│   └── XxxInterface.php            # 服务接口
└── ...
```

### 4.2 命名模式

| 类型 | 命名模式 | 示例 |
|------|---------|------|
| 主服务类 | `{功能}Service` | `AiService`, `OrderService` |
| 管理器类 | `{功能}Manager` | `StorageManager`, `CacheManager` |
| 工厂类 | `{功能}Factory` | `ProviderFactory`, `AdapterFactory` |
| 注册表类 | `{功能}Registry` | `WidgetRegistry`, `DriverRegistry` |
| 扫描器类 | `{功能}Scanner` | `AdapterScanner`, `ModuleScanner` |
| 提供者类 | `{功能}Provider` | `LocalProvider`, `CloudProvider` |

---

## 5. 依赖注入方式

### 5.1 构造函数注入（推荐）

```php
public function __construct(
    private readonly ObjectManager $objectManager,
    private readonly EventsManager $eventsManager,
    private readonly YourModel $model
) {
}
```

### 5.2 延迟加载（懒加载）

```php
private ?ThumbnailService $thumbnailService = null;

private function getThumbnailService(): ThumbnailService
{
    if ($this->thumbnailService === null) {
        $this->thumbnailService = ObjectManager::getInstance(ThumbnailService::class);
    }
    return $this->thumbnailService;
}
```

### 5.3 通过 ObjectManager 按需获取

```php
public function createOrder(array $data): Order
{
    /** @var OrderHistory $history */
    $history = $this->objectManager->getInstance(OrderHistory::class);
    $history->setData([...]);
    $history->save();
}
```

---

## 6. Controller 调用 Service

### 6.1 构造函数注入（推荐）

```php
class Index extends FrontendController
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function index()
    {
        $orders = $this->orderService->getOrders();
        return $this->render('index', ['orders' => $orders]);
    }
}
```

### 6.2 通过 ObjectManager

```php
class Order extends BackendController
{
    public function save()
    {
        $orderService = ObjectManager::getInstance(OrderService::class);
        $order = $orderService->createOrder($this->request->getParams());
        // ...
    }
}
```

---

## 7. Service 调用 Model

### 7.1 使用 ObjectManager 获取 Model

```php
private function getOrderModel(): Order
{
    return $this->objectManager->getInstance(Order::class);
}

public function getOrder(int $orderId): Order
{
    $order = $this->getOrderModel()->reset()->load($orderId);
    if (!$order->getId()) {
        throw new \Exception(__('订单不存在'));
    }
    return $order;
}
```

### 7.2 使用 clone 避免状态污染

```php
public function findByCode(string $code): ?YourModel
{
    $model = clone $this->model;  // 克隆避免状态污染
    $model->load('code', $code);
    return $model->getId() ? $model : null;
}
```

---

## 8. 最佳实践

### 8.1 优先使用构造函数注入

- 依赖关系明确
- 便于单元测试
- IDE 类型提示友好

### 8.2 通过接口解耦

- 在 `Api/` 目录定义接口
- Service 实现接口
- 便于替换实现和扩展

### 8.3 使用事件通知

```php
// 操作完成后触发事件
$this->eventsManager->dispatch('Weline_Module::action_completed', [
    'entity' => $entity,
    'entity_id' => $entity->getId(),
]);
```

### 8.4 事务处理

```php
$connection = $model->getConnection();
$connection->beginTransaction();

try {
    // 多个数据库操作
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

### 8.5 使用 PHP 8+ 特性

- 构造函数属性提升
- `readonly` 属性
- 联合类型
- 命名参数

---

## 9. 常见错误

### 9.1 直接修改注入的 Model

```php
// ❌ 错误：直接使用注入的实例
public function save(array $data): void
{
    $this->model->setData($data);  // 污染共享实例
    $this->model->save();
}

// ✅ 正确：获取新实例或克隆
public function save(array $data): void
{
    $model = $this->objectManager->getInstance(YourModel::class);
    $model->setData($data);
    $model->save();
}
```

### 9.2 忽略事务

```php
// ❌ 错误：多个操作无事务保护
$order->save();
$history->save();
$inventory->update();

// ✅ 正确：使用事务
$connection->beginTransaction();
try {
    $order->save();
    $history->save();
    $inventory->update();
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

### 9.3 Service 中包含视图逻辑

```php
// ❌ 错误：Service 不应包含视图逻辑
public function getOrderHtml(): string
{
    return "<div class='order'>...</div>";
}

// ✅ 正确：返回数据，视图逻辑放 Block/Template
public function getOrderData(): array
{
    return ['id' => 1, 'status' => 'pending'];
}
```
