# Weline Checkout 结账模块

## 📚 文档导航

- [使用指南](./使用指南.md) - 详细的使用说明、示例代码和最佳实践
- [事件使用指南](./事件使用指南.md) - 如何通过事件系统扩展业务逻辑功能
- [API文档](./API文档.md) - 完整的API接口文档

## 模块概述

Weline_Checkout 是一个完整的结账模块，提供订单创建、订单管理和支付处理等核心功能。模块支持国际化标准，并为支付模块提供标准化的扩展接口。

**扩展机制**：
- **Hook系统**：用于视图层（前端布局页面），在主题布局页面中推送模板内容
- **事件系统**：用于服务层（业务逻辑），在业务逻辑中扩展功能

两者不冲突，可以同时使用。

## 主要功能

### 1. 订单管理
- 订单创建
- 订单状态管理
- 订单查询和详情查看
- 订单取消

### 2. 结账流程
- 结账页面
- 订单项管理
- 地址管理
- 配送方式选择
- 支付方式选择

### 3. 支付集成
- 支付处理接口
- 支付回调处理
- 支付交易记录

### 4. Hook系统（视图层）
- 结账页面Hook（8个）：头部、内容、表单、支付方式选择区域
- 订单页面Hook（4个）：订单列表、订单详情
- 后台管理Hook（3个）：订单列表筛选器、订单详情页

### 5. 事件系统（服务层）
- 结账事件（6个）：验证、计算总额、创建订单
- 订单事件（9个）：加载、状态变更、取消、完成、退款
- 支付事件（8个）：验证、处理、回调、成功、失败

## 模块结构

```
app/code/Weline/Checkout/
├── register.php                    # 模块注册文件
├── etc/
│   ├── module.xml                  # 模块配置
│   └── backend/
│       └── menu.xml                # 后台菜单配置
├── Setup/
│   └── Install.php                 # 数据库安装脚本
├── Model/
│   ├── Order.php                   # 订单模型
│   ├── OrderItem.php               # 订单项模型
│   └── PaymentTransaction.php      # 支付交易模型
├── Service/
│   ├── CheckoutService.php         # 结账服务
│   ├── OrderService.php            # 订单服务
│   └── PaymentService.php          # 支付服务
├── Controller/
│   ├── Frontend/
│   │   ├── Checkout.php            # 前端结账控制器
│   │   └── Order.php                # 前端订单控制器
│   └── Backend/
│       └── Order.php               # 后台订单管理控制器
├── i18n/
│   ├── zh_Hans_CN.csv              # 中文翻译
│   └── en_US.csv                   # 英文翻译
├── view/
│   ├── frontend/
│   │   ├── checkout/
│   │   │   ├── index.phtml         # 结账页面
│   │   │   └── success.phtml       # 结账成功页面
│   │   └── order/
│   │       ├── view.phtml          # 订单详情
│   │       └── list.phtml          # 订单列表
│   └── backend/
│       └── order/
│           ├── index.phtml         # 订单管理列表
│           └── view.phtml          # 订单详情
├── hook.php                         # Hook规约文件（视图层）
├── event.php                        # 事件规约文件（服务层）
└── doc/
    └── README.md                    # 模块文档
```

## 数据库表结构

### 1. 订单表 (weline_checkout_order)
存储订单主信息，包括订单号、客户ID、订单状态、金额信息、地址信息等。

### 2. 订单项表 (weline_checkout_order_item)
存储订单中的商品项信息，包括产品ID、产品名称、数量、价格等。

### 3. 支付交易表 (weline_checkout_payment_transaction)
存储支付交易记录，包括交易号、支付方式、交易状态、网关响应等。

## Hook系统（视图层）

结账模块提供了15个标准化的Hook扩展点，用于在主题布局页面中推送模板内容。

### Hook分类

1. **结账页面Hook（8个）**：头部、内容、表单、支付方式选择区域
2. **订单页面Hook（4个）**：订单列表、订单详情
3. **后台管理Hook（3个）**：订单列表筛选器、订单详情页

Hook文件放在 `view/hooks/` 目录下，通过 `<w:hook>` 标签在模板中调用。

## 事件系统（服务层）

结账模块提供了23个标准化的事件扩展点，用于在业务逻辑中扩展功能。

### 事件分类

1. **结账事件（6个）**：验证、计算总额、创建订单
2. **订单事件（9个）**：加载、状态变更、取消、完成、退款
3. **支付事件（8个）**：验证、处理、回调、成功、失败

详细说明和实现示例请参考 [事件使用指南](./事件使用指南.md)。

**重要**：Hook系统用于视图层，事件系统用于服务层，两者不冲突，可以同时使用。

## 快速开始

### 创建订单

```php
use Weline\Checkout\Service\CheckoutService;
use Weline\Framework\Manager\ObjectManager;

$checkoutService = ObjectManager::getInstance(CheckoutService::class);

$data = [
    'customer_id' => 1,
    'items' => [
        [
            'product_id' => 1,
            'product_name' => '商品名称',
            'quantity' => 2,
            'price' => 100.00,
        ],
    ],
    'shipping_address' => [
        'name' => '收货人',
        'phone' => '13800138000',
        'address' => '收货地址',
    ],
    'payment_method' => 'alipay',
    'currency' => 'CNY',
];

$order = $checkoutService->createOrder($data);
```

更多使用示例请参考 [使用指南](./使用指南.md)。

## 国际化支持

模块支持国际化，所有用户可见文本都使用 `__()` 函数进行翻译。翻译文件位于 `i18n/` 目录下：

- `zh_Hans_CN.csv` - 中文翻译
- `en_US.csv` - 英文翻译

## 依赖模块

- `Weline_Framework` - 框架核心
- `Weline_Backend` - 后台管理
- `Weline_Customer` - 客户管理
- `Weline_I18n` - 国际化支持

## 安装

1. 将模块放置到 `app/code/Weline/Checkout/` 目录
2. 运行模块安装命令：
   ```bash
   php bin/w setup:upgrade
   ```

## 版本

当前版本：1.0.0

## 作者

秋枫雁飞 (Aiweline)
- 邮箱：aiweline@qq.com
- 网址：aiweline.com
- 论坛：https://bbs.aiweline.com

