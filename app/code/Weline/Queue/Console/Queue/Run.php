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
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Exception\QueueDeferredCompletionException;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface as LegacyQueueInterface;
use Weline\Queue\Service\QueueDispatchService;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private const DEFAULT_WORKER_MEMORY_LIMIT = '512M';
    private const DEFAULT_WORKER_MEMORY_LIMIT_BY_CLASS = [];
    private const CONSUMER_BOOTSTRAP_FAILURE_PREFIX = 'QUEUE_CONSUMER_BOOTSTRAP_FAILED:';

    private Printing $printing;
    private Queue $queue;
    private QueueDispatchService $queueDispatchService;

    public function __construct(
        Printing $printing,
        Queue $queue,
        ?QueueDispatchService $queueDispatchService = null,
    ) {
        $this->printing = $printing;
        $this->queue = $queue;
        $this->queueDispatchService = $queueDispatchService
            ?? ObjectManager::getInstance(QueueDispatchService::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $this->disableCliExecutionTimeout();

        $id = $args['id'] ?? 0;
        $force = !empty($args['f']) || !empty($args['force']);
        $dispatchToken = \strtolower(\trim((string)(
            $args['dispatch-token'] ?? $args['dispatch_token'] ?? ''
        )));
        $launchId = \strtolower(\trim((string)(
            $args['launch-id'] ?? $args['launch_id'] ?? ''
        )));
        $workerQueueTaskName = \trim((string)($args['name'] ?? ''));
        if ($id == 0) {
            $this->printing->error(__('请输入队列ID。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=1'));
            exit();
        }
        if (($dispatchToken === '' && $launchId !== '')
            || ($dispatchToken !== '' && (
                \preg_match('/^[a-f0-9]{64}$/', $dispatchToken) !== 1
                || $launchId === ''
                || !\hash_equals($dispatchToken, $launchId)
                || $workerQueueTaskName === ''
            ))
        ) {
            $this->printing->warning(__('Queue Worker 启动身份参数无效，未读取或执行队列。'));

            return 'QUEUE_NOOP: queue_worker_identity_invalid';
        }
        if ($dispatchToken !== '') {
            $workerPid = $this->currentProcessId();
            $workerQueueId = (int)$id;
            $this->registerShutdownCallback(function () use (
                $workerQueueId,
                $dispatchToken,
                $workerPid,
                $workerQueueTaskName,
            ): void {
                $this->queueDispatchService->releaseClaimedWorkerLeaseByTaskName(
                    $workerQueueId,
                    $dispatchToken,
                    $workerPid,
                    $workerQueueTaskName,
                );
            });
        }
        $queue = $this->loadFreshQueue((int)$id);
        if (empty($queue->getId())) {
            $this->printing->error(__('队列不存在。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=%{1}', $id));
            if ($dispatchToken !== '') {
                return 'QUEUE_NOOP: queue_not_found';
            }
            exit();
        }
        if ($queue->isScopeQuarantined()) {
            $message = (string)__('队列 Scope 已 quarantine，拒绝领取或执行。');
            $this->printing->error($message);
            throw new \RuntimeException('queue_scope_quarantined: ' . $message);
        }
        if ($dispatchToken !== '') {
            $claimedQueue = $this->queueDispatchService->attachClaimedWorker(
                $workerQueueId,
                $dispatchToken,
                $workerPid,
            );
            if (!$claimedQueue instanceof Queue) {
                $message = (string)__('Queue 派发令牌无效或已过期，Worker 不执行任何消费者。');
                $this->printing->warning($message);

                return 'QUEUE_NOOP: queue_dispatch_fence_rejected';
            }
            $queue = $claimedQueue;
        }
        $takeoverOnly = !empty($args['takeover-only']) || !empty($args['no-execute']);
        if ($force && $takeoverOnly) {
            $this->printing->note(__('强制接管会在释放队列后立即返回；后续执行由系统调度器负责。'));
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => (int)$id,
                'force' => true,
                'owner' => 'system_scheduler',
                'reason' => 'queue_run_takeover_only',
                'mark_force_rebuild' => true,
                'clear_output' => false,
            ]);
            $defaultMessage = (string)__('队列接管完成，等待系统调度。');
            $message = \is_array($takeover)
                ? (string)($takeover['message'] ?? $defaultMessage)
                : $defaultMessage;
            if (!\is_array($takeover) || empty($takeover['success'])) {
                $this->printing->error($message);
                exit();
            }
            $this->printing->note($message);

            return $message;
        }
        $currentPid = $this->currentProcessId();
        $existingPid = (int)($queue->getPid() ?: 0);
        $existingStatus = \trim((string)$queue->getStatus());
        if ($existingStatus === $queue::status_running && $existingPid !== $currentPid) {
            if (!$force) {
                $this->printing->error(__('队列 #%{1} 正在运行中，禁止重复运行（pid=%{2}）。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
                $this->printing->warning(__('如需接管，请使用 --force；系统会先终止当前同 ID 任务后再启动新任务。'));
                exit();
            }
            $this->printing->warning(__('强制模式：检测到队列 #%{1} 正在运行（pid=%{2}），将先安全接管并交回系统调度；当前 CLI 不直接执行。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => (int)$id,
                'force' => true,
                'owner' => 'system_scheduler',
                'reason' => 'queue_run_force_takeover',
                'mark_force_rebuild' => true,
                'clear_output' => false,
            ]);
            $defaultMessage = (string)__('队列接管完成，等待系统调度。');
            $message = \is_array($takeover)
                ? (string)($takeover['message'] ?? $defaultMessage)
                : $defaultMessage;
            if (!\is_array($takeover) || empty($takeover['success'])) {
                $this->printing->error($message);
                exit();
            }
            $this->printing->note($message);

            return $message;
        }

        if ($dispatchToken === '') {
            $claimed = $this->queueDispatchService->claimQueueForManualRun(
                (int)$id,
                $currentPid,
                [],
                $force,
            );
            if (empty($claimed['confirmed'])) {
                $message = (string)($claimed['message'] ?? __('队列 CLI 认领失败。'));
                $this->printing->error($message);

                return 'QUEUE_NOOP: queue_manual_claim_failed';
            }
            if ($force && !empty($claimed['force_prepared'])) {
                $this->printing->warning(__('已启用强制模式(-f)：本次执行将优先使用队列类中的强制重建逻辑。'));
                $this->printing->note(__('已清空该队列历史输出，本次仅展示最新执行过程。'));
            }
            $queue = $this->loadFreshQueue((int)$id);
        }

        $consumerExecutionStarted = false;
        try {
            # 类型解析、消费者实例化与 validate 均已位于 ownership 异常收口内。
            $type = $queue->getType();
            $queueClass = \ltrim((string)$type->getData('class'), '\\');
            $this->applyCliMemoryLimitForQueueClass($queueClass);
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
            if ($validate_result) {
                $marked = $this->queueDispatchService->markQueueWorkerExecutingSafely(
                    (int)$id,
                    $dispatchToken,
                    $currentPid,
                );
                if (empty($marked['confirmed'])) {
                    $message = (string)($marked['message'] ?? __('Queue Worker 已失去当前执行代次 ownership。'));
                    $this->printing->warning($message);

                    return 'QUEUE_NOOP: queue_worker_fence_lost';
                }
                if (\is_array($marked['data'] ?? null)) {
                    $queue->clearData()->setData($marked['data']);
                }
                $queue->setExecutionArgs($args); # 记录执行参数
                // From this point on, failures belong to the consumer itself.
                // Resolution, construction, interface checks, validation and
                // ownership activation are bootstrap failures and receive a
                // stable code before the Worker exits.
                $consumerExecutionStarted = true;
                $result = $this->executeQueueConsumer($queue_execute, $queue);
                $completed = $this->queueDispatchService->completeQueueWorkerSafely(
                    (int)$id,
                    $dispatchToken,
                    $currentPid,
                    $result,
                );
                if (empty($completed['confirmed'])) {
                    $this->printing->warning(
                        (string)($completed['message'] ?? __('Queue Worker 终态因执行代次变化未写入。'))
                    );
                }
                $this->printing->title(__('队列执行详情') . ' queue_id=' . $id);
                $this->printing->note((string)($completed['data'][Queue::schema_fields_result] ?? $result));
            } else {
                $result = (string)__('队列消息内容验证不通过。');
                $this->printing->error($result);
                $this->queueDispatchService->failQueueWorkerSafely(
                    (int)$id,
                    $dispatchToken,
                    $currentPid,
                    $result,
                    true,
                );
            }
        } catch (QueueDeferredCompletionException $deferred) {
            $result = $deferred->queueResult();
            $deferredCompletion = $this->queueDispatchService->deferQueueWorkerSafely(
                (int)$id,
                $dispatchToken,
                $currentPid,
                $deferred->queueContent(),
                $deferred->processMessage(),
                $result,
                $deferred->notBefore(),
            );
            if (empty($deferredCompletion['confirmed'])) {
                $this->printing->warning(
                    (string)($deferredCompletion['message']
                        ?? __('Queue Worker 延期终态因执行代次变化未写入。'))
                );

                return 'QUEUE_NOOP: queue_worker_fence_lost';
            }
            $this->printing->title(__('队列已安全延期') . ' queue_id=' . $id);
            $this->printing->note(
                (string)($deferredCompletion['data'][Queue::schema_fields_result] ?? $result)
            );
        } catch (\Throwable $e) {
            $failureDetail = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
            $bootstrapFailure = !$consumerExecutionStarted;
            $result = $bootstrapFailure
                ? self::CONSUMER_BOOTSTRAP_FAILURE_PREFIX . $failureDetail
                : $failureDetail;
            $failed = $this->queueDispatchService->failQueueWorkerSafely(
                (int)$id,
                $dispatchToken,
                $currentPid,
                $result,
                false,
                $bootstrapFailure ? self::CONSUMER_BOOTSTRAP_FAILURE_PREFIX : '',
            );
            $this->printing->title(__('队列执行详情（失败）') . ' queue_id=' . $id);
            $this->printing->note((string)($failed['data'][Queue::schema_fields_result] ?? $result));
            $this->printing->error($result);
            throw $e;
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
        // P1b：Scope 感知消费者执行前校验信封 kind + 必需维度（fail-closed）
        if ($consumer instanceof \Weline\Queue\Api\ScopedQueueConsumerInterface) {
            (new \Weline\Queue\Service\ScopedQueueConsumerGuard())->assertStoredEnvelopeAccepted(
                $consumer,
                $queue->getScopeEnvelope(),
            );
        }
        if ($consumer instanceof QueueConsumerInterface) {
            return $consumer->execute($queue);
        }

        return $consumer->execute($queue);
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

    protected function registerShutdownCallback(callable $callback): void
    {
        \register_shutdown_function($callback);
    }

    protected function currentProcessId(): int
    {
        return (int)\getmypid();
    }

    protected function loadFreshQueue(int $queueId): Queue
    {
        $queue = $this->newQueueModel();
        $queue->where(Queue::schema_fields_ID, $queueId)->find()->fetch();

        return $queue;
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
                '--dispatch-token' => '内部派发 fencing token；不匹配时 Worker 立即 no-op',
            ],
            [],
            []
        );
    }
}
