---
name: queue-usage
description: 队列使用。QueueInterface 实现、队列表 Model、enqueue、队列消费、Cron 自动跑队列。触发词：队列、queue、QueueInterface、enqueue、queue:run、异步任务。
globs: []
alwaysApply: false
---

# queue-usage（队列使用技能）

## 何时使用

- 创建异步队列任务（耗时操作拆离 HTTP 请求）
- 将构建/导出/发送等操作从当前请求中剥离后台执行
- 需要队列状态持久化（数据库记录，可查询进度）

## 核心接口

```php
namespace Weline\Queue\QueueInterface;

interface QueueInterface
{
    public function name(): string;                              // 队列名称（会写入 DB）
    public function tip(): string;                              // 提示信息
    public function attributes(): array;                         // EAV 属性（通常 []，数据存 content JSON）
    public function execute(\Weline\Queue\Model\Queue &$queue): string;  // 消费逻辑
    public function validate(\Weline\Queue\Model\Queue &$queue): bool;  // 校验 content
}
```

## 队列表 Model

**`Weline\Queue\Model\Queue`**

| 字段 | 说明 |
|------|------|
| `queue_id` | 主键自增 |
| `type_id` | 队列类型 ID（关联 `weline_queue_type.class`） |
| `pid` | 进程 PID（fork 成功后写入） |
| `name` | 任务名称 |
| `status` | `pending` / `running` / `done` / `error` / `stop` |
| `content` | **JSON 字符串**，存任务数据（取代 EAV 属性） |
| `result` | 执行结果文本 |
| `process` | 进度信息 |
| `finished` | 0/1 |
| `auto` | 0/1，是否参与 Cron 自动消费 |
| `module` | 所属模块名 |

**状态常量**：`Queue::status_pending` / `status_running` / `status_done` / `status_error` / `status_stop`

## 队列类型 Model

**`Weline\Queue\Model\Queue\Type`**

| 字段 | 说明 |
|------|------|
| `type_id` | 主键 |
| `name` | 队列类型名称 |
| `module_name` | 模块名 |
| `class` | 处理器实现类（含完整命名空间） |
| `tip` | 提示 |
| `enable` | 0/1 |

**自动注册**：模块目录放 `Queue/*.php` 实现 `QueueInterface`，运行 `php bin/w queue:collect` 即可写入 DB（也可在代码里手动 `save()` Type 记录）。

## 最小示例

```php
// app/code/Your/Module/Queue/YourTaskQueue.php
namespace Your\Module\Queue;

use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class YourTaskQueue implements QueueInterface
{
    public function name(): string
    {
        return '你的任务队列';
    }

    public function tip(): string
    {
        return '异步执行耗时任务';
    }

    public function attributes(): array
    {
        // 数据全部存 Queue.content JSON，不需要 EAV 属性
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = json_decode($queue->getContent(), true);
        if (empty($content['target_id'])) {
            $queue->setResult('缺少 target_id 参数');
            return false;
        }
        return true;
    }

    public function execute(Queue &$queue): string
    {
        $content = json_decode($queue->getContent(), true);
        $targetId = (int)($content['target_id'] ?? 0);

        try {
            // 执行业务逻辑
            $result = $this->doWork($targetId);

            $queue->setStatus(Queue::status_done);
            $queue->setResult('任务完成：' . $result);
            $queue->save();

            return '任务完成';
        } catch (\Throwable $e) {
            $queue->setStatus(Queue::status_error);
            $queue->setResult('任务失败：' . $e->getMessage());
            $queue->save();

            return '任务失败：' . $e->getMessage();
        }
    }
}
```

## enqueue（入队）方法

### 方式一：手动创建队列表记录 + fork

适用于**不想走 Cron，希望立即执行**的场景（如 AiSiteAgent 的构建任务）：

```php
use Weline\Queue\Model\Queue as QueueModel;
use Weline\Framework\System\Process\Processer;

/** @var QueueModel $queue */
$queue = ObjectManager::getInstance(QueueModel::class);

$queue->reset()
    ->setTypeId($typeId)                  // 通过 resolveBuildQueueTypeId() 查出来的 ID
    ->setName('任务名称：' . $publicId)
    ->setContent(json_encode([
        'public_id' => $publicId,
        'admin_id'  => $adminId,
        'execution_token' => $executionToken,
        'scope_patch' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
    ->setStatus(QueueModel::status_pending)
    ->setAuto(false)                      // 不走 Cron，由本进程 fork
    ->setModule('GuoLaiRen_PageBuilder')
    ->setResult('等待执行...');

$queueId = (int)$queue->save(true);

// 立即 fork 进程执行（后台模式）
$command = sprintf(
    '%s %s --id=%d',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(BP . 'bin/w'),
    $queueId
);
$pid = Processer::create($command, false, false);

// 记录 PID
$queue->setPid($pid)->save();
```

