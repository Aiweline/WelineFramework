# 站点绑定SEO账户事件

## 事件信息

- **事件名称**：`Weline_Seo::domain::website_account_bind`
- **事件类型**：Domain Event（领域事件）
- **版本**：1.0.0

## 事件描述

当站点需要绑定SEO账户时触发此事件。事件监听器会自动将站点与SEO账户的关联关系保存到 `weline_seo_website_account` 表中。

绑定后，该站点的 sitemap 将会在定时任务中自动提交到对应的搜索引擎。

## 数据契约

| 字段 | 类型 | 必需 | 描述 |
|------|------|------|------|
| website_id | integer | 是 | 站点ID |
| account_id | integer | 是 | SEO账户ID |
| is_auto_submit | boolean | 否 | 是否自动提交sitemap，默认为true |

## 触发时机

- 新建站点时选择SEO账户
- 编辑站点时绑定/更换SEO账户
- PageBuilder 建站时选择SEO账户
- 后台手动绑定站点与账户

## 使用示例

### 触发事件

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 绑定站点与SEO账户
$eventsManager->dispatch('Weline_Seo::domain::website_account_bind', [
    'website_id' => $websiteId,
    'account_id' => $seoAccountId,
    'is_auto_submit' => true,  // 可选，默认为true
]);
```

### 在建站表单中使用

```php
// 控制器保存方法中
if ($seoAccountId = $this->getRequest()->getPost('seo_account_id')) {
    $this->eventsManager->dispatch('Weline_Seo::domain::website_account_bind', [
        'website_id' => $website->getId(),
        'account_id' => (int)$seoAccountId,
        'is_auto_submit' => true,
    ]);
}
```

## 监听器

### WebsiteAccountBind

**类**：`Weline\Seo\Observer\WebsiteAccountBind`

**功能**：
1. 接收事件数据
2. 验证参数有效性
3. 保存或更新站点账户关联

**处理逻辑**：
- 如果站点已有绑定，则更新账户ID和自动提交设置
- 如果站点无绑定，则新建关联记录

## 关联表

### weline_seo_website_account

| 字段 | 类型 | 说明 |
|------|------|------|
| id | int | 主键 |
| website_id | int | 站点ID（唯一） |
| account_id | int | SEO账户ID |
| is_auto_submit | tinyint | 是否自动提交 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

## 相关文档

- [Sitemap架构设计](../../Sitemap架构设计.md)
- [账户与定时任务简要说明](../../账户与定时任务简要说明.md)

---

**最后更新**：2026-01-30
