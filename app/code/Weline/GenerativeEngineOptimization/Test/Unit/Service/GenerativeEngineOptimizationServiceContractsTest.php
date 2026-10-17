<?php

declare(strict_types=1);

namespace Weline\GenerativeEngineOptimization\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\GenerativeEngineOptimization\Service\FeedQueueService;
use Weline\Queue\Service\QueueDispatchService;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllGenerativeEngineOptimizationServiceContractsPass().
 */
final class GenerativeEngineOptimizationServiceContractsTest extends TestCase
{
    public function testAllGenerativeEngineOptimizationServiceContractsPass(): void
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
            'FeedQueueServiceCoalesce::testSlotKeysCollapseRealtimeWavesPerFeed' => function () {
            $this->assert_FeedQueueServiceCoalesce_testSlotKeysCollapseRealtimeWavesPerFeed();
        },
            'FeedQueueServiceCoalesce::testAdmissionUsesQueueQueryCreateIfAbsentSlots' => function () {
            $this->assert_FeedQueueServiceCoalesce_testAdmissionUsesQueueQueryCreateIfAbsentSlots();
        },
        ];
    }

private function assert_FeedQueueServiceCoalesce_testSlotKeysCollapseRealtimeWavesPerFeed(): void
{
    $service = new FeedQueueService($this->createMock(QueueDispatchService::class));
    self::assertSame('generate:12:json_feed', $service->generateSlotKey(12));
    self::assertSame('push:12:all', $service->pushSlotKey(12, []));
    self::assertSame('push:12:2,9', $service->pushSlotKey(12, [9, 2, 2]));
    self::assertSame($service->generateSlotKey(12, 'xml'), $service->generateSlotKey(12, 'xml'));
}

private function assert_FeedQueueServiceCoalesce_testAdmissionUsesQueueQueryCreateIfAbsentSlots(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/GenerativeEngineOptimization' . '/Service/FeedQueueService.php');
    self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
    self::assertStringContainsString("createIfAbsent", $source);
    self::assertStringContainsString("':followup'", $source);
    self::assertStringContainsString('requeueQueueSafely', $source);
    self::assertStringContainsString('FeedGenerateQueue::class', $source);
    self::assertStringContainsString('FeedPushQueue::class', $source);
    self::assertStringNotContainsString('ObjectManager::getInstance(Type::class)', $source);
    self::assertDoesNotMatchRegularExpression("/->setStatus\\(Queue::status_pending\\)/", $source);
}
}
