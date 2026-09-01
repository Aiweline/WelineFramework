# Weline_Order 模块扩展文档

## TrackingProvider（正式扩展点）

对齐万能支付：第三方物流模块实现 `Weline\Order\Interface\TrackingProviderInterface`，放在：

```text
extends/module/Weline_Order/TrackingProvider/YourCarrierProvider.php
```

权威指南：[doc/tracking-provider-development.md](doc/tracking-provider-development.md)、[doc/tracking-shell.md](doc/tracking-shell.md)。

内置：

- `system` — 无物流商时的系统订单追踪
- `fake_carrier` — 开发演示正式物流

## 概述

Weline_Order 还保留若干 legacy 扩展点（PaymentMethod / ShippingMethod 等）。**新对接请优先 TrackingProvider + Weline_Payment ProviderInterface**，不要继续扩展 legacy 支付/配送跟踪接口。

## 快速开始（TrackingProvider）

```php
<?php
declare(strict_types=1);

namespace Vendor\YourCarrier\Extends\Module\Weline_Order\TrackingProvider;

use Weline\Order\Interface\TrackingProviderInterface;
// ... 实现全部接口方法，并提供 icon_url / title / getFlowStages()
```

## Legacy 扩展点

以下路径仍登记在 `extends.php` 以兼容旧文档，新功能勿再依赖：

- `Service/PaymentMethod/` → 改用 `Weline_Payment` Provider
- `Service/ShippingMethod/` → 改用 `TrackingProvider`
- `Service/OrderStatus/`
- `Service/Calculator/`
