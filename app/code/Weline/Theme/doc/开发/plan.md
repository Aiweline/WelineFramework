# Weline_Theme 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Theme 负责提供 `w_msg()` JavaScript 全局函数，方便前端开发者通过 API 发送系统通知。

## 变更内容

### 修改文件：view/theme/backend/assets/js/theme.js

添加 `Weline.Message` 模块和 `window.w_msg()` 全局函数。

### JS 实现

```javascript
// Weline.Message 模块
Message: {
    send: async (topic, type, title, content, options = {}) => {
        const msgConfig = runtimeConfig.message || {};
        const endpoint = msgConfig.backendUrl || '/api_admin/backend/notification/send';

        const response = await Weline.Api.request(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                topic: topic,
                type: type,
                title: title,
                content: content,
                priority: options.priority || 5,
                metadata: options.metadata || {},
                icon: options.icon || 'ri-notification-line',
                notify_users: options.notifyUsers || [],
            })
        });

        if (!response.ok) {
            throw new Error('w_msg failed: ' + response.status);
        }
        return response.json();
    }
},

// 全局函数
window.w_msg = function (topic, type, title, content, options = {}) {
    return Weline.Message.send(topic, type, title, content, options);
};
```

### 使用示例

```javascript
w_msg('system_info', 'success', '操作成功', '数据已保存');

w_msg('domain_expiring', 'warning', '域名提醒', '域名即将到期', {
    priority: 8,
    metadata: { domain: 'example.com' }
});
```

## 进度跟踪

详见 [task.md](./task.md)
