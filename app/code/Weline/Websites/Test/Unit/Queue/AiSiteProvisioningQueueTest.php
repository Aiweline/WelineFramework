<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Queue;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Queue\Exception\QueueDeferredCompletionException;
use Weline\Queue\Model\Queue;
use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Queue\AiSiteProvisioningQueue;
use Weline\Websites\Service\AiSiteProvisioningJobHandler;

final class AiSiteProvisioningQueueTest extends TestCase
{
    public function testDeadWorkerRecoveryReturnsAttemptPatchWithoutConsumerPersistence(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $consumer = new AiSiteProvisioningQueue($handler);

        $recoverable = $this->getMockBuilder(Queue::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getContent', 'setContent', 'save'])
            ->getMock();
        $recoverable->method('getContent')->willReturn('{"request_id":"req-1"}');
        $recoverable->expects(self::never())->method('setContent');
        $recoverable->expects(self::never())->method('save');
        self::assertTrue($consumer->shouldRecoverDeadWorker($recoverable, 123, ''));
        $patch = $consumer->deadWorkerRecoveryPatch($recoverable, 123, '');
        self::assertSame(
            1,
            \json_decode(
                $patch[Queue::schema_fields_content],
                true,
                512,
                \JSON_THROW_ON_ERROR
            )['_dead_worker_retries']
        );
        self::assertStringContainsString(
            '1/3',
            $consumer->deadWorkerRecoveryMessage($recoverable, 123, '')
        );

        $exhausted = $this->getMockBuilder(Queue::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getContent', 'setContent', 'save'])
            ->getMock();
        $exhausted->method('getContent')->willReturn('{"_dead_worker_retries":3}');
        $exhausted->expects(self::never())->method('setContent');
        $exhausted->expects(self::never())->method('save');
        self::assertFalse($consumer->shouldRecoverDeadWorker($exhausted, 456, ''));
        self::assertSame([], $consumer->deadWorkerRecoveryPatch($exhausted, 456, ''));
    }

    public function testFirstAuthorizationPendingUsesFrameworkDeferredCompletionWithoutConsumerPersistence(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', false)
            ->willReturn([
                'status' => 'pending',
                'target_domain' => 'demo-site.weline.test',
                'authorization_pending' => true,
                'authorization_already_started' => false,
            ]);
        $queueModel = $this->queueModel([
            'request_id' => 'request-1',
            'execution_token' => 'token-1',
        ]);

        $deferred = $this->captureDeferredCompletion(
            static fn() => (new AiSiteProvisioningQueue(
                $handler,
                static fn (): int => 100,
                static fn (string $domain): array => []
            ))->execute($queueModel)
        );

        $decodedResult = \json_decode($deferred->queueResult(), true, 512, \JSON_THROW_ON_ERROR);
        $decodedContent = \json_decode($deferred->queueContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('pending', $decodedResult['status']);
        self::assertTrue($decodedResult['authorization_pending']);
        self::assertSame('1970-01-01 00:01:45', $deferred->notBefore());
        self::assertSame($deferred->notBefore(), $decodedResult['not_before']);
        self::assertStringContainsString('Scheduler', $deferred->processMessage());
        self::assertSame(
            [
                'domain' => 'demo-site.weline.test',
                'started_at' => 100,
                'deadline_at' => 520,
                'checks' => 1,
                'takeover_token' => '',
            ],
            $decodedContent['_hosts_authorization_wait_v1']
        );
    }

    public function testPendingGenerationOnlyPollsResolverWithoutLaunchingAnotherAuthorization(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::never())->method('handle');
        $queueModel = $this->queueModel($this->waitingContent(100));

        $deferred = $this->captureDeferredCompletion(
            static fn() => (new AiSiteProvisioningQueue(
                $handler,
                static fn (): int => 160,
                static fn (string $domain): array => []
            ))->execute($queueModel)
        );

        $decodedContent = \json_decode($deferred->queueContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(2, $decodedContent['_hosts_authorization_wait_v1']['checks']);
        self::assertSame('demo-site.weline.test', $decodedContent['_hosts_authorization_wait_v1']['domain']);
        self::assertSame(520, $decodedContent['_hosts_authorization_wait_v1']['deadline_at']);
        self::assertSame('1970-01-01 00:02:50', $deferred->notBefore());
    }

    public function testLoopbackResolutionAutomaticallyContinuesSameRequest(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', false)
            ->willReturn([
                'status' => 'done',
                'target_domain' => 'demo-site.weline.test',
                'website_bound' => 1,
                'website_id' => 0,
            ]);
        $queueModel = $this->queueModel($this->waitingContent(100));

        $result = (new AiSiteProvisioningQueue(
            $handler,
            static fn (): int => 160,
            static fn (string $domain): array => ['127.0.0.1']
        ))->execute($queueModel);

        self::assertSame('done', \json_decode($result, true, 512, \JSON_THROW_ON_ERROR)['status']);
    }

    public function testRepeatedPendingResultCannotResetExistingAuthorizationDeadline(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', false)
            ->willReturn([
                'status' => 'pending',
                'target_domain' => 'demo-site.weline.test',
                'authorization_pending' => true,
            ]);
        $queueModel = $this->queueModel($this->waitingContent(100));

        $deferred = $this->captureDeferredCompletion(
            static fn() => (new AiSiteProvisioningQueue(
                $handler,
                static fn (): int => 160,
                static fn (string $domain): array => ['127.0.0.1']
            ))->execute($queueModel)
        );

        $wait = \json_decode(
            $deferred->queueContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR
        )['_hosts_authorization_wait_v1'];
        self::assertSame(100, $wait['started_at']);
        self::assertSame(520, $wait['deadline_at']);
        self::assertSame(2, $wait['checks']);
        self::assertSame('1970-01-01 00:02:50', $deferred->notBefore());
    }

    public function testExpiredAuthorizationMarksRequestTerminalWithoutOpeningAnotherPrompt(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', true)
            ->willThrowException(new AiSiteProvisioningException(
                'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
                'authorization expired'
            ));
        $queueModel = $this->queueModel($this->waitingContent(100));

        $this->expectException(AiSiteProvisioningException::class);
        $this->expectExceptionMessage('authorization expired');

        (new AiSiteProvisioningQueue(
            $handler,
            static fn (): int => 521,
            static fn (string $domain): array => []
        ))->execute($queueModel);
    }

    public function testExplicitTakeoverStartsFreshBoundedAuthorizationGeneration(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', false)
            ->willReturn([
                'status' => 'pending',
                'target_domain' => 'demo-site.weline.test',
                'authorization_pending' => true,
            ]);
        $content = $this->waitingContent(100);
        $content['_queue_takeover'] = ['token' => 'new-generation'];
        $queueModel = $this->queueModel($content);

        $deferred = $this->captureDeferredCompletion(
            static fn() => (new AiSiteProvisioningQueue(
                $handler,
                static fn (): int => 600,
                static fn (string $domain): array => []
            ))->execute($queueModel)
        );

        $decodedContent = \json_decode($deferred->queueContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(600, $decodedContent['_hosts_authorization_wait_v1']['started_at']);
        self::assertSame(1020, $decodedContent['_hosts_authorization_wait_v1']['deadline_at']);
        self::assertSame('new-generation', $decodedContent['_hosts_authorization_wait_v1']['takeover_token']);
        self::assertSame('1970-01-01 00:10:05', $deferred->notBefore());
    }

    public function testFutureAuthorizationStartCannotExtendWaitWindow(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', true)
            ->willThrowException(new AiSiteProvisioningException(
                'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
                'future wait rejected'
            ));
        $queueModel = $this->queueModel($this->waitingContent(601));

        $this->expectException(AiSiteProvisioningException::class);
        $this->expectExceptionMessage('future wait rejected');

        (new AiSiteProvisioningQueue(
            $handler,
            static fn (): int => 600,
            static fn (string $domain): array => []
        ))->execute($queueModel);
    }

    public function testCorruptAuthorizationStartCannotResetDeadline(): void
    {
        $handler = $this->createMock(AiSiteProvisioningJobHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with('request-1', 'token-1', true)
            ->willThrowException(new AiSiteProvisioningException(
                'TEST_DOMAIN_HOSTS_AUTHORIZATION_EXPIRED',
                'corrupt wait rejected'
            ));
        $content = $this->waitingContent(100);
        $content['_hosts_authorization_wait_v1']['started_at'] = '100';
        $queueModel = $this->queueModel($content);

        $this->expectException(AiSiteProvisioningException::class);
        $this->expectExceptionMessage('corrupt wait rejected');

        (new AiSiteProvisioningQueue(
            $handler,
            static fn (): int => 160,
            static fn (string $domain): array => []
        ))->execute($queueModel);
    }

    /** @return array<string,mixed> */
    private function waitingContent(int $startedAt): array
    {
        return [
            'request_id' => 'request-1',
            'execution_token' => 'token-1',
            '_hosts_authorization_wait_v1' => [
                'domain' => 'demo-site.weline.test',
                'started_at' => $startedAt,
                'deadline_at' => $startedAt + 420,
                'checks' => 1,
                'takeover_token' => '',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $content
     * @return Queue&MockObject
     */
    private function queueModel(array $content): Queue
    {
        $queue = $this->getMockBuilder(Queue::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getContent',
                'setContent',
                'setStatus',
                'setFinished',
                'setProcess',
                'persist',
            ])
            ->getMock();
        $queue->method('getContent')->willReturn(
            \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
        );
        $queue->expects(self::never())->method('setContent');
        $queue->expects(self::never())->method('setStatus');
        $queue->expects(self::never())->method('setFinished');
        $queue->expects(self::never())->method('setProcess');
        $queue->expects(self::never())->method('persist');

        return $queue;
    }

    private function captureDeferredCompletion(callable $execute): QueueDeferredCompletionException
    {
        try {
            $execute();
        } catch (QueueDeferredCompletionException $deferred) {
            return $deferred;
        }

        self::fail('Expected a fenced Queue deferred-completion signal.');
    }
}
