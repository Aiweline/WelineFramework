# Weline ThemeFancy 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`add_link`
- **显示名称**：添加链接
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在主题中添加自定义链接内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/add_link.phtml`

## 使用场景

- 在主题中添加自定义导航链接
- 添加功能入口链接
- 根据用户状态显示不同的链接

## 示例代码

```html
<!-- 在模块的 view/hooks/add_link.phtml 文件中 -->
<a href="/custom/page" class="nav-link">自定义链接</a>
```

## 注意事项

- 此 hook 用于在主题中添加额外的链接
- 可以返回单个链接或多个链接
- 建议使用主题的导航样式类
