# Weline Bot 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Bot::chat::message::tools`
- **显示名称**：消息工具栏扩展
- **功能说明**：在聊天消息的工具栏区域触发，允许其他模块为消息添加自定义操作按钮，如复制、重新生成、收藏等。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Bot--chat--message--tools.phtml`

## 使用场景

- 添加复制消息按钮
- 添加重新生成按钮
- 添加收藏/书签功能
- 添加分享功能
- 添加消息反馈按钮（有用/无用）

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Bot--chat--message--tools.phtml 文件中 -->
<div class="message-tools">
    <button class="btn btn-icon btn-sm" onclick="copyMessage(<?= $messageId ?>)" title="<?= __('复制') ?>">
        <i class="mdi mdi-content-copy"></i>
    </button>
    <button class="btn btn-icon btn-sm" onclick="regenerateMessage(<?= $messageId ?>)" title="<?= __('重新生成') ?>">
        <i class="mdi mdi-refresh"></i>
    </button>
    <button class="btn btn-icon btn-sm" onclick="bookmarkMessage(<?= $messageId ?>)" title="<?= __('收藏') ?>">
        <i class="mdi mdi-bookmark-outline"></i>
    </button>
</div>
```

## 注意事项

- 工具按钮应使用图标而非文字，保持简洁
- 提供 tooltip 说明按钮功能
- 避免添加过多按钮影响用户体验
