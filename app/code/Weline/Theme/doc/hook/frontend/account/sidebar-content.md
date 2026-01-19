# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`account.sidebar.content`
- **显示名称**：账户侧边栏内容
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在账户页面的侧边栏内容区域注入内容，允许其他模块添加自定义内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/account.sidebar.content.phtml`

## 使用场景

- 在账户侧边栏添加自定义内容块
- 显示用户相关的信息卡片
- 添加功能快捷入口

## 示例代码

```html
<!-- 在模块的 view/hooks/account.sidebar.content.phtml 文件中 -->
<div class="sidebar-content-block">
    <h5><lang>自定义内容</lang></h5>
    <p><lang>这里是自定义内容区域</lang></p>
</div>
```

## HTML 结构

此 hook 可以返回任意 HTML 内容，这些内容会被插入到账户侧边栏的内容区域中。

## 注意事项

- 此 hook 用于账户侧边栏的内容区域
- 可以返回复杂的 HTML 结构
- 建议使用与主题一致的样式类
