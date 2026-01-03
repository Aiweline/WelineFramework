# Weline_Seo::integration::task_enqueued - SEO任务入队

## 事件说明

当SEO任务被加入队列时触发，允许其他模块监听任务入队事件。

## 事件类型

**Integration Event（集成事件）** - 跨模块/系统的事件

## 触发时机

在SEO任务被加入队列后触发，通常在 `TaskQueueService::enqueue()` 方法中。

## 数据格式

```php
[
    'task_id' => int,                 // 必需：任务ID
    'task_type' => string,            // 必需：任务类型
    'subject_type' => string,         // 必需：主体类型
    'subject_id' => int,              // 必需：主体ID
]
```

## 可用数据

### 必需字段

- `task_id` (integer) - 任务ID
- `task_type` (string) - 任务类型（如：feed_generate, keyword_extract, seo_analyze等）
- `subject_type` (string) - 主体类型
- `subject_id` (integer) - 主体ID

## 使用场景

- 监听任务入队，执行相关操作
- 记录任务入队日志
- 同步任务状态到外部系统
- 触发任务监控和告警

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::integration::task_enqueued">
    <observer name="Weline_YourModule::task_enqueued" 
              instance="Weline\YourModule\Observer\TaskEnqueuedObserver" 
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

class TaskEnqueuedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $taskId = $data['task_id'] ?? null;
        $taskType = $data['task_type'] ?? '';
        $subjectType = $data['subject_type'] ?? '';
        $subjectId = $data['subject_id'] ?? null;
        
        if (!$taskId || !$taskType) {
            return;
        }
        
        // 执行相关操作
        $this->handleTaskEnqueued($taskId, $taskType, $subjectType, $subjectId);
    }
    
    private function handleTaskEnqueued(int $taskId, string $taskType, string $subjectType, ?int $subjectId): void
    {
        // 记录任务入队日志
        $this->logTaskEnqueued($taskId, $taskType);
        
        // 同步任务状态到外部系统
        $this->syncTaskStatusToExternalSystem($taskId, $taskType, 'enqueued');
        
        // 触发任务监控
        $this->triggerTaskMonitoring($taskId, $taskType);
    }
    
    private function logTaskEnqueued(int $taskId, string $taskType): void
    {
        error_log("SEO任务已入队: 任务 #{$taskId}, 类型: {$taskType}");
    }
    
    private function syncTaskStatusToExternalSystem(int $taskId, string $taskType, string $status): void
    {
        // 实现同步逻辑
    }
    
    private function triggerTaskMonitoring(int $taskId, string $taskType): void
    {
        // 实现任务监控逻辑
    }
}
```

## 注意事项

- 任务已加入队列，等待处理
- 任务状态为 `pending` 或 `enqueued`
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Seo::integration::task_completed` - SEO任务处理完成
- `Weline_Seo::integration::feed_collect` - SEO Feed收集
