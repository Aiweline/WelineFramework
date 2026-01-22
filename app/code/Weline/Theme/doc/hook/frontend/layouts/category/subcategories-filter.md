# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::layouts::category::subcategories-filter`
- **显示名称**：分类页子分类筛选
- **功能说明**：在分类页左侧筛选栏中渲染子分类筛选区域，支持显示上级分类返回入口和当前分类的直接子分类列表。

## 使用方法

在你的业务模块中创建 Hook 实现文件：

- 路径：`view/hooks/Weline_Theme--frontend--layouts--category--subcategories-filter.phtml`

## 使用场景

- 在分类筛选侧边栏中展示当前分类的所有直接子分类，便于用户按照子分类快速筛选商品
- 在子分类列表上方提供“上级分类”返回入口，帮助用户回退到上一层分类
- 在默认筛选组件（例如价格区间、属性筛选）之前或之后插入自定义的分类导航内容

## 示例代码

```php
<?php
/** @var \Weline\Framework\View\Template $this */

// 从模板数据中获取当前分类信息（推荐）
$category = $this->getData('category') ?? null;

if (!is_array($category) || empty($category['category_id'])) {
    return;
}

$children = $category['children'] ?? [];

if (empty($children)) {
    return;
}
?>

<div class="category-subcategories-filter">
    <div class="category-filter-section category-filter-subcategories">
        <div class="category-filter-section-header">
            <h4 class="category-filter-section-title">
                <lang>子分类</lang>
                <span class="category-filter-count">(<?= count($children) ?>)</span>
            </h4>
        </div>
        <div class="category-filter-section-content">
            <ul class="category-subcategories-list">
                <?php foreach ($children as $child): ?>
                    <?php
                    $handle = trim((string)($child['handle'] ?? ''), '/');
                    if ($handle === '') {
                        continue;
                    }
                    $url = $this->getUrl('catalog/category/' . rawurlencode($handle));
                    ?>
                    <li class="category-subcategory-item">
                        <a href="<?= htmlspecialchars($url ?? '') ?>" class="category-subcategory-link">
                            <?= htmlspecialchars($child['name'] ?? '') ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
```

## 执行顺序

在分类页默认布局 `frontend/layouts/category/default.phtml` 中，此 Hook 的执行顺序如下：

1. `Weline_Theme::frontend::layouts::category::filters-before`
2. `Weline_Theme::frontend::layouts::category::subcategories-filter`（当前 Hook）
3. 通过 `meta.filter_sidebar` / `filter_sidebar` 注入的自定义筛选内容
4. `sidebar` 类型的筛选小部件（`<w:widget type="sidebar" name="category-filters"/>`）
5. `Weline_Theme::frontend::layouts::category::filters-after`

## 注意事项

- 此 Hook 仅在使用 `category/default.phtml` 分类布局时执行
- 建议使用主题提供的 CSS 变量（如 `--color-text-primary`、`--color-border-light` 等）以保持风格一致
- 如果你的模块需要完全接管子分类区域，可以在实现模板中输出完整结构，并根据需要隐藏默认的筛选组件

