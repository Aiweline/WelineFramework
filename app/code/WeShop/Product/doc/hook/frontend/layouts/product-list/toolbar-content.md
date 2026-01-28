# WeShop Product 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`WeShop_Product::frontend::layouts::product-list::toolbar-content`
- **显示名称**：产品列表工具栏内容
- **Hook 类型**：标准格式 Hook
- **定义模块**：WeShop_Product
- **功能说明**：在产品列表页工具栏区域渲染排序、视图切换、每页数量等选项，允许用户自定义产品列表的显示方式和排序规则。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：
```
view/hooks/WeShop_Product/frontend/layouts/product-list/toolbar-content.phtml
```

## 触发时机

该 Hook 在以下时机触发：
- **触发位置**：产品列表页面的工具栏区域
- **触发条件**：当产品列表需要显示工具栏时自动触发
- **使用位置**：产品列表页面布局模板中

## 可用数据

Hook 文件可以通过 `$this->getData()` 获取以下数据：

- `products` - 产品列表（数组）
- `pagination` - 分页信息（数组）
  - `page_size` - 每页数量
  - `total` - 总记录数
- `sort_options` - 排序选项（数组）
  - `default` - 默认排序
  - `price_asc` - 价格从低到高
  - `price_desc` - 价格从高到低
  - `name_asc` - 名称 A-Z
  - `name_desc` - 名称 Z-A
  - `newest` - 最新上架
  - `bestselling` - 销量最高
- `current_sort` - 当前排序方式
- `current_view` - 当前视图模式（'grid' 或 'list'）

## 使用场景

该 Hook 适用于以下场景：
- 自定义产品列表的排序选项
- 添加额外的筛选功能
- 自定义每页数量选项
- 添加视图切换功能（网格/列表）
- 集成第三方筛选组件

## 示例代码

```phtml
<?php
/**
 * 模块名称 - 产品列表工具栏Hook
 * 
 * Hook名称：WeShop_Product::frontend::layouts::product-list::toolbar-content
 * 
 * @hook-priority 100      Hook优先级：100
 * @hook-sort-order 1      Hook排序顺序：1
 */

/** @var \Weline\Framework\View\Template $this */

// 获取工具栏数据
$sortOptions = $this->getData('sort_options') ?? [];
$currentSort = $this->getData('current_sort') ?? 'default';
$currentView = $this->getData('current_view') ?? 'grid';
$pageSize = (int)($this->getData('pagination')['page_size'] ?? 24);

// 渲染工具栏
?>
<div class="product-toolbar">
    <div class="toolbar-left">
        <span class="product-count">共 <?= count($products) ?> 件商品</span>
    </div>
    
    <div class="toolbar-right">
        <!-- 排序选择 -->
        <select id="product-sort">
            <?php foreach ($sortOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>" <?= $currentSort === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <!-- 视图切换 -->
        <div class="view-switcher">
            <button class="view-btn <?= $currentView === 'grid' ? 'active' : '' ?>" data-view="grid">网格</button>
            <button class="view-btn <?= $currentView === 'list' ? 'active' : '' ?>" data-view="list">列表</button>
        </div>
    </div>
</div>
```

## 注意事项

- 排序和视图切换应该通过URL参数或JavaScript实现，保持状态
- 建议支持每页数量选择，提升用户体验
- 工具栏应该响应式设计，适配移动端
- Hook 文件应该处理数据为空的情况，避免报错
- 可以通过 `$this->getUrl()` 方法生成带参数的URL

## 相关文档

- [WeShop Product 模块文档](../../README.md)
- [Hook 使用指南](../../../../../../Weline/Framework/Hook/doc/Hook顺序机制设计.md)
