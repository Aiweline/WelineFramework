<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Subscription\Queue\SubscriptionRenewalConsumer;
use Weline\Subscription\Service\SubscriptionSchedulerService;
use Weline\Subscription\Service\SubscriptionService;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

/** P4B2-H: Queue adapter consumes only the public Queue task context. */
final class SubscriptionRenewalConsumerTest extends TestCase
{
    public function testValidTickPayloadRunsScheduler(): void
    {
        $subscriptions = SubscriptionService::forTesting();
        $subscriptions->rollout()->setMode(
            SubscriptionService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0'],
        );
        $scheduler = SubscriptionSchedulerService::forTesting($subscriptions);
        $created = $subscriptions->create([
            'customer_id' => 'cust-queue',
            'website_id' => 0,
            'provider_code' => 'interval_monthly',
            'plan_code' => 'plan-queue',
            'idempotency_key' => 'idem-queue',
        ]);
        $content = json_encode([
            'operation' => 'tick',
            'subscription_id' => $created['subscription_id'],
        ], JSON_THROW_ON_ERROR);
        $queue = $this->createMock(QueueTaskContextInterface::class);
        $queue->method('getContent')->willReturn($content);
        $queue->method('getBizKey')->willReturn('subscription-renewal-test');
        $queue->method('getId')->willReturn(101);

        $consumer = new SubscriptionRenewalConsumer($scheduler);
        self::assertTrue($consumer->validate($queue));
        $result = json_decode($consumer->execute($queue), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('QUEUE_DONE', $result['marker']);
        self::assertSame('tick', $result['operation']);
        self::assertNotEmpty($result['order_ref']);
        self::assertSame('succeeded', $result['attempt_status']);
    }

    public function testInvalidRecoverPayloadFailsValidationWithoutExecution(): void
    {
        $queue = $this->createMock(QueueTaskContextInterface::class);
        $queue->method('getContent')->willReturn('{"operation":"recover","subscription_id":"sub-x"}');
        $queue->expects(self::once())->method('setResult');

        $consumer = new SubscriptionRenewalConsumer(
            SubscriptionSchedulerService::forTesting(),
        );
        self::assertFalse($consumer->validate($queue));
    }
}

