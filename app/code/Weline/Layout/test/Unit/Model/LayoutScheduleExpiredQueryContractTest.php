<?php

declare(strict_types=1);

namespace Weline\Layout\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class LayoutScheduleExpiredQueryContractTest extends TestCase
{
    public function testExpiredActiveSchedulesUsesIsNotNullNotEmptyStringBind(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/LayoutSchedule.php',
        );

        self::assertStringContainsString("function getExpiredActiveSchedules()", $source);
        self::assertStringContainsString("IS NOT NULL", $source);
        self::assertStringNotContainsString("schema_fields_END_TIME, '', '!='", $source);
        self::assertStringNotContainsString("END_TIME, '', '!='", $source);
    }
}
