# WeShop_Cart 购物车模块

购物车模块提供完整的购物车功能，包括购物车页面和 MiniCart 迷你购物车。

## 功能特性

- 购物车页面（Sidebar 布局）
- MiniCart 迷你购物车（Shopify Drawer 风格）
- Header 购物车图标集成
- 完整的事件系统
- 丰富的 Hook 扩展点
- Widget Slot 支持

## 目录结构

```
WeShop/Cart/
├── Controller/Frontend/
│   ├── Api/
│   │   ├── Add.php          # 添加到购物车 API
│   │   ├── MiniItems.php    # MiniCart 商品列表 API
│   │   ├── Update.php       # 更新购物车 API
│   │   └── Remove.php       # 移除商品 API
│   └── Cart/
│       ├── Index.php        # 购物车页面
│       ├── Add.php          # 添加到购物车
│       ├── Update.php       # 更新购物车
│       └── Remove.php       # 移除商品
├── Service/
│   └── CartService.php      # 购物车服务
├── Model/
│   └── Cart.php             # 购物车模型
├── view/
│   ├── theme/frontend/
│   │   ├── layouts/cart/    # 购物车页面布局
│   │   └── widgets/         # Widget 部件
│   ├── hooks/               # Hook 实现
│   ├── templates/           # 模板文件
│   └── statics/js/          # JavaScript 模块
├── extends/module/Weline_Widget/  # Widget 配置
├── hook.php                 # Hook 定义
├── event.php                # 事件定义
└── i18n/                    # 国际化文件
```

## 使用指南

### 1. 购物车页面布局

购物车页面采用 Sidebar 布局，左侧商品列表，右侧订单摘要。

**布局文件**: `view/theme/frontend/layouts/cart/default.phtml`

**布局结构**:
```
┌─────────────────────────────────────────────┐
│                   Header                     │
├──────────────────────────┬──────────────────┤
│                          │                  │
│    商品列表区域          │   订单摘要       │
│                          │                  │
│    [Hook: items-before]  │  - 优惠券输入    │
│    [商品列表]            │  - 配送方式      │
│    [Hook: items-after]   │  - 价格明细      │
│                          │  - 结算按钮      │
│    [继续购物按钮]        │                  │
│                          │  [Hook: sidebar] │
├──────────────────────────┴──────────────────┤
│                   Footer                     │
└─────────────────────────────────────────────┘
```

### 2. MiniCart Widget

Shopify 风格的侧边抽屉购物车。

**使用方式**:
```phtml
<w:widget type="mini-cart" name="mini-cart-drawer" params='{"position":"right"}'/>
```

**参数**:
- `position`: 弹出位置，`left` 或 `right`（默认）
- `width`: 宽度，如 `400px`
- `autoOpen`: 添加商品后是否自动打开，`true`（默认）

### 3. Header 购物车图标

通过 Hook 集成到 Header：

```phtml
<!-- 在 header 模板中 -->
<div class="header-cart">
    <w:hook>header-cart</w:hook>
</div>
```

WeShop_Cart 模块会自动实现 `header-cart` Hook，显示购物车图标和 MiniCart。

## Hook 扩展点

### 购物车页面 Hooks

| Hook 名称 | 说明 |
|-----------|------|
| `WeShop_Cart::frontend::cart::items-before` | 商品列表之前 |
| `WeShop_Cart::frontend::cart::items-after` | 商品列表之后 |
| `WeShop_Cart::frontend::cart::item-before` | 单个商品之前 |
| `WeShop_Cart::frontend::cart::item-after` | 单个商品之后 |
| `WeShop_Cart::frontend::cart::summary-before` | 订单摘要之前 |
| `WeShop_Cart::frontend::cart::summary-after` | 订单摘要之后 |
| `WeShop_Cart::frontend::cart::coupon-input` | 优惠券输入框 |
| `WeShop_Cart::frontend::cart::shipping-options` | 配送方式选择 |
| `WeShop_Cart::frontend::cart::express-checkout` | 快捷支付按钮 |
| `WeShop_Cart::frontend::cart::sidebar` | 侧边栏扩展 |
| `WeShop_Cart::frontend::cart::empty` | 空购物车状态 |
| `WeShop_Cart::frontend::cart::continue-shopping` | 继续购物按钮 |

### MiniCart Hooks

| Hook 名称 | 说明 |
|-----------|------|
| `WeShop_Cart::frontend::mini-cart::header-before` | 头部之前 |
| `WeShop_Cart::frontend::mini-cart::header-after` | 头部之后 |
| `WeShop_Cart::frontend::mini-cart::items-before` | 商品列表之前 |
| `WeShop_Cart::frontend::mini-cart::items-after` | 商品列表之后 |
| `WeShop_Cart::frontend::mini-cart::footer-before` | 底部之前 |
| `WeShop_Cart::frontend::mini-cart::footer-after` | 底部之后 |
| `WeShop_Cart::frontend::mini-cart::empty` | 空状态 |

### Hook 实现示例

