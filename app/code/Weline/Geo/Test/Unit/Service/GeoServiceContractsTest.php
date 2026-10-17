<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\EnsureDefaultFeedsService;
use Weline\Geo\Service\FeedQueueService;
use Weline\Queue\Service\QueueDispatchService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllGeoServiceContractsPass().
 */
final class GeoServiceContractsTest extends TestCase
{
    public function testAllGeoServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'EnsureDefaultFeedsService::testSourceCodesAreStable' => function () {
            $this->assert_EnsureDefaultFeedsService_testSourceCodesAreStable();
        },
            'FeedQueueServiceCoalesce::testSlotKeysCollapseRealtimeWavesPerFeed' => function () {
            $this->assert_FeedQueueServiceCoalesce_testSlotKeysCollapseRealtimeWavesPerFeed();
        },
            'FeedQueueServiceCoalesce::testAdmissionUsesCreateIfAbsentSlotsInsteadOfBareCreate' => function () {
            $this->assert_FeedQueueServiceCoalesce_testAdmissionUsesCreateIfAbsentSlotsInsteadOfBareCreate();
        },
        ];
    }

private function assert_EnsureDefaultFeedsService_testSourceCodesAreStable(): void
{
    self::assertSame('default_content', EnsureDefaultFeedsService::SOURCE_CODE_CONTENT);
    self::assertSame('default_product', EnsureDefaultFeedsService::SOURCE_CODE_PRODUCT);
    self::assertSame('default_article', EnsureDefaultFeedsService::SOURCE_CODE_ARTICLE);
    self::assertSame('content', Feed::TYPE_CONTENT);
    self::assertSame('product', Feed::TYPE_PRODUCT);
    self::assertSame('article', Feed::TYPE_ARTICLE);
}

private function assert_FeedQueueServiceCoalesce_testSlotKeysCollapseRealtimeWavesPerFeed(): void
{
    $service = new FeedQueueService($this->createMock(QueueDispatchService::class));
    self::assertSame('generate:12:json_feed', $service->generateSlotKey(12));
    self::assertSame('generate:12:json_feed', $service->generateSlotKey(12, 'json_feed'));
    self::assertNotSame($service->generateSlotKey(12, 'json_feed'), $service->generateSlotKey(13, 'json_feed'));
    self::assertSame('push:12:all', $service->pushSlotKey(12, []));
    self::assertSame('push:12:1,3', $service->pushSlotKey(12, [3, 1, 1]));
    self::assertNotSame($service->pushSlotKey(12, []), $service->pushSlotKey(12, [1]));
}

private function assert_FeedQueueServiceCoalesce_testAdmissionUsesCreateIfAbsentSlotsInsteadOfBareCreate(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Geo' . '/Service/FeedQueueService.php');
    self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
    self::assertStringContainsString("createIfAbsent", $source);
    self::assertStringContainsString("':followup'", $source);
    self::assertStringContainsString('requeueQueueSafely', $source);
    self::assertStringContainsString('FeedGenerateQueue::class', $source);
    self::assertStringContainsString('FeedPushQueue::class', $source);
    self::assertDoesNotMatchRegularExpression("/w_query\\(\\s*'queue'\\s*,\\s*'create'\\s*,/", $source);
}
}
