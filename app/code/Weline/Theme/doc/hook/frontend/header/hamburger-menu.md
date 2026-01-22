# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-hamburger-menu`
- **显示名称**：页头汉堡菜单
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头主导航左侧显示“全部”汉堡菜单按钮，允许其他模块实现打开侧边栏分类菜单或自定义导航入口。如果未实现该 Hook，将显示默认的“全部”汉堡按钮。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-hamburger-menu.phtml`

## 使用场景

- 打开左侧“全部分类”侧边栏导航
- 在移动端/平板端提供折叠菜单入口
- 实现自定义抽屉式导航、活动中心入口、应用列表等

## 默认结构参考

在主题默认模板 `header/default.phtml` 中，未实现 Hook 时的结构大致如下：

```html
<a href="#" class="hamburger-menu-btn" id="hamburger-menu"
   role="button"
   aria-label="<lang>打开所有类别菜单</lang>"
   aria-expanded="false"
   aria-controls="categories-list">
    <i class="fas fa-bars" aria-hidden="true"></i>
    <span class="hamburger-label"><lang>全部</lang></span>
</a>
```

## 示例代码

```php
<!-- 在模块的 view/hooks/header-hamburger-menu.phtml 文件中 -->
<?php
/** @var \Weline\Framework\View\Template $this */
?>
<a href="#"
   class="hamburger-menu-btn"
   id="hamburger-menu"
   role="button"
   aria-label="<?= __('打开全部分类菜单') ?>"
   aria-expanded="false"
   aria-controls="categories-sidebar">
    <i class="fas fa-bars" aria-hidden="true"></i>
    <span class="hamburger-label"><?= __('全部') ?></span>
</a>
```

> 侧边栏实际内容由 `header/default.phtml` 中的分类侧边栏结构和 JavaScript 控制逻辑负责，本 Hook 只需提供触发按钮。

## CSS 类说明

- `.hamburger-menu-btn`：汉堡菜单按钮样式
- `.hamburger-label`：按钮文本（如“全部”）
- `.header-main-nav`：主导航容器（由主题模板提供）

## 注意事项

- 建议使用 `aria-*` 属性保证无障碍访问（`aria-label`、`aria-expanded`、`aria-controls` 等）。
- 建议配合主题已有的分类侧边栏（`#categories-sidebar`）使用，保持交互一致性。
- 如果 Hook 模板未输出任何内容，将回退到默认的汉堡菜单按钮。

