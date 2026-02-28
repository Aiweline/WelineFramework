# Weline_I18n 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_I18n 负责：
- 注册翻译相关消息主题
- 迁移现有通知调用到 w_msg()

## 变更内容

### 新增文件：extends.php

注册主题提供者。

### 新增文件：Extends/NotificationTopicProvider.php

提供翻译相关消息主题。

```php
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'translation_complete',
                'name' => __('翻译完成'),
                'group' => 'i18n_management',
                'group_name' => __('国际化管理'),
                'icon' => 'ri-translate-2',
                'color' => '#34c38f',
            ],
            [
                'code' => 'translation_error',
                'name' => __('翻译错误'),
                'group' => 'i18n_management',
                'group_name' => __('国际化管理'),
                'icon' => 'ri-error-warning-line',
                'color' => '#f46a6a',
            ],
        ];
    }
}
```

### 修改文件

迁移以下文件中的通知调用：
- Service/AiTranslationService.php - 翻译完成通知
- Observer/AiTranslationObserver.php - 翻译错误通知

## 进度跟踪

详见 [task.md](./task.md)
