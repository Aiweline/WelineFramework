# Weline_Seo::domain::suggestion_generated - SEO建议生成完成

## 事件说明

当AI生成SEO建议完成时触发，允许其他模块监听并处理生成的建议。

## 事件类型

**Domain Event（领域事件）** - 业务领域内的事件

## 触发时机

在AI生成SEO建议任务完成后触发。

## 数据格式

```php
[
    'subject_id' => int,              // 必需：SEO主体ID
    'suggestion_id' => int,           // 必需：建议ID
    'keywords' => array,              // 可选：推荐关键词列表
    'content' => array,               // 可选：建议内容
]
```

## 可用数据

### 必需字段

- `subject_id` (integer) - SEO主体ID
- `suggestion_id` (integer) - 建议ID

### 可选字段

- `keywords` (array) - 推荐关键词列表
- `content` (array) - 建议内容，可能包含：
  - `title_suggestion`：标题建议
  - `description_suggestion`：描述建议
  - `meta_tags`：Meta标签建议
  - 其他SEO优化建议

## 使用场景

- 监听SEO建议生成结果，执行相关操作
- 同步SEO建议到外部系统
- 记录SEO建议生成日志
- 触发SEO建议应用任务

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::domain::suggestion_generated">
    <observer name="Weline_YourModule::suggestion_generated" 
              instance="Weline\YourModule\Observer\SuggestionGeneratedObserver" 
              disabled="false" 
              shared="true" 
              sort="10"/>
</event>
```

### 创建观察者类

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SuggestionGeneratedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'] ?? null;
        $suggestionId = $data['suggestion_id'] ?? null;
        $keywords = $data['keywords'] ?? [];
        $content = $data['content'] ?? [];
        
        if (!$subjectId || !$suggestionId) {
            return;
        }
        
        // 执行相关操作
        $this->handleSuggestionGenerated($subjectId, $suggestionId, $keywords, $content);
    }
    
    private function handleSuggestionGenerated(int $subjectId, int $suggestionId, array $keywords, array $content): void
    {
        // 发送通知给管理员
        $this->sendNotificationToAdmin($subjectId, $suggestionId);
        
        // 同步建议到外部系统
        $this->syncSuggestionToExternalSystem($subjectId, $suggestionId, $content);
        
        // 记录日志
        error_log("SEO建议已生成: 主体 #{$subjectId}, 建议 #{$suggestionId}");
    }
    
    private function sendNotificationToAdmin(int $subjectId, int $suggestionId): void
    {
        // 实现通知逻辑
    }
    
    private function syncSuggestionToExternalSystem(int $subjectId, int $suggestionId, array $content): void
    {
        // 实现同步逻辑
    }
}
```

## 注意事项

- SEO建议已保存到数据库
- `content` 数组包含AI生成的优化建议
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Seo::domain::keywords_extracted` - 关键词提取完成
- `Weline_Seo::domain::subject_created` - SEO主体创建
