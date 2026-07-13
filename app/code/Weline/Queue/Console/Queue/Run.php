<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 15:34:45
 */

namespace Weline\Queue\Console\Queue;

use Weline\Framework\App\Env;
use Weline\Framework\Async\TaskConsumerInterface as FrameworkTaskConsumerInterface;
use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface as LegacyQueueInterface;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';
    private const DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS = [];

    private Printing $printing;
    private Queue $queue;

    public function __construct(Printing $printing, Queue $queue)
    {
        $this->printing = $printing;
        $this->queue = $queue;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $this->disableCliExecutionTimeout();

        $id = $args['id'] ?? 0;
        $force = !empty($args['f']) || !empty($args['force']);
        if ($id == 0) {
            $this->printing->error(__('请输入队列ID。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=1'));
            exit();
        }
        $queue = $this->newQueueModel()->load($id);
        if (empty($queue->getId())) {
            $this->printing->error(__('队列不存在。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=%{1}', $id));
            exit();
        }
        $takeoverOnly = !empty($args['takeover-only']) || !empty($args['no-execute']);
        if ($force && $takeoverOnly) {
            $this->printing->note('Force takeover now returns after releasing the queue; execution remains owned by the system scheduler.');
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => (int)$id,
                'force' => true,
                'owner' => 'system_scheduler',
                'reason' => 'queue_run_takeover_only',
                'mark_force_rebuild' => true,
                'clear_output' => false,
            ]);
            $message = \is_array($takeover)
                ? (string)($takeover['message'] ?? 'Queue takeover completed; waiting for system scheduler.')
                : 'Queue takeover completed; waiting for system scheduler.';
            if (!\is_array($takeover) || empty($takeover['success'])) {
                $this->printing->error($message);
                exit();
            }
            $this->printing->note($message);

            return $message;
        }
        $currentPid = (int)getmypid();
        $existingPid = (int)($queue->getPid() ?: 0);
        $existingStatus = \trim((string)$queue->getStatus());
        $sameQueueRunning = false;
        if ($existingStatus === $queue::status_running) {
            if ($existingPid > 0 && $existingPid !== $currentPid) {
                $sameQueueRunning = Processer::isRunningByPid($existingPid);
            } elseif ($existingPid === 0) {
                // 无 pid 但状态仍是 running，按“正在执行”处理，避免并发重复消费。
                $sameQueueRunning = true;
            }
        }
        if ($sameQueueRunning) {
            if (!$force) {
                $this->printing->error(__('队列 #%{1} 正在运行中，禁止重复运行（pid=%{2}）。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
                $this->printing->warning(__('如需接管，请使用 --force；系统会先终止当前同 ID 任务后再启动新任务。'));
                exit();
            }
            $this->printing->warning(__('强制模式：检测到队列 #%{1} 正在运行（pid=%{2}），将先终止旧任务再继续。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => (int)$id,
                'force' => true,
                'owner' => 'system_scheduler',
                'reason' => 'queue_run_force_takeover',
                'mark_force_rebuild' => true,
                'clear_output' => false,
            ]);
            $message = \is_array($takeover)
                ? (string)($takeover['message'] ?? 'Queue takeover completed; waiting for system scheduler.')
                : 'Queue takeover completed; waiting for system scheduler.';
            if (!\is_array($takeover) || empty($takeover['success'])) {
                $this->printing->error($message);
                exit();
            }
            $this->printing->note($message);

            return $message;
        } elseif ($existingStatus === $queue::status_running && $existingPid > 0 && !Processer::isRunningByPid($existingPid)) {
            // 兜底：running + 僵尸 pid，自动回收，允许本次继续执行。
            $queue->setStatus($queue::status_pending)
                ->setPid(0)
                ->setProcess(\trim((string)$queue->getProcess() . PHP_EOL . __('检测到历史运行进程不存在，已自动回收为 pending。')))
                ->save();
        }

        # 获取执行者
        $type = $queue->getType();
        $queueClass = \ltrim((string)$type->getData('class'), '\\');
        $this->applyCliMemoryLimitForQueueClass($queueClass);
        if ($force) {
            $content = \json_decode((string)$queue->getContent(), true);
            if (\is_array($content)) {
                $content['_force_rebuild'] = 1;
                // 强制重跑同一个队列 ID 时先清空历史 result/process，避免旧日志（含历史乱码）干扰本次观察
                $queue->setContent((string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)$queue->getContent()))
                    ->setResult('')
                    ->setProcess('')
                    ->save();
                $this->printing->warning(__('已启用强制模式(-f)：本次执行将优先使用队列类中的强制重建逻辑。'));
                $this->printing->note(__('已清空该队列历史输出，本次仅展示最新执行过程。'));
            }
        }
        /** @var FrameworkTaskConsumerInterface|QueueConsumerInterface|LegacyQueueInterface $queue_execute */
        $queue_execute = ObjectManager::getInstance($queueClass);
        if (
            !$queue_execute instanceof FrameworkTaskConsumerInterface
            && !$queue_execute instanceof QueueConsumerInterface
            && !$queue_execute instanceof LegacyQueueInterface
        ) {
            throw new \LogicException(
                FrameworkTaskConsumerInterface::class . '|' . QueueConsumerInterface::class . '|' . LegacyQueueInterface::class
            );
        }
        $validate_result = $this->validateQueueConsumer($queue_execute, $queue);
        if (is_bool($validate_result) and $validate_result) {
            $queue->setStatus($queue::status_running)
                ->setPid((int)getmypid())
                ->setResult($queue->getResult() . PHP_EOL . __('正在执行...'))
                ->save();
            try {
                $queue->setExecutionArgs($args); # 记录执行参数
                $result = $this->executeQueueConsumer($queue_execute, $queue);
                // execute() 内常通过 w_query 等直接更新库里的 result；此处必须重新 load，否则会用过期内存覆盖掉过程日志
                $queue = $this->newQueueModel()->load($id);
                if ($this->shouldPreserveQueueStateAfterExecute($queue)) {
                    $queue->setResult(\trim($queue->getResult() . PHP_EOL . $result))
                        ->save();
                    $this->printing->title(__('闃熷垪鎵ц璇︽儏') . ' queue_id=' . $id);
                    $this->printing->note($queue->getResult());

                    return $result;
                }
                $queue->setStatus($queue::status_done)
                    ->setPid(0)
                    ->setResult(\trim($queue->getResult() . PHP_EOL . $result))
                    ->save();
                $this->printing->title(__('队列执行详情') . ' queue_id=' . $id);
                $this->printing->note($queue->getResult());
            } catch (\Throwable $e) {
                $result = $e->getMessage();
                $queue = $this->newQueueModel()->load($id);
                $queue->setStatus($queue::status_error)
                    ->setPid(0)
                    ->setResult(\trim($queue->getResult() . PHP_EOL . $result))
                    ->save();
                $this->printing->title(__('队列执行详情（失败）') . ' queue_id=' . $id);
                $this->printing->note($queue->getResult());
                $this->printing->error($result);
                throw $e;
            }
        } else {
            $result = __('队列消息内容验证不通过。') . ($validate_result ? __('验证结果：') : '');
            $this->printing->error($result);
            $queue->setStatus($queue::status_error)
                ->setPid(0)
                ->setResult($result . PHP_EOL . $queue->getResult())
                ->save();
        }
        return $result;
    }

    private function validateQueueConsumer(
        FrameworkTaskConsumerInterface|QueueConsumerInterface|LegacyQueueInterface $consumer,
        Queue $queue
    ): bool {
        if ($consumer instanceof QueueConsumerInterface) {
            return $consumer->validate($queue);
        }

        return $consumer->validate($queue);
    }

    private function executeQueueConsumer(
        FrameworkTaskConsumerInterface|QueueConsumerInterface|LegacyQueueInterface $consumer,
        Queue $queue
    ): string {
        if ($consumer instanceof QueueConsumerInterface) {
            return $consumer->execute($queue);
        }

        return $consumer->execute($queue);
    }

    private function shouldPreserveQueueStateAfterExecute(Queue $queue): bool
    {
        $status = \trim((string)$queue->getStatus());
        if ($status === '' || $queue->isFinished()) {
            return false;
        }

        return \in_array($status, [
            $queue::status_pending,
            $queue::status_error,
            $queue::status_stop,
        ], true);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('运行队列. ') . 'php bin/w queue:run --id=1 [-f]';
    }

    private function newQueueModel(): Queue
    {
        return (clone $this->queue)->clearData()->clearQuery();
    }

    private function disableCliExecutionTimeout(): void
    {
        if (\PHP_SAPI !== 'cli') {
            return;
        }

        // Queue workers can run long AI/build tasks; do not inherit php.ini execution limits.
        @\ini_set('max_execution_time', '0');
        @\set_time_limit(0);
        @\ignore_user_abort(true);
    }

    private function applyCliMemoryLimitForQueueClass(string $queueClass): void
    {
        if (\PHP_SAPI !== 'cli' || $queueClass === '') {
            return;
        }

        $target = $this->resolveWorkerMemoryLimit($queueClass);
        if (!$this->shouldRaiseMemoryLimit((string)\ini_get('memory_limit'), $target)) {
            return;
        }

        @\ini_set('memory_limit', $target);
    }

    private function resolveWorkerMemoryLimit(string $queueClass): string
    {
        $configuredByClass = Env::get(
            'queue.worker.memory_limit_by_class.' . $queueClass,
            Env::get('queue.worker.memory_limit.' . $queueClass, null)
        );
        if ($configuredByClass !== null && $configuredByClass !== '') {
            return $this->normalizeMemoryLimit(
                $configuredByClass,
                self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass] ?? self::DEFAULT_WORKER_MEMORY_LIMIT
            );
        }

        if (isset(self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass])) {
            return $this->normalizeMemoryLimit(
                self::DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS[$queueClass],
                self::DEFAULT_WORKER_MEMORY_LIMIT
            );
        }

        return $this->normalizeMemoryLimit(
            Env::get('queue.worker.memory_limit', Env::get('queue.cron.memory_limit', self::DEFAULT_WORKER_MEMORY_LIMIT)),
            self::DEFAULT_WORKER_MEMORY_LIMIT
        );
    }

    private function shouldRaiseMemoryLimit(string $current, string $target): bool
    {
        $currentBytes = $this->memoryLimitToBytes($current);
        $targetBytes = $this->memoryLimitToBytes($target);
        if ($targetBytes < 0) {
            return $currentBytes >= 0;
        }
        if ($currentBytes < 0) {
            return false;
        }

        return $targetBytes > $currentBytes;
    }

    private function memoryLimitToBytes(string $value): int
    {
        $value = \trim($value);
        if ($value === '-1') {
            return -1;
        }
        if ($value === '') {
            return 0;
        }

        $unit = \strtoupper(\substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'G' => (int)($number * 1024 * 1024 * 1024),
            'M' => (int)($number * 1024 * 1024),
            'K' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    private function normalizeMemoryLimit(mixed $value, string $default): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string)(int)$value;
        }

        $value = \strtoupper(\trim((string)$value));
        $default = \strtoupper(\trim($default)) ?: self::DEFAULT_WORKER_MEMORY_LIMIT;
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }

        return $default;
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-f, --force' => '强制模式：将 _force_rebuild 注入队列内容，并清 result，避免历史输出干扰本次执行',
            ],
            [],
            []
        );
    }
}
