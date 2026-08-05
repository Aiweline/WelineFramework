# Weline_Order 订单管理模块

## 模块概述

Weline_Order 是一个符合国际电商标准的订单管理模块，提供完整的订单生命周期管理功能，支持订单创建、支付、发货、退款、发票等全流程操作。

订单优惠校验直接使用 Payment 的支付方式兼容能力，因此 Payment 是必需依赖。

## Order Facade（P2D-001）

跨模块写入边界见 [`doc/facade-api.md`](facade-api.md)：`plan` / `create` / `get`；Checkout/Payment/Inventory 不得引用内部 Model/Service。`get()` 的公开只读投影保留 `customerId`，供支付 eligibility 冻结 owner，但不暴露 Order Model。Order 作为 Payable：[`doc/payable-resolver.md`](payable-resolver.md)（`weline_order`）。退款：[`doc/refund.md`](refund.md)。发票/履约/tombstone/账户汇总：[`doc/invoice-fulfillment-tombstone.md`](invoice-fulfillment-tombstone.md)。

多仓履约当前源码见 [`doc/warehouse-fulfillment.md`](warehouse-fulfillment.md)：
durable writer flag 控制的 `legacy_default→warehouse` 新单来源、部分发货
CAS、不可变进度账本和原仓退款重试边界。

## CheckoutGroup 拓扑（P2D-002）

见 [`doc/model-topology.md`](model-topology.md)：Group→Order→
FulfillmentUnit、Order 级五类不可变快照、状态 CAS、组不变式与整组回滚。

## 展示号与支付后处理（P2D-003）

见 [`doc/display-number.md`](display-number.md)：展示号按
`website/store/number_kind/display_number` 唯一，后台查号必须携带 kind
与 Scope；支付后处理只接收冻结的 `OrderPaidContext` minor-unit DTO。

## Checkout→Order 切换机制（P2D-004）

见 [`doc/cutover.md`](cutover.md)：legacy Checkout writer 通过同步 critical
事件接入 Order guard，不跨模块调用 Order 内部 Service；shadow 比对强制
`mode=shadow`，并用 DML/lock/reservation/outbox/cache 五通道快照及完整
item/金额/运费 owner 归一化防止假绿。`TASK-MIG-P2-ORDER` 的当前源码
命令只允许登记的隔离 clone，并以显式 token、不可变 checkpoint/journal、
带表前缀数据库探针和新进程 verify 完成单写切流验收；生产持久启用仍须
独立发布授权。

## 核心功能

### 1. 订单管理
- 订单创建、更新、取消
- 订单列表查询和筛选
- 订单详情查看
- 订单状态管理

### 2. 支付管理
- 支付记录创建
- 支付状态跟踪
- 支付退款处理

### 3. 发货管理
- 发货记录创建
- 物流单号管理
- 发货状态跟踪

### 4. 退款管理
- 退款申请创建
- 退款处理流程
- 退款历史记录

### 5. 发票管理
- 发票生成
- 发票打印
- 发票历史记录

### 6. 优惠方式验证
- 验证优惠规则是否可以应用于订单
- 检查支付方式是否支持优惠方式
- 在订单创建时自动验证

## 模块结构

```
app/code/Weline/Order/
├── register.php                    # 模块注册
├── etc/
│   ├── module.xml                  # 模块配置
│   ├── event.xml                   # 事件配置
│   ├── backend/
│   │   └── menu.xml                # 后台菜单
│   └── routes.xml                  # API路由
├── Setup/
│   └── Install.php                 # 数据库安装脚本
├── Model/                          # 数据模型
│   ├── Order.php                   # 订单主表模型
│   ├── OrderItem.php               # 订单项模型
│   ├── OrderPayment.php           # 支付记录模型
│   ├── OrderShipment.php          # 发货记录模型
│   ├── OrderRefund.php            # 退款记录模型
│   ├── OrderInvoice.php           # 发票模型
│   └── OrderHistory.php           # 订单历史模型
├── Service/                        # 业务服务层
│   ├── OrderService.php           # 订单核心服务
│   ├── OrderStateMachine.php      # 订单状态机
│   ├── PaymentService.php         # 支付服务
│   ├── FulfillmentService.php     # 发货服务
│   ├── RefundService.php          # 退款服务
│   ├── InvoiceService.php         # 发票服务
│   └── DiscountValidationService.php # 优惠方式验证服务
├── Controller/
│   ├── Backend/
│   │   ├── Order.php              # 订单管理控制器
│   │   ├── Payment.php            # 支付管理控制器
│   │   ├── Shipment.php           # 发货管理控制器
│   │   ├── Refund.php             # 退款管理控制器
│   │   └── Invoice.php            # 发票管理控制器
│   └── Api/
│       └── Order.php              # 订单API控制器
├── Observer/                       # 事件观察者
│   ├── OrderCreatedObserver.php   # 订单创建观察者
│   ├── OrderStatusChangedObserver.php # 订单状态变更观察者
│   └── OrderPaidObserver.php      # 订单支付观察者
├── view/
│   └── templates/
│       └── Backend/
│           └── Order/
│               ├── index.phtml    # 订单列表
│               ├── view.phtml     # 订单详情
│               └── edit.phtml     # 订单编辑
├── Test/                           # 测试
│   ├── Unit/                      # 单元测试
│   └── Integration/               # 集成测试
└── doc/                            # 文档
    ├── README.md
    ├── 订单状态机说明.md
    └── API文档.md
```

