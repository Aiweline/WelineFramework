# Hook: WeShop_Filters::frontend::partials::filters::container

## 说明

筛选区域的主容器，包含所有筛选组件。

## 位置

分类页面筛选侧边栏的主容器区域

## 类型

Slot Hook - 支持插槽替换

## 可用插槽

| 插槽名 | 说明 |
|--------|------|
| `header` | 筛选头部 |
| `applied` | 已选条件 |
| `groups` | 筛选组容器 |
| `footer` | 筛选底部 |

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$filters` | array | 筛选数据 |
| `$appliedFilters` | array | 已应用的筛选条件 |
| `$categoryId` | int | 分类ID |

## 使用示例

```php
<?php
// 自定义筛选容器
?>
<div class="custom-filters-container">
    <?= $this->hook('WeShop_Filters::frontend::partials::filters::header') ?>
    <?= $this->hook('WeShop_Filters::frontend::partials::filters::applied') ?>
    <!-- 自定义筛选组 -->
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::header`
- `WeShop_Filters::frontend::partials::filters::applied`
