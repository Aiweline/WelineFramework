# Hook: WeShop_Filters::frontend::partials::filters::result-count

## 说明

显示筛选后的产品数量。

## 位置

筛选结果区域

## 类型

普通 Hook

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$totalCount` | int | 筛选后产品总数 |
| `$originalCount` | int | 筛选前产品总数 |

## 使用示例

```php
<div class="filter-result-count">
    <?= __('找到 %1 件商品', $totalCount) ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
