# Weline DeveloperWorkspace 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`dev-tool-panel`
- **显示名称**：开发工具面板
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页面中显示开发工具面板，提供路由查看、API文档等功能。仅在开发模式下显示。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/dev-tool-panel.phtml`

## 使用场景

- 开发模式下显示开发工具面板
- 提供路由查看功能
- 提供API文档访问
- 调试和开发辅助

## 示例代码

```html
<!-- 在模块的 view/hooks/dev-tool-panel.phtml 文件中 -->
<?php
// 开发工具面板内容
// 此 hook 通常由 Weline_DeveloperWorkspace 模块实现
?>
```

## 注意事项

- 此 hook 仅在开发模式（DEV=true）下显示
- 面板位置固定在页面右侧
- 可以通过双击面板头部收起/展开
- 支持拖拽移动位置
