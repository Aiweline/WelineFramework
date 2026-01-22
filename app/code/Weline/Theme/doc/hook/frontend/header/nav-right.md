# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-nav-right`
- **显示名称**：页头右侧导航
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头主导航右侧区域渲染自定义内容，通常用于放置右侧辅助导航、活动入口、公告条、语言/货币等额外控件。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-nav-right.phtml`

> 该 Hook 输出的内容会被包裹在主题模板提供的容器：
>
> ```html
> <div class="header-nav-right">
>     <!-- header-nav-right Hook 输出内容 -->
> </div>
> ```

## 使用场景

- 在导航栏右侧添加“商家入驻”、“下载 APP”、“帮助中心”等入口
- 在特定活动期间展示醒目的活动 Banner、公告或倒计时
- 集成语言/货币切换、站点切换等辅助功能

## 示例代码

```php
<!-- 在模块的 view/hooks/header-nav-right.phtml 文件中 -->
<?php
/** @var \Weline\Framework\View\Template $this */
?>
<nav class="header-nav-right-inner">
    <ul class="header-nav-right-list">
        <li><a href="/seller/join"><lang>商家入驻</lang></a></li>
        <li><a href="/app/download"><lang>下载 APP</lang></a></li>
        <li><a href="/help"><lang>帮助中心</lang></a></li>
    </ul>
</nav>
```

## CSS 建议

- `.header-nav-right`：外层容器（由主题模板提供）
- 建议自定义：
  - `.header-nav-right-inner`：内部导航容器
  - `.header-nav-right-list`：右侧导航 `<ul>` 列表

## 注意事项

- 右侧区域宽度通常有限，建议控制导航项数量，避免在小屏幕上换行过多。
- 所有用户可见的文案需支持 i18n 翻译（示例中使用 `<lang>` 标签）。
- 如果 Hook 模板未输出任何内容，则右侧区域会保持为空白，不影响布局结构。

