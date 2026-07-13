# Weline Queue 消息队列

`Weline_Queue` 提供数据库队列、类型收集、定时调度、独立 Worker 执行和运行状态记录。跨模块生产者和消费者不应引用 `Weline\Queue\Model\*`。

Queue 的后台运行控制依赖 Backend 用户数据与 Cron 进程契约，二者均为必需依赖；跨模块业务仍只通过下述 Queue 公共契约接入。

Queue 内部也不能越界调用这两个模块的实现类：

- 后台表单暂存数据使用 `Weline\Backend\Api\UserData\BackendCurrentUserDataInterface`，只传递当前登录用户下指定 scope 的数组。
- PID 检查、终止、日志清理和任务名规范化使用 `Weline\Cron\Api\Process\ProcessControlInterface`，不引用 Cron Helper。

## 公共契约

- `Weline\Queue\Api\QueueConsumerInterface`：新队列消费者契约。
- `Weline\Queue\Api\QueueTaskContextInterface`：执行时任务上下文，不暴露 Queue ORM 模型。
- `Weline\Queue\Api\QueueStatus`：`pending/running/done/error/stop` 的稳定常量。
- `Weline\Queue\QueueInterface`：旧版第三方兼容契约，仍可被收集和执行，新代码不再使用。

`queue:collect` 和 `queue:run` 同时识别新旧两套契约。旧接口的签名保持不变，不需要第三方模块立即迁移。

## 创建任务

跨模块写入统一通过 `w_query('queue', 'create', ...)`：

```php
use Vendor\Module\Queue\EmailQueue;
use Weline\Queue\Api\QueueStatus;

$result = w_query('queue', 'create', [
    'class' => EmailQueue::class,
    'name' => (string)__('发送邮件'),
    'module' => 'Vendor_Module',
    'content' => [
        'to' => 'user@example.com',
        'subject' => '通知',
    ],
    'status' => QueueStatus::PENDING,
    'auto' => true,
    'biz_key' => 'mail:notice:1001',
]);

$queueId = (int)($result['queue_id'] ?? 0);
```

按业务键去重时先读取：

```php
$existing = w_query('queue', 'getByBizKey', [
    'biz_key' => 'mail:notice:1001',
]);
```

查询、统计和更新的完整参数以以下命令为准：

```bash
php bin/w query:help queue
```

## 新消费者

```php
namespace Vendor\Module\Queue;

use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

final class EmailQueue implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('邮件发送队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('异步发送邮件');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = json_decode($queue->getContent(), true);
        if (!is_array($content) || empty($content['to'])) {
            $queue->setResult((string)__('缺少收件人'));
            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = json_decode($queue->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // 执行模块自身的发送服务。
        $queue->setProcess((string)__('邮件发送完成'));
        $queue->persist();

        return (string)__('执行成功');
    }
}
```

消费者可更新 `content/result/process/status/finished/pid`，并通过 `persist()` 持久化。不应依赖具体 Queue 模型、表名、查询对象或 EAV 内部类。

## 运行命令

```bash
php bin/w queue:collect
php bin/w queue:type:listing
php bin/w queue:type:listing Vendor_Module EmailQueue
php bin/w queue:run --id=77
php bin/w queue:run --id=77 --force
```

- `queue:collect` 扫描激活模块的 `Queue/` 类，只登记实现新或旧消费契约的可实例化类。
- `queue:type:listing` 列出已登记类型，传入词条可按类名、队列名或模块名搜索。
- `queue:run` 按 `queue_id` 执行一条任务。
- `--force` 会接管同 ID 的历史 Worker，注入 `_force_rebuild=1`，并将队列交回系统调度器。
- takeover 成功后命令会立即返回；返回之后的旧强杀/重写分支属于不可达代码，不是第二套 takeover 语义。

当前没有 `queue:status` 命令。状态查询使用 `w_query('queue', 'get'|'list'|'stats', ...)` 或后台 Queue 管理页。

## 调度与资源配置

当前生效配置：

- `queue.cron.max_concurrent`：自动队列的最大并发数。
- `queue.worker.memory_limit`：队列 Worker 默认内存上限。
- `queue.worker.memory_limit_by_class.<FQCN>`：按消费者类覆盖内存上限。

队列调度器只派发 `auto=1` 且 `status=pending` 的未完成任务。Worker 异常退出时，只有显式实现恢复契约的类型才能自动回到 `pending`，其他任务会进入可观测的错误状态。

## 模块边界

- 生产者读写队列：使用 `w_query('queue', ...)`。
- 消费者执行上下文：使用 `Weline\Queue\Api\*`。
- 使用 Queue API 的模块必须在 `etc/module.php` 的 `requires` 中声明 `Weline_Queue`，并在 Composer `require` 中声明 `weline/module-queue`。
- 模块 Setup 只安装自身 schema；队列类型由 `queue:collect` 及 `setup:upgrade` 后置收集器统一登记。
- 队列模型、Type 模型、Helper 和 Console 类都是 `Weline_Queue` 内部实现，不是跨模块 API。

## 验证

```bash
php -l app/code/Weline/Queue/Api/QueueConsumerInterface.php
php -l app/code/Weline/Queue/Api/QueueTaskContextInterface.php
php -l app/code/Weline/Queue/Api/QueueStatus.php
php bin/w queue:collect
php bin/w queue:type:listing
php bin/w query:help queue
```
