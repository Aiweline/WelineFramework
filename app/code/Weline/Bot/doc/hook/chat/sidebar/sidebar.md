# Weline Bot 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Bot::chat::sidebar`
- **显示名称**：聊天侧边栏扩展
- **功能说明**：在聊天控制台的侧边栏区域触发，允许其他模块在侧边栏添加自定义内容，如快捷指令、历史会话列表等。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Bot--chat--sidebar.phtml`

## 使用场景

- 添加快捷指令按钮
- 显示历史会话列表
- 展示用户收藏的提示词模板
- 添加角色切换控件
- 显示使用统计信息

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Bot--chat--sidebar.phtml 文件中 -->
<div class="bot-sidebar-section">
    <h5><?= __('快捷指令') ?></h5>
    <div class="quick-actions">
        <button class="btn btn-sm btn-outline-primary" onclick="insertPrompt('总结这篇文档')">
            <i class="mdi mdi-file-document-outline"></i> 总结文档
        </button>
        <button class="btn btn-sm btn-outline-primary" onclick="insertPrompt('翻译成英文')">
            <i class="mdi mdi-translate"></i> 翻译
        </button>
    </div>
</div>
```

## 注意事项

- 保持样式与聊天控制台一致
- 避免添加过多内容影响性能
- 使用框架的主题变量而非硬编码颜色