## 安装

### 1. 模块安装

```bash
php bin/w setup:upgrade
```

### 2. 验证安装

```bash
php bin/w module:list | grep "Weline_Order"
```

应该显示: `Weline_Order    # 开启`

## 使用指南

### 创建订单

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\OrderService;

$orderService = ObjectManager::getInstance(OrderService::class);

$orderData = [
    'customer_id' => 1,
    'customer_name' => '张三',
    'customer_email' => 'zhangsan@example.com',
    'items' => [
        [
            'product_name' => '商品1',
            'qty_ordered' => 2,
            'price' => 100.00,
        ],
    ],
];

$order = $orderService->createOrder($orderData);
```

### 处理支付

```php
use Weline\Order\Service\PaymentService;

$paymentService = ObjectManager::getInstance(PaymentService::class);

$paymentData = [
    'amount' => 200.00,
    'payment_method' => 'alipay',
    'transaction_id' => 'TXN123456',
];

$payment = $paymentService->processPayment($orderId, $paymentData);
```

### 创建发货记录

```php
use Weline\Order\Service\FulfillmentService;

$fulfillmentService = ObjectManager::getInstance(FulfillmentService::class);

$shipmentData = [
    'tracking_number' => 'SF1234567890',
    'carrier' => '顺丰速运',
];

