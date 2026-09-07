<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Service\FeedQueueService;
use Weline\Queue\Service\QueueDispatchService;

final class FeedQueueServiceCoalesceContractTest extends TestCase
{
    public function testSlotKeysCollapseRealtimeWavesPerFeed(): void
    {
        $service = new FeedQueueService($this->createMock(QueueDispatchService::class));

        self::assertSame('generate:12:json_feed', $service->generateSlotKey(12));
        self::assertSame('generate:12:json_feed', $service->generateSlotKey(12, 'json_feed'));
        self::assertNotSame(
            $service->generateSlotKey(12, 'json_feed'),
            $service->generateSlotKey(13, 'json_feed'),
        );
        self::assertSame('push:12:all', $service->pushSlotKey(12, []));
        self::assertSame('push:12:1,3', $service->pushSlotKey(12, [3, 1, 1]));
        self::assertNotSame($service->pushSlotKey(12, []), $service->pushSlotKey(12, [1]));
    }

    public function testAdmissionUsesCreateIfAbsentSlotsInsteadOfBareCreate(): void
    {
        $source = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/FeedQueueService.php');

        self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
        self::assertStringContainsString("createIfAbsent", $source);
        self::assertStringContainsString("':followup'", $source);
        self::assertStringContainsString('requeueQueueSafely', $source);
        self::assertStringContainsString('FeedGenerateQueue::class', $source);
        self::assertStringContainsString('FeedPushQueue::class', $source);
        self::assertDoesNotMatchRegularExpression(
            "/w_query\\(\\s*'queue'\\s*,\\s*'create'\\s*,/",
            $source,
        );
    }
}
