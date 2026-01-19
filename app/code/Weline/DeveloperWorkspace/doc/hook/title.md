# Weline DeveloperWorkspace 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`title`
- **显示名称**：开发工具标题
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在开发工具面板中显示标题内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/title.phtml`

## 使用场景

- 自定义开发工具面板标题
- 显示开发环境标识

## 示例代码

```html
<!-- 在模块的 view/hooks/title.phtml 文件中 -->
<lang>开发</lang>
```

## 注意事项

- 此 hook 用于开发工具面板的标题显示
- 通常显示简短的开发环境标识
