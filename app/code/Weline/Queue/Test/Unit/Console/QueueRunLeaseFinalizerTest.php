<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Queue\Console\Queue\Run;
use Weline\Queue\Exception\QueueDeferredCompletionException;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\QueueInterface;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Queue\Service\QueueScopeProducerMapping;

final class QueueRunLeaseFinalizerTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testQuarantinedQueueFailsCommandBeforeClaimOrConsumerResolution(): void
    {
        $service = new RecordingQueueDispatchService();
        $queue = self::queue([
            Queue::schema_fields_ID => 77,
            Queue::schema_fields_status => Queue::status_stop,
            Queue::schema_fields_finished => 1,
            Queue::schema_fields_pid => 0,
            Queue::schema_fields_result => QueueScopeProducerMapping::QUARANTINE_RESULT_PREFIX
                . ' unprovable',
        ]);
        $run = new TestableQueueRun($service, $queue);

        try {
            $run->execute(['id' => 77]);
            self::fail('Quarantined Queue command must fail non-zero.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('queue_scope_quarantined', $exception->getMessage());
        }

        self::assertSame(['load'], $run->timeline);
        self::assertSame([], $service->workerFailures);
    }

    public function testFinalizerRegistersBeforeMissingQueueLoadAndUsesArgvIdentity(): void
    {
        $service = new RecordingQueueDispatchService();
        $run = new TestableQueueRun($service, self::queue([]));

        $result = $run->execute([
            'id' => 77,
            'name' => 'queue-demo-77',
            'launch-id' => self::TOKEN,
            'dispatch-token' => self::TOKEN,
        ]);

        self::assertSame('QUEUE_NOOP: queue_not_found', $result);
        self::assertSame(['register', 'load'], $run->timeline);
        self::assertInstanceOf(\Closure::class, $run->shutdownCallback);

        ($run->shutdownCallback)();

        self::assertSame([[
            'queue_id' => 77,
            'dispatch_token' => self::TOKEN,
            'pid' => 4242,
            'queue_task_name' => 'queue-demo-77',
        ]], $service->leaseReleases);
    }

    public function testInvalidInternalIdentityNeverLoadsQueue(): void
    {
        $service = new RecordingQueueDispatchService();
        $run = new TestableQueueRun($service, self::queue([]));

        $result = $run->execute([
            'id' => 77,
            'name' => 'queue-demo-77',
            'launch-id' => self::TOKEN,
            'dispatch-token' => 'invalid',
        ]);

        self::assertSame('QUEUE_NOOP: queue_worker_identity_invalid', $result);
        self::assertSame([], $run->timeline);
        self::assertNull($run->shutdownCallback);
        self::assertSame([], $service->leaseReleases);
    }

    public function testLaunchIdWithoutDispatchTokenFailsClosedBeforeQueueLoad(): void
    {
        $service = new RecordingQueueDispatchService();
        $run = new TestableQueueRun($service, self::queue([]));

        $result = $run->execute([
            'id' => 77,
            'launch-id' => self::TOKEN,
        ]);

        self::assertSame('QUEUE_NOOP: queue_worker_identity_invalid', $result);
        self::assertSame([], $run->timeline);
        self::assertNull($run->shutdownCallback);
    }

    public function testTypeResolutionExceptionTerminatesClaimedWorkerGeneration(): void
    {
        $queue = self::throwingTypeQueue([
            Queue::schema_fields_ID => 77,
            Queue::schema_fields_type_id => 1,
            Queue::schema_fields_status => Queue::status_running,
            Queue::schema_fields_pid => 4242,
            Queue::schema_fields_DISPATCH_TOKEN => self::TOKEN,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_auto => 0,
        ]);
        $service = new RecordingQueueDispatchService();
        $service->attachedQueue = $queue;
        $run = new TestableQueueRun($service, $queue);

        try {
            $run->execute([
                'id' => 77,
                'name' => 'queue-demo-77',
                'launch-id' => self::TOKEN,
                'dispatch-token' => self::TOKEN,
            ]);
            self::fail('Type resolution must throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('type-resolution-failed', $exception->getMessage());
        }

        self::assertCount(1, $service->workerFailures);
        self::assertSame(77, $service->workerFailures[0]['queue_id']);
        self::assertSame(self::TOKEN, $service->workerFailures[0]['dispatch_token']);
        self::assertSame(4242, $service->workerFailures[0]['pid']);
        self::assertSame(
            'QUEUE_CONSUMER_BOOTSTRAP_FAILED:type-resolution-failed',
            $service->workerFailures[0]['result'],
        );
        self::assertSame(
            'QUEUE_CONSUMER_BOOTSTRAP_FAILED:',
            $service->workerFailures[0]['process_message'],
        );
    }

    public function testDeferredConsumerUsesFencedFinalizerInsteadOfFailurePath(): void
    {
        $queue = self::deferredTypeQueue([
            Queue::schema_fields_ID => 77,
            Queue::schema_fields_type_id => 1,
            Queue::schema_fields_status => Queue::status_running,
            Queue::schema_fields_pid => 4242,
            Queue::schema_fields_DISPATCH_TOKEN => self::TOKEN,
            Queue::schema_fields_DISPATCH_UNTIL => null,
            Queue::schema_fields_finished => 0,
            Queue::schema_fields_auto => 1,
            Queue::schema_fields_content => '{"request_id":"request-1"}',
            Queue::schema_fields_process => 'old-process',
            Queue::schema_fields_result => 'old-result',
        ]);
        $service = new RecordingQueueDispatchService();
        $service->attachedQueue = $queue;
        $run = new TestableQueueRun($service, $queue);

        $result = $run->execute([
            'id' => 77,
            'name' => 'queue-demo-77',
            'launch-id' => self::TOKEN,
            'dispatch-token' => self::TOKEN,
        ]);

        self::assertSame('{"status":"pending"}', $result);
        self::assertCount(1, $service->workerDeferrals);
        self::assertSame(self::TOKEN, $service->workerDeferrals[0]['dispatch_token']);
        self::assertSame(4242, $service->workerDeferrals[0]['pid']);
        self::assertSame('{"request_id":"request-1","wait":1}', $service->workerDeferrals[0]['content']);
        self::assertSame('2099-01-01 00:00:05', $service->workerDeferrals[0]['not_before']);
        self::assertSame([], $service->workerFailures);
    }

    /** @param array<string,mixed> $row */
    private static function queue(array $row): Queue
    {
        /** @var Queue $queue */
        $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();
        $queue->setData($row);

        return $queue;
    }

    /** @param array<string,mixed> $row */
    private static function throwingTypeQueue(array $row): Queue
    {
        /** @var ThrowingTypeQueue $queue */
        $queue = (new \ReflectionClass(ThrowingTypeQueue::class))->newInstanceWithoutConstructor();
        $queue->setData($row);

        return $queue;
    }

    /** @param array<string,mixed> $row */
    private static function deferredTypeQueue(array $row): Queue
    {
        /** @var DeferredTypeQueue $queue */
        $queue = (new \ReflectionClass(DeferredTypeQueue::class))->newInstanceWithoutConstructor();
        $queue->setData($row);

        return $queue;
    }
}

final class ThrowingTypeQueue extends Queue
{
    public function getType(): \Weline\Queue\Model\Queue\Type
    {
        throw new \RuntimeException('type-resolution-failed');
    }
}

final class DeferredTypeQueue extends Queue
{
    public function getType(): Type
    {
        /** @var Type $type */
        $type = (new \ReflectionClass(Type::class))->newInstanceWithoutConstructor();
        $type->setData(Type::schema_fields_class, DeferredQueueConsumer::class);

        return $type;
    }
}

final class DeferredQueueConsumer implements QueueInterface
{
    public function name(): string
    {
        return 'deferred-test';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '';
    }

    public function validate(Queue &$queue): bool
    {
        return true;
    }

    public function execute(Queue &$queue): string
    {
        throw new QueueDeferredCompletionException(
            '{"request_id":"request-1","wait":1}',
            'waiting',
            '{"status":"pending"}',
            '2099-01-01 00:00:05',
        );
    }
}

final class TestableQueueRun extends Run
{
    /** @var list<string> */
    public array $timeline = [];
    public ?\Closure $shutdownCallback = null;

    public function __construct(
        QueueDispatchService $service,
        private readonly Queue $loadedQueue,
    ) {
        parent::__construct(new Printing(), self::emptyQueue(), $service);
    }

    protected function registerShutdownCallback(callable $callback): void
    {
        $this->timeline[] = 'register';
        $this->shutdownCallback = $callback(...);
    }

    protected function currentProcessId(): int
    {
        return 4242;
    }

    protected function loadFreshQueue(int $queueId): Queue
    {
        $this->timeline[] = 'load';

        return $this->loadedQueue;
    }

    private static function emptyQueue(): Queue
    {
        /** @var Queue $queue */
        $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();

        return $queue;
    }
}

final class RecordingQueueDispatchService extends QueueDispatchService
{
    /** @var list<array{queue_id:int,dispatch_token:string,pid:int,queue_task_name:string}> */
    public array $leaseReleases = [];
    public ?Queue $attachedQueue = null;
    /** @var list<array{queue_id:int,dispatch_token:string,pid:int,result:string,prepend:bool,process_message:string}> */
    public array $workerFailures = [];
    /** @var list<array{queue_id:int,dispatch_token:string,pid:int,content:string,process_message:string,result:string}> */
    public array $workerDeferrals = [];

    public function __construct()
    {
        parent::__construct(
            self::emptyQueue(),
            (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor(),
        );
    }

    public function releaseClaimedWorkerLeaseByTaskName(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $queueTaskName,
    ): bool {
        $this->leaseReleases[] = [
            'queue_id' => $queueId,
            'dispatch_token' => $dispatchToken,
            'pid' => $pid,
            'queue_task_name' => $queueTaskName,
        ];

        return true;
    }

    public function attachClaimedWorker(int $queueId, string $dispatchToken, int $pid): ?Queue
    {
        return $this->attachedQueue;
    }

    public function failQueueWorkerSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $result,
        bool $prepend = false,
        string $processMessage = '',
    ): array {
        $this->workerFailures[] = [
            'queue_id' => $queueId,
            'dispatch_token' => $dispatchToken,
            'pid' => $pid,
            'result' => $result,
            'prepend' => $prepend,
            'process_message' => $processMessage,
        ];

        return [
            'confirmed' => true,
            'data' => [
                Queue::schema_fields_status => Queue::status_error,
                Queue::schema_fields_pid => 0,
                Queue::schema_fields_DISPATCH_TOKEN => null,
                Queue::schema_fields_DISPATCH_UNTIL => null,
                Queue::schema_fields_result => $result,
            ],
        ];
    }

    public function markQueueWorkerExecutingSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
    ): array {
        return [
            'confirmed' => true,
            'data' => $this->attachedQueue?->getData() ?? [],
        ];
    }

    public function deferQueueWorkerSafely(
        int $queueId,
        string $dispatchToken,
        int $pid,
        string $content,
        string $processMessage,
        string $result,
        string $notBefore,
    ): array {
        $this->workerDeferrals[] = [
            'queue_id' => $queueId,
            'dispatch_token' => $dispatchToken,
            'pid' => $pid,
            'content' => $content,
            'process_message' => $processMessage,
            'result' => $result,
            'not_before' => $notBefore,
        ];

        return [
            'confirmed' => true,
            'data' => [
                Queue::schema_fields_status => Queue::status_pending,
                Queue::schema_fields_pid => 0,
                Queue::schema_fields_DISPATCH_TOKEN => null,
                Queue::schema_fields_DISPATCH_UNTIL => null,
                Queue::schema_fields_NOT_BEFORE => $notBefore,
                Queue::schema_fields_result => $result,
            ],
        ];
    }

    private static function emptyQueue(): Queue
    {
        /** @var Queue $queue */
        $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();

        return $queue;
    }
}
