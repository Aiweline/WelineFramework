# Weline_Seo::integration::task_completed - SEO任务处理完成

## 事件说明

当SEO任务处理完成时触发，允许其他模块监听任务完成事件。

## 事件类型

**Integration Event（集成事件）** - 跨模块/系统的事件

## 触发时机

在SEO任务处理完成后触发，通常在 `TaskProcessor::process()` 方法中。

## 数据格式

```php
[
    'task_id' => int,                 // 必需：任务ID
    'task_type' => string,            // 必需：任务类型
    'status' => string,                // 必需：任务状态（done, error）
    'result' => mixed,                 // 可选：处理结果
]
```

## 可用数据

### 必需字段

- `task_id` (integer) - 任务ID
- `task_type` (string) - 任务类型（如：feed_generate, keyword_extract, seo_analyze等）
- `status` (string) - 任务状态：done（成功）, error（失败）

### 可选字段

- `result` (mixed) - 处理结果，可能包含：
  - 成功时的结果数据
  - 失败时的错误信息

## 使用场景

- 监听任务完成，执行后续操作
- 记录任务完成日志
- 同步任务状态到外部系统
- 触发任务完成通知

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::integration::task_completed">
    <observer name="Weline_YourModule::task_completed" 
              instance="Weline\YourModule\Observer\TaskCompletedObserver" 
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

class TaskCompletedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $taskId = $data['task_id'] ?? null;
        $taskType = $data['task_type'] ?? '';
        $status = $data['status'] ?? '';
        $result = $data['result'] ?? null;
        
        if (!$taskId || !$taskType || !$status) {
            return;
        }
        
        // 执行相关操作
        $this->handleTaskCompleted($taskId, $taskType, $status, $result);
    }
    
    private function handleTaskCompleted(int $taskId, string $taskType, string $status, $result): void
    {
        // 记录任务完成日志
        $this->logTaskCompleted($taskId, $taskType, $status);
        
        // 同步任务状态到外部系统
        $this->syncTaskStatusToExternalSystem($taskId, $taskType, $status);
        
        // 如果任务失败，发送告警
        if ($status === 'error') {
            $this->sendAlert($taskId, $taskType, $result);
        }
    }
    
    private function logTaskCompleted(int $taskId, string $taskType, string $status): void
    {
        error_log("SEO任务已完成: 任务 #{$taskId}, 类型: {$taskType}, 状态: {$status}");
    }
    
    private function syncTaskStatusToExternalSystem(int $taskId, string $taskType, string $status): void
    {
        // 实现同步逻辑
    }
    
    private function sendAlert(int $taskId, string $taskType, $result): void
    {
        // 实现告警逻辑
    }
}
```

## 注意事项

- 任务已处理完成，状态为 `done` 或 `error`
- `result` 字段包含处理结果或错误信息
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Seo::integration::task_enqueued` - SEO任务入队
- `Weline_Seo::domain::keywords_extracted` - 关键词提取完成
