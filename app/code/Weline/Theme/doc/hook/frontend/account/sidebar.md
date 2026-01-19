# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`account.sidebar`
- **显示名称**：账户侧边栏
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在账户页面的侧边栏导航中注入内容，允许其他模块添加自定义导航项。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/account.sidebar.phtml`

## 使用场景

- 在账户侧边栏添加自定义导航项
- 添加模块特定的功能入口
- 根据用户权限显示不同的导航项

## 示例代码

```html
<!-- 在模块的 view/hooks/account.sidebar.phtml 文件中 -->
<a class="nav-link" href="/custom/feature">
    <i class="ri-settings-line"></i> <span><lang>自定义功能</lang></span>
</a>
```

## HTML 结构

此 hook 应该返回导航链接元素（`<a>` 标签），这些元素会被包裹在账户侧边栏的导航区域中。

## 注意事项

- 此 hook 用于账户页面的侧边栏导航
- 建议使用与主题一致的导航样式类
- 可以使用图标和文字组合显示
