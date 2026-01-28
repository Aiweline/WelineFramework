# Hook: WeShop_Filters::frontend::partials::filters::header

## 说明

筛选区域的头部，包含标题和清除全部按钮。

## 位置

筛选容器顶部

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$clearAllUrl` | string | 清除全部筛选的URL |
| `$hasAppliedFilters` | bool | 是否有已应用的筛选 |

## 使用示例

```php
<div class="filters-header">
    <h3><?= __('筛选') ?></h3>
    <?php if ($hasAppliedFilters): ?>
        <a href="<?= $clearAllUrl ?>"><?= __('清除全部') ?></a>
    <?php endif; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
