# Hook: WeShop_Filters::frontend::partials::filters::stock

## 说明

库存状态筛选组件，如有货、缺货等。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$stockOptions` | array | 库存状态选项 |
| `$currentStock` | string | 当前选中状态 |

## 使用示例

```php
<div class="stock-filter">
    <h4><?= __('库存') ?></h4>
    <?php foreach ($stockOptions as $option): ?>
        <label>
            <input type="checkbox" name="stock" value="<?= $option['value'] ?>">
            <?= $option['label'] ?> (<?= $option['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
