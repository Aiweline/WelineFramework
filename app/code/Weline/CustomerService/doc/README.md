# Weline CustomerService 模块

## 模块概述

Weline_CustomerService 是一个完整的客服服务模块，提供多语言实时聊天、客户语言配置、客服语言配置、邮件绑定客户等功能。

## 主要功能

### 1. 多语言实时聊天
- 支持客户和客服使用不同语言进行交流
- 消息自动翻译
- 实时消息推送（AJAX轮询）

### 2. 语言配置
- 客户可配置目标语言
- 客服可配置工作语言
- 支持多种语言切换

### 3. 邮件绑定
- 未登录用户可通过邮箱绑定会话
- 邮件验证机制
- 绑定后升级会话

### 4. 会话管理
- 支持未登录用户会话
- 客服自动分配
- 会话状态管理

### 5. 后台管理
- 客服配置管理
- 客服人员管理
- 会话查看和管理

## 技术架构

### 事件驱动的翻译服务

模块通过事件机制与翻译模块集成：

```php
// 触发翻译事件
$eventData = [
    'text' => $message,
    'source_locale' => $sourceLocale,
    'target_locale' => $targetLocale,
    'context' => 'customer_service',
    'session_id' => $sessionId
];

$eventsManager->dispatch('Weline_CustomerService::translate', $eventData);

// 从事件数据中获取翻译结果
$translatedText = $eventData['translated_text'] ?? $message;
```

翻译模块需要监听 `Weline_CustomerService::translate` 事件并提供翻译服务。

### Hook集成

模块通过Hook机制在前端页面注入客服组件：

- Hook位置：`Weline_Theme::frontend::layouts::base::body-end`
- Hook文件：`view/hooks/Weline_Theme--frontend--layouts--base--body-end.phtml`

## 数据库表结构

### customer_service_config
客服系统配置表

### customer_language
客户语言配置表

### service_agent
客服人员表

### chat_session
聊天会话表

### chat_message
聊天消息表

## API接口

### 前端接口

- `GET /customerservice/frontend/chat/getSession` - 获取或创建会话
- `POST /customerservice/frontend/chat/sendMessage` - 发送消息
- `GET /customerservice/frontend/chat/getMessages` - 获取消息列表
- `POST /customerservice/frontend/chat/setLanguage` - 设置客户语言
- `POST /customerservice/frontend/bind/sendVerification` - 发送绑定验证邮件
- `GET /customerservice/frontend/bind/verify` - 验证绑定令牌

### 后台接口

- `GET /customerservice/backend/config` - 客服配置页面
- `POST /customerservice/backend/config/save` - 保存配置
- `GET /customerservice/backend/agent` - 客服人员列表
- `POST /customerservice/backend/agent/save` - 保存客服人员
- `POST /customerservice/backend/agent/delete` - 删除客服人员
- `GET /customerservice/backend/session` - 会话列表
- `GET /customerservice/backend/session/view` - 会话详情
- `POST /customerservice/backend/session/close` - 关闭会话

## 使用说明

### 1. 安装模块

运行模块升级命令：

```bash
php bin/w setup:upgrade
```

### 2. 配置客服人员

在后台"客服服务 > 客服人员"中添加客服人员，配置：
- 关联后台用户
- 客服名称
- 邮箱
- 工作语言
- 最大并发会话数

### 3. 配置系统设置

在后台"客服服务 > 客服配置"中配置：
- 启用/禁用客服服务
- 默认客服语言
- 默认客户语言

### 4. 前端使用

前端用户访问网站时，会在页面右下角看到客服聊天按钮。点击按钮即可开始聊天。

未登录用户会收到邮件绑定提示，可以通过邮箱绑定会话。

## 翻译模块集成

翻译模块需要监听 `Weline_CustomerService::translate` 事件。

### 事件数据格式

**输入数据：**
```php
[
    'text' => '待翻译文本',           // 或 'texts' => ['文本1', '文本2'] (批量翻译)
    'source_locale' => 'zh_Hans_CN',  // 源语言
    'target_locale' => 'en_US',       // 目标语言
    'context' => 'customer_service',  // 上下文
    'session_id' => '123'             // 会话ID（可选）
]
```

**输出数据（由观察者设置）：**
```php
[
    'translated_text' => '翻译后的文本',  // 或 'translated_texts' => ['翻译1', '翻译2'] (批量翻译)
    'success' => true,                    // 是否成功
    'errors' => []                        // 错误信息（如果有）
]
```

### 示例观察者实现

```php
namespace YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CustomerServiceTranslationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $text = $event->getData('text');
        $targetLocale = $event->getData('target_locale');
        $sourceLocale = $event->getData('source_locale');
        
        // 执行翻译
        $translatedText = $this->translate($text, $sourceLocale, $targetLocale);
        
        // 设置翻译结果
        $event->setData('translated_text', $translatedText);
        $event->setData('success', true);
    }
}
```

## 依赖模块

- Weline_Framework - 核心框架
- Weline_Backend - 后台管理
- Weline_Customer - 客户模块
- Weline_Theme - 主题模块（Hook）
- Weline_Smtp - 邮件发送

## 注意事项

1. **翻译模块集成**：确保有翻译模块监听 `Weline_CustomerService::translate` 事件
2. **邮件配置**：确保SMTP模块已正确配置
3. **性能优化**：消息翻译结果会缓存，避免重复翻译
4. **安全性**：所有API接口都有权限验证

## 后续扩展

- WebSocket实时通信
- 文件上传支持
- 客服工作台
- 智能客服（AI集成）
- 客服评价系统

