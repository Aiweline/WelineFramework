# Hook: WeShop_Filters::frontend::partials::filters::applied

## 说明

显示当前已选择的筛选条件标签。

## 位置

筛选头部下方

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$appliedFilters` | array | 已应用的筛选条件 |

## 使用示例

```php
<?php if (!empty($appliedFilters)): ?>
<div class="applied-filters">
    <?php foreach ($appliedFilters as $filter): ?>
        <span class="filter-tag">
            <?= $filter['label'] ?>
            <a href="<?= $filter['remove_url'] ?>" class="remove">&times;</a>
        </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
