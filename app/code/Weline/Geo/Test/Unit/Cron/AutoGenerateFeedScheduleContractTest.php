<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Cron\AutoGenerateFeed;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\FeedQueueService;
use Weline\Geo\Service\FeedScheduleService;

final class AutoGenerateFeedScheduleContractTest extends TestCase
{
    public function testCronRunsEveryTenMinutes(): void
    {
        $cron = new AutoGenerateFeed();
        self::assertSame('*/10 * * * *', $cron->cron_time());
        self::assertSame('Weline_Geo::auto_generate_feed', $cron->name());
    }

    public function testDefaultFrequencyConstantAndItemAddDoesNotEnqueue(): void
    {
        self::assertSame('every_10_min', Feed::FREQUENCY_EVERY_10_MIN);
        self::assertSame(600, FeedScheduleService::MIN_CHECK_INTERVAL_SECONDS);

        $dispatch = $this->getMockBuilder(\Weline\Queue\Service\QueueDispatchService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $queue = new FeedQueueService($dispatch);
        self::assertSame(0, $queue->enqueueFeedItemAdd(1, 'product', 99, []));
    }
}
