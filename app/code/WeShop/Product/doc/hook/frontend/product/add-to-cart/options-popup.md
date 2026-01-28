# WeShop_Product::frontend::product::add-to-cart::options-popup

## 概述

可配置产品的规格选择弹窗 Hook，用户选择规格后再加入购物车。此弹窗在页面中只需渲染一次，通过 JavaScript 控制显示/隐藏。

## Hook 信息

| 属性 | 值 |
|------|-----|
| Hook 名称 | `WeShop_Product::frontend::product::add-to-cart::options-popup` |
| 模块 | WeShop_Product |
| 区域 | frontend |
| 类型 | component |

## 弹窗结构

弹窗包含以下主要区域：

1. **产品预览区** - 显示产品图片、名称、价格、库存状态
2. **规格选项区** - 动态渲染可选规格（颜色、尺寸等）
3. **数量选择器** - 调整购买数量
4. **操作按钮** - 加入购物车按钮

## 使用示例

### 在模板中调用

```php
<?php 
// 在产品列表或详情页底部调用（只需调用一次）
echo $this->getHook('WeShop_Product::frontend::product::add-to-cart::options-popup');
?>
```

### JavaScript 控制

```javascript
// 显示弹窗
WeShopCart.showOptionsPopup(productId);

// 隐藏弹窗
WeShopCart.hideOptionsPopup();

// 监听事件
document.addEventListener('weshop:cart:options:show', function(e) {
    console.log('弹窗已显示', e.detail.productId);
});

document.addEventListener('weshop:cart:options:hide', function(e) {
    console.log('弹窗已隐藏');
});
```

## 实现此 Hook

在模块的 `view/hooks/WeShop_Product/frontend/product/add-to-cart/options-popup.phtml` 创建实现文件。

### DOM 结构要求

弹窗实现必须包含以下 ID 的元素：

| 元素 ID | 说明 |
|---------|------|
| `product-options-popup` | 弹窗容器 |
| `popup-product-image` | 产品图片 |
| `popup-product-name` | 产品名称 |
| `popup-product-price` | 产品价格 |
| `popup-product-original-price` | 原价（可选） |
| `popup-product-stock` | 库存状态 |
| `popup-selected-options` | 已选规格显示 |
| `popup-options-container` | 规格选项容器 |
| `popup-qty` | 数量输入框 |
| `popup-add-to-cart` | 加入购物车按钮 |

### 数据属性

选项按钮需要以下数据属性：

```html
<button class="option-value" 
        data-attribute-id="1"
        data-option-id="5"
        data-available-products='[2,3,5]'>
    红色
</button>
```

## 规格选项类型

支持三种规格展示方式：

### 1. 文本选项

```html
<button class="option-value">
    <span class="text-value">XL</span>
</button>
```

### 2. 颜色色块

```html
<button class="option-value swatch-color">
    <span class="swatch-inner" style="background-color: #ff0000"></span>
</button>
```

### 3. 图片色块

```html
<button class="option-value swatch-image">
    <img class="swatch-inner" src="/path/to/swatch.jpg" alt="红色">
</button>
```

## 相关事件

| 事件名 | 触发时机 | detail 数据 |
|--------|----------|-------------|
| `weshop:cart:options:show` | 弹窗显示时 | `{ productId, options }` |
| `weshop:cart:options:hide` | 弹窗隐藏时 | `{ productId }` |

## 相关 Hook

- `WeShop_Product::frontend::product::add-to-cart::button` - 加购按钮

## 样式定制

弹窗使用 CSS 变量，可通过覆盖变量自定义样式：

```css
:root {
    --color-bg-primary: #fff;
    --color-bg-secondary: #f8f9fa;
    --color-text-primary: #111827;
    --color-text-secondary: #6b7280;
    --color-text-muted: #9ca3af;
    --color-border-light: #e5e7eb;
    --color-accent: #3b82f6;
    --color-accent-light: #eff6ff;
}
```

## 响应式设计

- 移动端：底部弹出（bottom sheet）
- 桌面端：居中弹窗（modal）
- 断点：640px
