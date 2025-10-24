# Weline Queue 消息队列模块

## 模块概述

Weline Queue 是系统的消息队列模块，基于数据库实现，支持异步任务处理、批量操作、定时任务等功能。该模块提供了完整的队列管理、任务执行、状态监控等企业级特性。

## 主要功能

### 1. 队列管理
- 队列任务创建和管理
- 任务状态跟踪（pending/running/done/error/stop）
- 任务进度监控
- 自动/手动执行模式

### 2. 任务执行
- 异步任务处理
- 批量任务执行
- 任务重试机制
- 错误处理和日志记录

### 3. 队列类型
- 基于 EAV 模型的动态队列类型
- 自定义队列处理器
- 队列属性配置
- 类型验证机制

### 4. 控制台命令
- 队列运行命令
- 队列收集命令
- 队列类型管理
- 批量任务处理

### 5. 监控和日志
- 任务执行状态监控
- 执行结果记录
- 错误日志管理
- 性能统计

## 使用方法

### 创建队列任务
```php
use Weline\Queue\Model\Queue;

$queue = new Queue();
$queue->setName('邮件发送任务')
    ->setTypeId(1) // 队列类型ID
    ->setContent(json_encode([
        'to' => 'user@example.com',
        'subject' => '测试邮件',
        'body' => '这是一封测试邮件'
    ]))
    ->setStatus(Queue::status_pending)
    ->setAuto(true) // 自动执行
    ->setModule('Your_Module')
    ->save();
```

### 自定义队列处理器
```php
namespace Your\Module\Queue;

use Weline\Queue\QueueInterface;
use Weline\Queue\Model\Queue;
use Weline\Eav\Model\EavAttribute;

class EmailQueue implements QueueInterface
{
    public function name(): string
    {
        return '邮件发送队列';
    }
    
    public function attributes(): array
    {
        return [
            (new EavAttribute())
                ->setCode('to')
                ->setName('收件人')
                ->setType('text')
                ->setRequired(true),
            (new EavAttribute())
                ->setCode('subject')
                ->setName('邮件主题')
                ->setType('text')
                ->setRequired(true),
            (new EavAttribute())
                ->setCode('body')
                ->setName('邮件内容')
                ->setType('textarea')
                ->setRequired(true)
        ];
    }
    
    public function tip(): string
    {
        return '用于发送邮件的队列处理器';
    }
    
    public function execute(Queue &$queue): string
    {
        $content = json_decode($queue->getContent(), true);
        
        // 执行邮件发送逻辑
        $result = $this->sendEmail($content['to'], $content['subject'], $content['body']);
        
        if ($result) {
            return '邮件发送成功';
        } else {
            throw new \Exception('邮件发送失败');
        }
    }
    
    public function validate(Queue &$queue): bool
    {
        $content = json_decode($queue->getContent(), true);
        
        if (empty($content['to']) || empty($content['subject'])) {
            $queue->setResult('收件人和主题不能为空');
            return false;
        }
        
        if (!filter_var($content['to'], FILTER_VALIDATE_EMAIL)) {
            $queue->setResult('邮箱格式不正确');
            return false;
        }
        
        return true;
    }
    
    private function sendEmail($to, $subject, $body): bool
    {
        // 实现邮件发送逻辑
        return mail($to, $subject, $body);
    }
}
```

### 队列类型注册
```php
use Weline\Queue\Model\Queue\Type;

$type = new Type();
$type->setName('邮件队列')
    ->setCode('email_queue')
    ->setClass('Your\\Module\\Queue\\EmailQueue')
    ->setDescription('用于处理邮件发送任务')
    ->save();
```

### 控制台命令使用
```bash
# 运行指定队列任务
php bin/w queue:run --id=1

# 收集队列任务
php bin/w queue:collect

# 查看队列状态
php bin/w queue:status
```

## 配置说明

### 队列配置
在 `app/etc/queue.php` 中配置队列相关设置：

```php
'queue' => [
    'default_driver' => 'database',
    'database' => [
        'table' => 'queue',
        'connection' => 'default'
    ],
    'retry_times' => 3,
    'retry_delay' => 60,
    'max_execution_time' => 300,
    'batch_size' => 100
]
```

### 队列类型配置
```php
'queue_types' => [
    'email' => [
        'name' => '邮件队列',
        'class' => 'Your\\Module\\Queue\\EmailQueue',
        'attributes' => [
            'to' => ['type' => 'text', 'required' => true],
            'subject' => ['type' => 'text', 'required' => true],
            'body' => ['type' => 'textarea', 'required' => true]
        ]
    ]
]
```

## 依赖关系

- Weline_Framework
- Weline_Eav

## 版本信息

- 当前版本：1.1.1
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 队列状态管理

### 状态说明
- `pending`: 等待执行
- `running`: 正在执行
- `done`: 执行完成
- `error`: 执行错误
- `stop`: 已停止