### 方式二：auto=1 交给 Cron 消费

适用于**不紧急、可等待定时任务**的场景：

```php
$queue->reset()
    ->setTypeId($typeId)
    ->setName('任务名称')
    ->setContent(json_encode($data, JSON_UNESCAPED_UNICODE))
    ->setStatus(QueueModel::status_pending)
    ->setAuto(true)   // Cron 会自动消费
    ->setModule('Your_Module')
    ->setResult('等待执行...')
    ->save();
```

Cron 配置在 `app\code\Weline\Queue\Cron\Queue.php`，表达式 `*/1 * * * *`（每分钟）。

## 队列消费命令

```bash
# 手动消费指定队列
php bin/w queue:run --id=1

# 收集队列类型（扫描模块目录的 Queue/*.php）
php bin/w queue:collect
```

## 查询队列状态

```php
use Weline\Queue\Model\Queue as QueueModel;

/** @var QueueModel $queue */
$queue = ObjectManager::getInstance(QueueModel::class);
$queue->reset()
    ->where('finished', 0)
    ->where('auto', 1)
    ->where('status', 'done', '!=')
    ->pagination(1, 10)
    ->select()
    ->fetch();

foreach ($queue->getItems() as $item) {
    echo $item->getStatus();   // pending / running / error
    echo $item->getProcess();   // 进度文本
    echo $item->getResult();   // 结果文本
}
```

## 通过队列类型 ID 查类型

```php
use Weline\Queue\Model\Queue\Type as QueueType;

/** @var QueueType $type */
$type = ObjectManager::getInstance(QueueType::class);
$type->reset()
    ->where(QueueType::schema_fields_class, YourQueueClass::class)
    ->find()
    ->fetch();

$typeId = (int)$type->getId();
```

## 禁止

- **不要**直接 new 队列处理器，应通过 `ObjectManager::getInstance()`
- `content` 是 JSON 字符串，不要存复杂对象（先 `json_encode`）
- `auto=true` 的队列不要在同一个请求里再 fork，会重复执行
- 队列消费进程内**不要**输出任何内容到 stdout（会影响 CLI 输出），用 `$queue->setProcess()` / `$queue->setResult()` 写 DB

## 进度更新（在 execute 中）

```php
// 更新进度文本（前端可通过轮询队列状态看到）
$queue->setProcess("正在处理第 {$i}/{$total} 项...");
$queue->save();

// 完成
$queue->setStatus(Queue::status_done);
$queue->setResult("完成，共处理 {$success} 项，失败 {$fail} 项");
$queue->save();
```

## 完整流程示例（立即执行型）

```
HTTP 请求                         队列进程
   │                                │
   ▼                                │
handleStartBuild()                  │
   │                                │
   ├─ 创建 Queue 记录（auto=0）     │
   │                                │
   ├─ fork: php bin/w queue:run     │
   │   （后台进程，不阻塞响应）       │
   │                                │
   ▼ 返回 success 给前端             │
   │                                │
   │                     queue:run 读取队列
   │                                │
   │                     执行 execute()
   │                                │
   │                     通过 appendWorkspaceEvent()
   │                       写 session 事件
   │                                │
   │                     SSE Observer 读取事件
   │                                │
   │                     前端 UI 更新 ◄──┘
```

## 关联文件路径

- 队列接口：`app\code\Weline\Queue\QueueInterface.php`
- 队列表 Model：`app\code\Weline\Queue\Model\Queue.php`
- 队列类型 Model：`app\code\Weline\Queue\Model\Queue\Type.php`
- 基础抽象类：`app\code\Weline\Queue\Queue\AbstractQueue.php`
- Cron 消费者：`app\code\Weline\Queue\Cron\Queue.php`
- CLI 消费命令：`app\code\Weline\Queue\Console\Queue\Run.php`
- 收集命令：`app\code\Weline\Queue\Console\Queue\Collect.php`
- 框架 Helper：`app\code\Weline\Queue\Helper\Helper.php`
- Feed 生成队列参考：`app\code\Weline\GenerativeEngineOptimization\Queue\FeedGenerateQueue.php`
