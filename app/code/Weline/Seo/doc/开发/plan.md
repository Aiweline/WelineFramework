# Weline_Seo 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Seo 负责：
- 注册 SEO 相关消息主题
- 迁移现有通知调用到 w_msg()

## 变更内容

### 新增文件：extends.php

注册主题提供者。

### 新增文件：Extends/NotificationTopicProvider.php

提供 SEO 相关消息主题。

```php
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'sitemap_submit',
                'name' => __('站点地图提交'),
                'group' => 'seo_management',
                'group_name' => __('SEO 管理'),
                'icon' => 'ri-file-list-line',
                'color' => '#34c38f',
            ],
            [
                'code' => 'seo_warning',
                'name' => __('SEO 警告'),
                'group' => 'seo_management',
                'group_name' => __('SEO 管理'),
                'icon' => 'ri-alert-line',
                'color' => '#f1b44c',
            ],
        ];
    }
}
```

### 修改文件

迁移以下文件中的通知调用：
- Cron/SitemapSubmit.php - 站点地图提交通知

## 进度跟踪

详见 [task.md](./task.md)