$shipment = $fulfillmentService->createShipment($orderId, $shipmentData);
```

## API接口

### 获取订单列表

```
GET /weline_api/rest/v1/backend/order/list
```

### 获取订单详情

```
GET /weline_api/rest/v1/backend/order/{id}
```

### 创建订单

```
POST /weline_api/rest/v1/backend/order/create
```

### 更新订单状态

```
POST /weline_api/rest/v1/backend/order/status
```

详细API文档请参考：`doc/API文档.md`

## 事件系统

模块定义了以下事件：

### 订单生命周期事件

- `Weline_Order::order_created` - 订单创建后
- `Weline_Order::order_updated` - 订单更新后
- `Weline_Order::order_paid` - 订单支付后
- `Weline_Order::order_shipped` - 订单发货后
- `Weline_Order::order_completed` - 订单完成后
- `Weline_Order::order_cancelled` - 订单取消后
- `Weline_Order::order_refunded` - 订单退款后

### 订单状态变更事件

- `Weline_Order::order_status_change_before` - 订单状态变更前
  - 允许观察者阻止状态转换（设置 `can_change` 为 `false`）
  - 事件数据：`order`, `order_id`, `old_status`, `new_status`, `comment`, `notify_customer`, `can_change`
  
- `Weline_Order::order_status_changed` - 订单状态变更后
  - 用于处理状态变更后的逻辑（如：记录历史、发送通知）
  - 事件数据：`order`, `order_id`, `old_status`, `new_status`, `comment`, `notify_customer`

- `Weline_Order::order_status_can_transition` - 检查状态转换规则
  - 允许观察者动态扩展状态转换规则
  - 事件数据：`from_status`, `to_status`, `can_transition`, `transitions`

### 订单状态管理事件

- `Weline_Order::order_status_save_before` - 订单状态保存前
  - 允许观察者在状态保存前进行验证或修改
  - 事件数据：`status`, `old_status`, `code`, `name`, `description`, `color`, `icon`, `is_active`, `sort_order`, `translations`

- `Weline_Order::order_status_saved` - 订单状态保存后
  - 用于处理状态保存后的逻辑（如：清除缓存）
  - 事件数据：`status`, `old_status`, `code`

- `Weline_Order::order_status_delete_before` - 订单状态删除前
  - 允许观察者阻止状态删除（设置 `can_delete` 为 `false`）
  - 事件数据：`status`, `status_id`, `code`, `can_delete`

- `Weline_Order::order_status_deleted` - 订单状态删除后
  - 用于处理状态删除后的逻辑（如：清理相关数据）
  - 事件数据：`status_id`, `code`

### 事件使用示例

#### 监听状态变更事件

```php
<?php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusChangedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $orderId = $data['order_id'];
        $newStatus = $data['new_status'];
        
        // 处理状态变更逻辑
        // 例如：发送通知、更新库存等
    }
}
```

#### 阻止状态转换

```php
<?php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusChangeBeforeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $oldStatus = $data['old_status'];
        $newStatus = $data['new_status'];
        
        // 业务规则：已发货的订单不能取消
        if ($oldStatus === 'fulfilled' && $newStatus === 'cancelled') {
            $data['can_change'] = false;
            $event->setData($data);
        }
    }
}
```

#### 扩展状态转换规则

```php
<?php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class OrderStatusTransitionRulesObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $fromStatus = $data['from_status'];
        $toStatus = $data['to_status'];
        $transitions = $data['transitions'];
        
        // 动态添加转换规则
        if ($fromStatus === 'custom_status' && !isset($transitions['custom_status'])) {
            $transitions['custom_status'] = ['completed'];
            $data['transitions'] = $transitions;
            $data['can_transition'] = in_array($toStatus, $transitions['custom_status']);
            $event->setData($data);
        }
    }
}
```

## Hook扩展点

模块提供以下Hook点：

- `Weline_Order::backend::order::view::before` - 订单详情页之前
- `Weline_Order::backend::order::view::after` - 订单详情页之后
- `Weline_Order::backend::order::list::filters` - 订单列表筛选器
- `Weline_Order::frontend::order::create::before` - 前端订单创建前
- `Weline_Order::frontend::order::create::after` - 前端订单创建后

## Extends扩展点

模块提供以下扩展点：

- `Weline_Order::payment_methods` - 支付方式扩展
- `Weline_Order::shipping_methods` - 配送方式扩展
- `Weline_Order::order_statuses` - 订单状态扩展
- `Weline_Order::order_calculators` - 订单计算器扩展

## 优惠方式验证

订单模块集成了支付方式的优惠方式支持功能，在应用优惠规则时会自动验证支付方式是否支持该优惠方式。

### 验证机制

在订单创建时，如果提供了支付方式和优惠规则，系统会自动验证：
1. 检查支付方式是否支持优惠规则中的每个优惠方式
2. 如果不支持，抛出异常并提示用户

### 使用示例

#### 验证单个优惠规则

```php
use Weline\Order\Service\OrderService;
use Weline\Framework\Manager\ObjectManager;

$orderService = ObjectManager::getInstance(OrderService::class);

// 验证优惠规则
$validationResult = $orderService->validateDiscountRule('alipay', [
    'type' => 'discount_fixed_amount',
    'discount_value' => 10.00,
]);

if (!$validationResult['valid']) {
    // 处理不支持的情况
    foreach ($validationResult['messages'] as $message) {
        echo $message;
    }
}
```

#### 在订单创建时自动验证

```php
$order = $orderService->createOrder([
    'customer_id' => 123,
    'payment_method' => 'alipay',
    'discount_rules' => [
        [
            'rule_id' => 1,
            'actions' => [
                'type' => 'discount_fixed_amount',
                'discount_value' => 10.00,
            ],
        ],
    ],
    'items' => [...],
]);
// 如果支付方式不支持优惠方式，会抛出异常
```

#### 使用DiscountValidationService

```php
use Weline\Order\Service\DiscountValidationService;
use Weline\Framework\Manager\ObjectManager;

$validationService = ObjectManager::getInstance(DiscountValidationService::class);

// 验证支付方式是否支持优惠方式
$isSupported = $validationService->validateDiscountForPayment('alipay', 'discount_fixed_amount');

// 验证多个优惠方式
$unsupported = $validationService->validateDiscountsForPayment('alipay', [
    'discount_fixed_amount',
    'discount_percentage',
]);

