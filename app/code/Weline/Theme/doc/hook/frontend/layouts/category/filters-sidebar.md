# Hook: Weline_Theme::frontend::layouts::category::filters-sidebar

## 说明

在分类页左侧渲染产品筛选侧栏，支持价格、品牌、颜色、评分等多维度筛选。

## 位置

分类页面的筛选侧边栏区域（category-filters widget 内部）

## 类型

Slot Hook - 允许其他模块完全替换筛选内容

## 实现模块

由 `WeShop_Filters` 模块实现

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$category` | array | 当前分类数据 |
| `$categoriesData` | array | 分类树数据 |
| `$request` | Request | 请求对象 |

## 使用示例

```php
// 在其他模块的 hook 模板中
<?php
use WeShop\Filters\Service\FilterService;

$filterService = ObjectManager::getInstance(FilterService::class);
$result = $filterService->getFilterResult($categoryId, $productIds, $filterParams);

// 渲染筛选模板
echo $this->fetch('WeShop_Filters::Frontend/filters.phtml');
?>
```

## 注意事项

1. 此 Hook 为 slot 类型，实现后将完全替换默认的 Mock 筛选内容
2. 需要正确获取当前分类ID和产品ID列表
3. 筛选结果会更新 `filtered_product_ids` 到 request 数据中

## 相关 Hook

- `Weline_Theme::frontend::layouts::category::filters-before`
- `Weline_Theme::frontend::layouts::category::filters-after`
- `Weline_Theme::frontend::layouts::category::subcategories-filter`
