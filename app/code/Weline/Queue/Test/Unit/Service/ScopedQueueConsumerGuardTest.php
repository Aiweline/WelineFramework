<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Api\ScopedQueueConsumerInterface;
use Weline\Queue\Service\ScopedQueueConsumerGuard;

/** TEST-P1B-02: a channel-only consumer rejects incomplete envelopes before side effects. */
final class ScopedQueueConsumerGuardTest extends TestCase
{
    public function testScopedConsumerRejectsUnmigratedLegacyRowInsteadOfGuessingGlobal(): void
    {
        $consumer = new class implements ScopedQueueConsumerInterface {
            public function name(): string
            {
                return 'global-only';
            }

            public function attributes(): array
            {
                return [];
            }

            public function tip(): string
            {
                return '';
            }

            public function acceptedScopeKinds(): array
            {
                return [ScopeIdentity::KIND_GLOBAL];
            }

            public function requiredScopeDimensions(): array
            {
                return [];
            }

            public function rejectedScopeMessage(ScopeEnvelope $envelope): string
            {
                return '';
            }

            public function validate(QueueTaskContextInterface $queue): bool
            {
                return true;
            }

            public function execute(QueueTaskContextInterface $queue): string
            {
                return 'must_not_run';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('缺少已迁移的 Scope 信封');
        (new ScopedQueueConsumerGuard())->assertStoredEnvelopeAccepted($consumer, null);
    }

    public function testChannelOnlyConsumerRejectsGlobalAndStoreWithoutSideEffects(): void
    {
        $consumer = new class implements ScopedQueueConsumerInterface {
            public int $executeCalls = 0;
            public int $validateCalls = 0;

            public function name(): string
            {
                return 'channel-only';
            }

            public function attributes(): array
            {
                return [];
            }

            public function tip(): string
            {
                return '';
            }

            public function acceptedScopeKinds(): array
            {
                return [ScopeIdentity::KIND_CHANNEL];
            }

            public function requiredScopeDimensions(): array
            {
                return ['website_id', 'store_code', 'channel_code', 'store_mode'];
            }

            public function rejectedScopeMessage(ScopeEnvelope $envelope): string
            {
                return 'channel_only_handler';
            }

            public function validate(QueueTaskContextInterface $queue): bool
            {
                ++$this->validateCalls;

                return true;
            }

            public function execute(QueueTaskContextInterface $queue): string
            {
                ++$this->executeCalls;

                return 'should_not_run';
            }
        };

        $guard = new ScopedQueueConsumerGuard();
        foreach ([
            ScopeEnvelope::of(ScopeIdentity::global()),
            ScopeEnvelope::of(ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL)),
        ] as $envelope) {
            try {
                $guard->assertAccepted($consumer, $envelope);
                self::fail('channel-only consumer must reject non-channel envelopes');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('不在消费者接受列表', $e->getMessage());
                self::assertStringContainsString('channel_only_handler', $e->getMessage());
            }
        }

        self::assertSame(0, $consumer->executeCalls);
        self::assertSame(0, $consumer->validateCalls);
    }

    public function testChannelConsumerAcceptsZeroWebsiteChannelAndRejectsMissingDimension(): void
    {
        $consumer = new class implements ScopedQueueConsumerInterface {
            public function name(): string
            {
                return 'channel-required';
            }

            public function attributes(): array
            {
                return [];
            }

            public function tip(): string
            {
                return '';
            }

            public function acceptedScopeKinds(): array
            {
                return [ScopeIdentity::KIND_CHANNEL, ScopeIdentity::KIND_WEBSITE];
            }

            public function requiredScopeDimensions(): array
            {
                return ['channel_code'];
            }

            public function rejectedScopeMessage(ScopeEnvelope $envelope): string
            {
                return '';
            }

            public function validate(QueueTaskContextInterface $queue): bool
            {
                return true;
            }

            public function execute(QueueTaskContextInterface $queue): string
            {
                return 'ok';
            }
        };

        $guard = new ScopedQueueConsumerGuard();
        $zeroChannel = ScopeEnvelope::of(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        $guard->assertAccepted($consumer, $zeroChannel);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('缺少必需维度 channel_code');
        $guard->assertAccepted(
            $consumer,
            ScopeEnvelope::of(ScopeIdentity::website(0, 'default')),
        );
    }
}