// 验证规则动作
$result = $validationService->validateRuleActions('alipay', [
    'type' => 'discount_fixed_amount',
    'discount_value' => 10.00,
]);
```

### 与营销模块集成

订单模块不直接依赖 Marketing。它只消费
`Weline\Payment\Api\Discount\DiscountActionSupportInterface`；Payment 在 Marketing
存在时从公开的 data-only ActionCatalog 获取动作描述。因此：

- 自动发现所有可用的优惠方式（包括扩展的）
- 支持营销模块扩展的所有优惠方式类型
- 在订单创建时自动验证，确保支付方式支持优惠方式
- Order 不加载 Payment `Service` / `Model`，Marketing 未安装也不会导致类加载错误

## 后台对象授权（TASK-P1B-004）

- 订单列表在分页和输出投影前按 `LIST` 过滤；详情与编辑加载按 `VIEW/UPDATE` 校验。
- 已有订单的对象 Scope 只从持久化 `website_id/store_id` 解析，请求参数不能改变其授权目标。
- 新建订单必须提交显式且有效的目标 Scope；所有写入在持久化前校验当前
  `expected_grant_version`。
- 状态、支付和发票变更使用 `UPDATE`，退款使用 `REFUND`，发货/履约使用 `FULFILL`。
- 旧 `adminRequest` 任意 URL/method/body 桥接已移除；`order_admin` 只发布
  `saveStatus`、`deleteStatus`、`toggleStatus` 三个固定类型化操作。
- 状态字典属于 Global 对象，但仍需显式 Global 授权；超级管理员不会获得隐式对象写权限。

## 测试

### 运行单元测试

```bash
php bin/w phpunit:run --module=Weline_Order --filter=Unit
```

### 运行集成测试

```bash
php bin/w phpunit:run --module=Weline_Order --filter=Integration
```

## 技术要点

1. **严格类型声明**: 所有PHP文件使用 `declare(strict_types=1)`
2. **依赖注入**: 使用ObjectManager进行依赖注入
3. **事务处理**: 订单创建和状态变更使用数据库事务
4. **事件驱动**: 关键操作触发事件，支持模块间解耦
5. **状态机模式**: 使用状态机管理订单状态流转
6. **国际化支持**: 所有文本使用翻译函数
7. **权限控制**: 路由 ACL 与对象 Scope ACL 双重控制，提交前校验授权版本

## 参考标准

- Shopify订单管理系统
- Magento订单管理架构
- WooCommerce订单流程
- ISO 8601日期时间标准
- ISO 4217货币代码标准

## 版本历史

- **2.12.4** - 完成 P4D-002 current source：冻结 Order 资产分配快照，
  RefundCase 固化 cash/asset 拆分，混合与全资产退款通过独立
  `asset_return` outbox 累计返还，现金 Provider 成功事实不因资产重试重放。
- **2.12.2** - 消费 MIG-P3A 的 durable Warehouse writer flag；关闭时新
  FulfillmentUnit 保持 `legacy_default`，verify/allowlist 后才记录
  `warehouse`，原仓事实继续不可变。
- **2.12.1** - 完成 P3A-002 current source：mode-off 新单默认逻辑仓、
  既有仓事实保留、FulfillmentUnit qty CAS + immutable progress ledger，
  以及现金成功后原 Warehouse 退款回库的独立幂等重试。
- **2.11.6** - 完成 MIG-P2-ORDER current-source：隔离 clone 的
  preflight/apply/fresh-process verify/controlled rollback 全部由显式
  checkpoint 驱动；移除默认 token，补带前缀物理表探针、可靠非零退出码，
  并在已有新 Order 时拒绝隐藏事实源的 `off` 回退。
- **2.11.5** - 完成 P2D-004 current-source：移除进程级 static writer
  guard，改为 Checkout 前置事件与 Order critical Observer；兼容 reader
  不再吞掉新读故障，shadow comparator 强制五通道零副作用与完整计划 diff。
- **2.11.4** - 修复 DB 模式展示号查号与 seed replay，使用冲突安全
  upsert 实现五次有界分配；发布带 Scope ACL 的 kind-qualified
  QueryProvider，并将支付后处理边界收紧为冻结 `OrderPaidContext`。
- **2.11.3** - 落实 Group→Order→Item/FulfillmentUnit 数据库拓扑，
  持久化 Order 级 Tax/Shipping 快照，补 UUID/Group 索引和状态 CAS；
  同状态重试零写，并禁止 legacy service 改写新拓扑冻结字段。
- **2.11.2** - 加固 Order Facade 命令边界、DB 幂等竞态恢复和 SQLite
  事务/回滚证据；发布 `OrderFacadeInterface` DI 映射并同步标准模块依赖元数据。
- **1.0.0** - 初始版本，实现基础订单管理功能

## 许可证

本模块遵循WelineFramework许可证。
