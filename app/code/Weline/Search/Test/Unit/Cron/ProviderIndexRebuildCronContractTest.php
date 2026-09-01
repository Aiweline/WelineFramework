<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Search\Cron\ProviderIndexRebuild;

final class ProviderIndexRebuildCronContractTest extends TestCase
{
    public function testCronImplementsInterfaceAndSchedule(): void
    {
        $cron = new ProviderIndexRebuild();
        self::assertInstanceOf(CronTaskInterface::class, $cron);
        self::assertSame('search_provider_index_rebuild', $cron->execute_name());
        self::assertSame('*/15 * * * *', $cron->cron_time());
        self::assertGreaterThanOrEqual(60, $cron->unlock_timeout());
    }
}
