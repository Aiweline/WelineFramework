# Hook: WeShop_Filters::frontend::partials::filters::shipping

## 说明

配送方式筛选组件，如免运费、当日达等。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$shippingOptions` | array | 配送方式选项 |
| `$selectedShipping` | array | 已选配送方式 |

## 使用示例

```php
<div class="shipping-filter">
    <h4><?= __('配送') ?></h4>
    <?php foreach ($shippingOptions as $option): ?>
        <label>
            <input type="checkbox" name="shipping[]" value="<?= $option['value'] ?>">
            <?= $option['label'] ?> (<?= $option['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
