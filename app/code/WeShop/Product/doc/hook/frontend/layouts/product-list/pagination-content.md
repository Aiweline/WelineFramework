# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Product::frontend::layouts::product-list::pagination-content`
- **显示名称**：产品列表分页内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Product
- **功能说明**：在产品列表页分页区域渲染分页组件，包括页码导航、跳转功能等。如果只有一页或没有数据，则不渲染分页。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Product/frontend/layouts/product-list/pagination-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：产品列表页面的分页区域
- **触发条件**：当产品列表需要分页时自动触发
- **使用位置**：产品列表页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `pagination` - 分页信息（数组）
  - `current_page` - 当前页码
  - `page_size` - 每页数量
  - `total` - 总记录数
  - `total_pages` - 总页数
- `products` - 当前页的产品列表（数组）

## 使用场景

该 Hook 适用于以下场景：
- 自定义产品列表的分页显示方式
- 添加分页的额外功能（如快速跳转、每页数量选择等）
- 替换默认的分页组件
- 集成第三方分页组件

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 产品列表分页Hook
 * 
 * Hook名称：WeShop_Product::frontend::layouts::product-list::pagination-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取分页数据
$pagination = $this->getData('pagination') ?? [];
$currentPage = (int)($pagination['current_page'] ?? 1);
$totalPages = (int)($pagination['total_pages'] ?? 1);

// 如果只有一页，不渲染分页
if ($totalPages <= 1) {
    return;
}

// 渲染分页组件
?>
<nav class="pagination-nav">
    <!-- 分页导航 -->
    <ul class="pagination">
        <!-- 页码按钮 -->
    </ul>
</nav>
```

## 注意事项

- 如果只有一页或没有数据，建议不渲染分页组件
- 分页链接应该保持当前的筛选和排序参数
- 建议支持页码跳转功能，提升用户体验
- Hook 文件应该处理数据为空的情况，避免报错

## 相关文档

- [WeShop Product 模块文档](../../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
