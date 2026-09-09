<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Cron\AutoPushFeed;
use Weline\Geo\Service\GeoPushSettings;

final class AutoPushFeedScheduleContractTest extends TestCase
{
    public function testCronRunsOnceDailyAtTwoAm(): void
    {
        $cron = new AutoPushFeed();
        self::assertSame('0 2 * * *', $cron->cron_time());
        self::assertSame('Weline_Geo::auto_push_feed', $cron->name());
    }

    public function testScheduledPushConfigKeyIsStable(): void
    {
        self::assertSame('geo/push/scheduled_enabled', GeoPushSettings::KEY_SCHEDULED_ENABLED);
        self::assertSame('Weline_Geo', GeoPushSettings::MODULE);
        self::assertSame('backend', GeoPushSettings::AREA);
    }
}
