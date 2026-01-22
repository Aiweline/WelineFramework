# Weline Theme 模块 - Hook 文档：面包屑自定义内容（items）

## Hook 信息

- **Hook 名称**：`Weline_Theme::frontend::partials::breadcrumb::items`
- **显示名称**：Weline_Theme::frontend::partials::breadcrumb::items
- **功能说明**：在前台主题的面包屑片段内部，用于输出自定义的面包屑节点列表。当有模块在此 Hook 中输出内容时，将完全覆盖主题自带的默认面包屑列表。

## 使用场景

当某些页面（例如商品分类页、搜索结果页、活动页等）需要根据业务数据动态生成面包屑导航时，可通过实现此 Hook，在面包屑区域输出自定义的 `<ol class="breadcrumb-list">...</ol>` 结构。

典型用例如下：

- `WeShop_Catalog` 模块在分类详情页中，通过该 Hook 输出基于分类层级的面包屑路径（如 `首页 / 男装 / 衬衫`）。

## 使用方式

1. 在自定义模块中创建 Hook 模板文件（示例路径）：

```text
app/code/YourVendor/YourModule/view/hooks/Weline_Theme/frontend/partials/breadcrumb/items.phtml
```

2. 在该模板中编写输出逻辑，例如：

```php
<?php
/** @var \Weline\Framework\View\Template $this */

$items = [
    ['text' => (string)__('首页'), 'url' => $this->getUrl('')],
    ['text' => (string)__('分类'), 'url' => $this->getUrl('catalog/category')],
    ['text' => (string)__('当前页面'), 'url' => ''],
];

if (empty($items)) {
    return; // 无数据则不输出，让主题默认面包屑兜底
}
?>
<ol class="breadcrumb-list">
    <?php foreach ($items as $index => $item): ?>
        <li class="breadcrumb-item <?= empty($item['url']) ? 'active' : '' ?>">
            <?php if (!empty($item['url'])): ?>
                <a href="<?= htmlspecialchars($item['url']) ?>">
                    <?= htmlspecialchars($item['text'] ?? '') ?>
                </a>
            <?php else: ?>
                <span><?= htmlspecialchars($item['text'] ?? '') ?></span>
            <?php endif; ?>
        </li>
        <?php if ($index < count($items) - 1): ?>
            <span class="breadcrumb-separator">/</span>
        <?php endif; ?>
    <?php endforeach; ?>
</ol>
```

3. 确保在模块根目录的 `hook.php` 中正确注册该 Hook，并设置合适的优先级和文档路径。

## 注意事项

- 如果 Hook 模板未输出任何内容（例如直接 `return;`），则主题的 `breadcrumb/default.phtml` 会回退到使用 `meta.items` 或默认示例数据渲染面包屑。
- 输出的 HTML 结构应尽量复用主题已定义的 CSS 类（如 `.breadcrumb-list`、`.breadcrumb-item`、`.breadcrumb-separator`），以保持样式一致性。
