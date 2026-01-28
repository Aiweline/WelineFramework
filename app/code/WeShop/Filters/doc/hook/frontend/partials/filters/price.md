# Hook: WeShop_Filters::frontend::partials::filters::price

## 说明

价格区间筛选组件，支持预设区间、动态区间、滑块三种模式。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$priceRanges` | array | 价格区间选项 |
| `$minPrice` | float | 最低价格 |
| `$maxPrice` | float | 最高价格 |
| `$currentMin` | float | 当前选中最低价 |
| `$currentMax` | float | 当前选中最高价 |
| `$mode` | string | 模式：preset/dynamic/slider |

## 使用示例

```php
<div class="price-filter">
    <h4><?= __('价格') ?></h4>
    <?php foreach ($priceRanges as $range): ?>
        <label>
            <input type="checkbox" name="price" value="<?= $range['value'] ?>">
            <?= $range['label'] ?> (<?= $range['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