### 状态检查
```php
$queue = new Queue();
$queue->load($queueId);

if ($queue->isPending()) {
    echo '任务等待执行';
} elseif ($queue->isRunning()) {
    echo '任务正在执行';
} elseif ($queue->isDone()) {
    echo '任务执行完成';
} elseif ($queue->isError()) {
    echo '任务执行错误';
}
```

## 批量任务处理

### 批量创建任务
```php
use Weline\Queue\Model\Queue;

$tasks = [
    ['to' => 'user1@example.com', 'subject' => '邮件1', 'body' => '内容1'],
    ['to' => 'user2@example.com', 'subject' => '邮件2', 'body' => '内容2'],
    ['to' => 'user3@example.com', 'subject' => '邮件3', 'body' => '内容3']
];

foreach ($tasks as $task) {
    $queue = new Queue();
    $queue->setName('批量邮件发送')
        ->setTypeId($emailTypeId)
        ->setContent(json_encode($task))
        ->setStatus(Queue::status_pending)
        ->setAuto(true)
        ->setModule('Your_Module')
        ->save();
}
```

### 批量执行任务
```php
// 获取待执行的任务
$pendingQueues = $queue->clear()
    ->where([['status', Queue::status_pending]])
    ->where([['auto', 1]])
    ->select()
    ->fetchArray();

foreach ($pendingQueues as $queueData) {
    $queue = new Queue();
    $queue->load($queueData['queue_id']);
    
    // 执行任务
    $this->executeQueue($queue);
}
```

## 错误处理和重试

### 错误处理
```php
public function execute(Queue &$queue): string
{
    try {
        // 执行任务逻辑
        $result = $this->processTask($queue);
        
        if ($result) {
            return '任务执行成功';
        } else {
            throw new \Exception('任务执行失败');
        }
    } catch (\Throwable $e) {
        // 记录错误信息
        $queue->setResult('执行错误: ' . $e->getMessage());
        
        // 检查重试次数
        $retryCount = $this->getRetryCount($queue);
        if ($retryCount < $this->maxRetries) {
            $this->scheduleRetry($queue, $retryCount + 1);
        }
        
        throw $e;
    }
}
```

### 重试机制
```php
private function scheduleRetry(Queue $queue, int $retryCount): void
{
    $delay = pow(2, $retryCount) * 60; // 指数退避
    
    $queue->setStatus(Queue::status_pending)
        ->setContent(json_encode([
            'retry_count' => $retryCount,
            'original_data' => json_decode($queue->getContent(), true)
        ]))
        ->save();
    
    // 设置延迟执行
    $this->scheduleDelayedExecution($queue, $delay);
}
```

## 性能优化

### 1. 批量处理
- 使用批量查询减少数据库访问
- 批量更新任务状态
- 批量删除已完成任务

### 2. 索引优化
- 为状态字段创建索引
- 为类型字段创建索引
- 为完成状态字段创建索引

### 3. 缓存策略
- 缓存队列类型信息
- 缓存处理器实例
- 缓存配置信息

## 监控和日志

### 队列监控
```php
// 获取队列统计信息
$stats = [
    'pending' => $queue->clear()->where([['status', Queue::status_pending]])->count(),
    'running' => $queue->clear()->where([['status', Queue::status_running]])->count(),
    'done' => $queue->clear()->where([['status', Queue::status_done]])->count(),
    'error' => $queue->clear()->where([['status', Queue::status_error]])->count()
];

// 获取运行中的任务
$runningQueues = Queue::getRunningItems();
```

### 日志记录
```php
use Weline\Framework\Logger\Logger;

$logger = new Logger();

// 记录任务开始
$logger->info('队列任务开始执行', [
    'queue_id' => $queue->getId(),
    'type' => $queue->getType()->getName(),
    'start_time' => date('Y-m-d H:i:s')
]);

// 记录任务完成
$logger->info('队列任务执行完成', [
    'queue_id' => $queue->getId(),
    'result' => $result,
    'end_time' => date('Y-m-d H:i:s')
]);
```

## 最佳实践

### 1. 队列设计
- 合理划分队列类型
- 避免单个任务执行时间过长
- 设置合适的重试策略

### 2. 错误处理
- 完善的异常处理机制
- 详细的错误日志记录
- 合理的重试策略

### 3. 性能优化
- 批量处理任务
- 合理设置并发数
- 定期清理已完成任务

### 4. 监控告警
- 监控队列积压情况
- 监控任务执行时间
- 监控错误率

## 常见问题

### Q: 如何查看队列执行状态？
A: 使用 `php bin/w queue:status` 命令或通过数据库查询队列表。

### Q: 如何手动执行队列任务？
A: 使用 `php bin/w queue:run --id=队列ID` 命令。

### Q: 如何处理队列任务失败？
A: 检查任务验证逻辑，查看错误日志，必要时手动重试。

### Q: 如何优化队列性能？
A: 使用批量处理、合理设置并发数、优化数据库查询。 