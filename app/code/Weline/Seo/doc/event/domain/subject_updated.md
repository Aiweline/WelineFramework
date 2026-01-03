# Weline_Seo::domain::subject_updated - SEO主体更新

## 事件说明

当SEO主体信息被更新时触发，允许其他模块监听并处理SEO主体更新逻辑。

## 事件类型

**Domain Event（领域事件）** - 业务领域内的事件

## 触发时机

在SEO主体信息更新成功后触发。

## 数据格式

```php
[
    'subject_id' => int,              // 必需：SEO主体ID
    'subject_type' => string,          // 必需：主体类型
    'changes' => array,                // 可选：变更字段列表
]
```

## 可用数据

### 必需字段

- `subject_id` (integer) - SEO主体ID
- `subject_type` (string) - 主体类型

### 可选字段

- `changes` (array) - 变更字段列表，包含变更的字段名和值

## 使用场景

- 监听SEO主体更新，同步更新相关数据
- 同步SEO主体信息到外部系统
- 记录SEO主体更新日志
- 触发SEO重新分析任务

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::domain::subject_updated">
    <observer name="Weline_YourModule::subject_updated" 
              instance="Weline\YourModule\Observer\SubjectUpdatedObserver" 
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

class SubjectUpdatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'] ?? null;
        $subjectType = $data['subject_type'] ?? '';
        $changes = $data['changes'] ?? [];
        
        if (!$subjectId || !$subjectType) {
            return;
        }
        
        // 执行相关操作
        $this->handleSubjectUpdated($subjectId, $subjectType, $changes);
    }
    
    private function handleSubjectUpdated(int $subjectId, string $subjectType, array $changes): void
    {
        // 同步到外部系统
        $this->syncToExternalSystem($subjectId, $subjectType, $changes);
        
        // 如果URL或标题变更，触发重新分析
        if (isset($changes['url']) || isset($changes['title'])) {
            $this->triggerReanalysis($subjectId, $subjectType);
        }
        
        // 记录日志
        error_log("SEO主体已更新: {$subjectType} #{$subjectId}");
    }
    
    private function syncToExternalSystem(int $subjectId, string $subjectType, array $changes): void
    {
        // 实现同步逻辑
    }
    
    private function triggerReanalysis(int $subjectId, string $subjectType): void
    {
        // 实现重新分析逻辑
    }
}
```

## 注意事项

- SEO主体已更新到数据库
- `changes` 数组包含变更的字段信息
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Seo::domain::subject_created` - SEO主体创建
- `Weline_Seo::domain::keywords_extracted` - 关键词提取完成
