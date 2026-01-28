# Hook: WeShop_Filters::frontend::partials::filters::sale

## 说明

促销/折扣商品筛选组件。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$saleProductCount` | int | 促销商品数量 |
| `$isSaleSelected` | bool | 是否已选促销筛选 |

## 使用示例

```php
<div class="sale-filter">
    <label>
        <input type="checkbox" name="sale" value="1" <?= $isSaleSelected ? 'checked' : '' ?>>
        <?= __('促销商品') ?> (<?= $saleProductCount ?>)
    </label>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
