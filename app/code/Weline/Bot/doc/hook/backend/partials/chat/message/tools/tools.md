# Weline Bot 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Bot::backend::partials::chat-message-tools`
- **显示名称**：消息工具栏扩展
- **功能说明**：在聊天消息的工具栏区域触发，允许其他模块为消息添加自定义操作按钮，如复制、重新生成、收藏等。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Bot--backend--partials--chat-message-tools.phtml`

## 使用场景

- 添加复制按钮
- 添加重新生成按钮
- 添加收藏按钮
- 添加删除按钮
- 添加编辑按钮

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Bot--backend--partials--chat-message-tools.phtml 文件中 -->
<div class="message-tools-section">
    <button class="btn btn-sm btn-outline-primary" onclick="copyMessage()">
        <i class="mdi mdi-content-copy"></i> 复制
    </button>
    <button class="btn btn-sm btn-outline-primary" onclick="regenerateMessage()">
        <i class="mdi mdi-reload"></i> 重新生成
    </button>
</div>
```

## 注意事项

- 保持样式与聊天控制台一致
- 使用框架的主题变量而非硬编码颜色
- 确保按钮功能与聊天功能兼容
