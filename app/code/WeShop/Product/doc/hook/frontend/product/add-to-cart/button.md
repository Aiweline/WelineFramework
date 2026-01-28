# WeShop_Product::frontend::product::add-to-cart::button

## 概述

产品加入购物车按钮的 Hook/Slot，用于渲染加购按钮。其他模块（如 Cart 模块）可以实现此 hook 来处理加购逻辑。对于可配置产品，点击按钮会弹出规格选择弹窗。

## Hook 信息

| 属性 | 值 |
|------|-----|
| Hook 名称 | `WeShop_Product::frontend::product::add-to-cart::button` |
| 模块 | WeShop_Product |
| 区域 | frontend |
| 类型 | slot（可被其他模块替换） |

## 可用变量

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$product` | array | 产品数据数组 |
| `$product_id` | int | 产品ID |
| `$in_stock` | bool | 是否有库存 |
| `$is_configurable` | bool | 是否为可配置产品（有子产品） |
| `$button_class` | string | 按钮CSS类名 |

## 使用示例

### 在模板中调用

```php
<?php 
echo $this->getHook('WeShop_Product::frontend::product::add-to-cart::button', [
    'product' => $product,
    'product_id' => $productId,
    'in_stock' => $inStock,
    'is_configurable' => $isConfigurable,
    'button_class' => 'btn btn-primary btn-add-to-cart',
]);
?>
```

### 实现此 Hook

在模块的 `view/hooks/WeShop_Product/frontend/product/add-to-cart/button.phtml` 创建实现文件：

```php
<?php
/**
 * @hook-priority 100
 * @hook-sort-order 1
 * @hook-solo false
 */

$productId = (int)$this->getData('product_id');
$inStock = $this->getData('in_stock');
$isConfigurable = $this->getData('is_configurable');
$buttonClass = $this->getData('button_class') ?? 'btn btn-primary';
?>

<button type="button" 
        class="<?= htmlspecialchars($buttonClass) ?>" 
        data-product-id="<?= $productId ?>"
        data-is-configurable="<?= $isConfigurable ? '1' : '0' ?>"
        data-action="add-to-cart"
        <?= !$inStock ? 'disabled' : '' ?>>
    <?= $inStock ? __('加入购物车') : __('缺货') ?>
</button>
```

## 相关事件

- `weshop:addToCart` - 原始加购事件（兼容旧代码）
- `weshop:cart:add` - 添加到购物车前
- `weshop:cart:added` - 已添加到购物车
- `weshop:cart:error` - 添加失败

## 相关 Hook

- `WeShop_Product::frontend::product::add-to-cart::options-popup` - 规格选择弹窗
- `WeShop_Product::frontend::product::detail::after-add-to-cart` - 加购后钩子

## 注意事项

1. 按钮必须包含 `data-action="add-to-cart"` 属性以触发 cart.js 模块的事件处理
2. 对于可配置产品，需设置 `data-is-configurable="1"` 以触发规格选择弹窗
3. 确保 cart.js 模块已加载（通过 `Weline.declare('cart', true)`）
