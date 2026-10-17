<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Cron\AutoGenerateFeed;
use Weline\Geo\Cron\AutoPushFeed;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\FeedQueueService;
use Weline\Geo\Service\FeedScheduleService;
use Weline\Geo\Service\GeoPushSettings;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllGeoCronContractsPass().
 */
final class GeoCronContractsTest extends TestCase
{
    public function testAllGeoCronContractsPass(): void
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
            'AutoGenerateFeedSchedule::testCronRunsEveryTenMinutes' => function () {
            $this->assert_AutoGenerateFeedSchedule_testCronRunsEveryTenMinutes();
        },
            'AutoGenerateFeedSchedule::testDefaultFrequencyConstantAndItemAddDoesNotEnqueue' => function () {
            $this->assert_AutoGenerateFeedSchedule_testDefaultFrequencyConstantAndItemAddDoesNotEnqueue();
        },
            'AutoPushFeedSchedule::testCronRunsOnceDailyAtTwoAm' => function () {
            $this->assert_AutoPushFeedSchedule_testCronRunsOnceDailyAtTwoAm();
        },
            'AutoPushFeedSchedule::testScheduledPushConfigKeyIsStable' => function () {
            $this->assert_AutoPushFeedSchedule_testScheduledPushConfigKeyIsStable();
        },
        ];
    }

private function assert_AutoGenerateFeedSchedule_testCronRunsEveryTenMinutes(): void
{
    $cron = new AutoGenerateFeed();
    self::assertSame('*/10 * * * *', $cron->cron_time());
    self::assertSame('Weline_Geo::auto_generate_feed', $cron->name());
}

private function assert_AutoGenerateFeedSchedule_testDefaultFrequencyConstantAndItemAddDoesNotEnqueue(): void
{
    self::assertSame('every_10_min', Feed::FREQUENCY_EVERY_10_MIN);
    self::assertSame(600, FeedScheduleService::MIN_CHECK_INTERVAL_SECONDS);
    $dispatch = $this->getMockBuilder(\Weline\Queue\Service\QueueDispatchService::class)->disableOriginalConstructor()->getMock();
    $queue = new FeedQueueService($dispatch);
    self::assertSame(0, $queue->enqueueFeedItemAdd(1, 'product', 99, []));
}

private function assert_AutoPushFeedSchedule_testCronRunsOnceDailyAtTwoAm(): void
{
    $cron = new AutoPushFeed();
    self::assertSame('0 2 * * *', $cron->cron_time());
    self::assertSame('Weline_Geo::auto_push_feed', $cron->name());
}

private function assert_AutoPushFeedSchedule_testScheduledPushConfigKeyIsStable(): void
{
    self::assertSame('geo/push/scheduled_enabled', GeoPushSettings::KEY_SCHEDULED_ENABLED);
    self::assertSame('Weline_Geo', GeoPushSettings::MODULE);
    self::assertSame('backend', GeoPushSettings::AREA);
}
}
