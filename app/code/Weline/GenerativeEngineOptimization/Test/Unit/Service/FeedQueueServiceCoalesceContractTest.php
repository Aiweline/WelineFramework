<?php

declare(strict_types=1);

namespace Weline\GenerativeEngineOptimization\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\GenerativeEngineOptimization\Service\FeedQueueService;
use Weline\Queue\Service\QueueDispatchService;

final class FeedQueueServiceCoalesceContractTest extends TestCase
{
    public function testSlotKeysCollapseRealtimeWavesPerFeed(): void
    {
        $service = new FeedQueueService($this->createMock(QueueDispatchService::class));

        self::assertSame('generate:12:json_feed', $service->generateSlotKey(12));
        self::assertSame('push:12:all', $service->pushSlotKey(12, []));
        self::assertSame('push:12:2,9', $service->pushSlotKey(12, [9, 2, 2]));
        self::assertSame(
            $service->generateSlotKey(12, 'xml'),
            $service->generateSlotKey(12, 'xml'),
        );
    }

    public function testAdmissionUsesQueueQueryCreateIfAbsentSlots(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/FeedQueueService.php');

        self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
        self::assertStringContainsString("createIfAbsent", $source);
        self::assertStringContainsString("':followup'", $source);
        self::assertStringContainsString('requeueQueueSafely', $source);
        self::assertStringContainsString('FeedGenerateQueue::class', $source);
        self::assertStringContainsString('FeedPushQueue::class', $source);
        self::assertStringNotContainsString('ObjectManager::getInstance(Type::class)', $source);
        self::assertDoesNotMatchRegularExpression(
            "/->setStatus\\(Queue::status_pending\\)/",
            $source,
        );
    }
}