创建文件 `view/hooks/WeShop_Cart/frontend/cart/items-after.phtml`:

```phtml
<?php
/**
 * 购物车商品列表后显示推荐商品
 * @hook-priority 100
 */
?>
<div class="cart-recommendations">
    <h3>您可能还喜欢</h3>
    <!-- 推荐商品列表 -->
</div>
```

## Widget Slot 扩展

### 购物车页面 Slots

| Slot ID | 说明 | 接受类型 |
|---------|------|----------|
| `cart-summary` | 订单摘要区域 | summary, coupon, shipping, payment |
| `cart-coupon` | 优惠券输入 | coupon |
| `cart-shipping` | 配送方式 | shipping |
| `cart-express-checkout` | 快捷支付 | payment, express-checkout |
| `cart-sidebar-extra` | 侧边栏扩展 | - |

### MiniCart Slots

| Slot ID | 说明 | 接受类型 |
|---------|------|----------|
| `mini-cart-header` | 头部区域 | - |
| `mini-cart-items` | 商品列表 | - |
| `mini-cart-footer` | 底部区域 | summary, actions, coupon |
| `mini-cart-express` | 快捷支付 | express-checkout, payment |

## 事件系统

### PHP 事件

| 事件名称 | 触发时机 |
|----------|----------|
| `WeShop_Cart::add_to_cart_before` | 添加到购物车之前 |
| `WeShop_Cart::add_to_cart_after` | 添加到购物车之后 |
| `WeShop_Cart::update_cart_before` | 更新购物车之前 |
| `WeShop_Cart::update_cart_after` | 更新购物车之后 |
| `WeShop_Cart::remove_from_cart_before` | 移除商品之前 |
| `WeShop_Cart::remove_from_cart_after` | 移除商品之后 |
| `WeShop_Cart::clear_before` | 清空购物车之前 |
| `WeShop_Cart::clear_after` | 清空购物车之后 |
| `WeShop_Cart::totals_collect` | 计算总额时 |
| `WeShop_Cart::totals_collected` | 总额计算完成 |
| `WeShop_Cart::mini_cart_loaded` | MiniCart 数据加载完成 |

### 监听事件示例

在 `etc/event.xml` 中注册 Observer：

```xml
<event name="WeShop_Cart::add_to_cart_after">
    <observer name="track_add_to_cart" 
              class="YourModule\Observer\TrackAddToCart"/>
</event>
```

### JavaScript 事件

| 事件名称 | 触发时机 |
|----------|----------|
| `weshop:cart:add` | 添加到购物车（请求前） |
| `weshop:cart:added` | 添加成功 |
| `weshop:cart:error` | 添加失败 |
| `weshop:cart:updated` | 购物车更新 |
| `weshop:cart:removed` | 商品移除 |
| `weshop:mini-cart:open` | MiniCart 打开 |
| `weshop:mini-cart:close` | MiniCart 关闭 |
| `weshop:mini-cart:loaded` | MiniCart 数据加载完成 |

### 监听 JS 事件示例

```javascript
document.addEventListener('weshop:cart:added', (e) => {
    console.log('商品已添加:', e.detail);
    // e.detail 包含: product_id, quantity, cart_count, cart_total 等
});

document.addEventListener('weshop:mini-cart:open', () => {
    console.log('MiniCart 已打开');
});
```

## API 接口

### 获取 MiniCart 数据

```
GET /cart/api/mini-items
```

响应：
```json
{
    "success": true,
    "html": "<div class=\"mini-cart-item\">...</div>",
    "items": [...],
    "totals": {
        "subtotal": 99.00,
        "subtotal_formatted": "¥99.00",
        "count": 3
    }
}
```

### 更新购物车

```
POST /cart/api/update
Content-Type: application/json

{
    "item_id": 123,
    "quantity": 2
}
```

### 移除商品

```
POST /cart/api/remove
Content-Type: application/json

{
    "item_id": 123
}
```

## 扩展开发

### 添加优惠券功能

1. 创建 WeShop_Coupon 模块
2. 实现 `WeShop_Cart::frontend::cart::coupon-input` Hook
3. 监听 `WeShop_Cart::totals_collect` 事件计算折扣

详见 [优惠券扩展示例](examples/coupon-extension.md)

### 添加配送方式选择

1. 创建 WeShop_Shipping 模块
2. 实现 `WeShop_Cart::frontend::cart::shipping-options` Hook
3. 创建 Widget 并注册到 `cart-shipping` Slot

### 添加快捷支付

1. 实现 `WeShop_Cart::frontend::cart::express-checkout` Hook
2. 或创建 Widget 并注册到 `cart-express-checkout` Slot

## 注意事项

1. **模块解耦**: 不要直接依赖其他模块的类，使用事件和 Hook 进行通信
2. **登录验证**: CartService 方法需要 customer_id，确保用户已登录
3. **性能优化**: MiniCart 使用 AJAX 加载，避免页面加载时的性能影响
4. **国际化**: 所有用户可见文本使用 `__()` 或 `<lang>` 标签
