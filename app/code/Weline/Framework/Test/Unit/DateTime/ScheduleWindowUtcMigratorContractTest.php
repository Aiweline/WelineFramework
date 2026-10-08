<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\DateTime;

use PHPUnit\Framework\TestCase;

final class ScheduleWindowUtcMigratorContractTest extends TestCase
{
    public function testMigratorLoadsContributionProvidesNotHardcodedForeignModels(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/DateTime/ScheduleWindowUtcMigrator.php'
        );
        self::assertStringContainsString('ScheduleWindowUtcMigrationContributionInterface', $src);
        self::assertStringContainsString('implementationsWithPrefix', $src);
        self::assertStringNotContainsString('Marketing\\Model\\Campaign\\Campaign', $src);
        self::assertStringNotContainsString('Theme\\Model\\ThemeLayoutSchedule', $src);
        self::assertStringNotContainsString('Promotion\\Model\\PromotionActivityTheme', $src);
    }

    public function testEnvResolvesDefaultThemeConfigProviderInterface(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/App/Env.php'
        );
        self::assertStringContainsString('DefaultThemeConfigProviderInterface', $src);
        self::assertStringNotContainsString('Theme\\Api\\DefaultThemeInterface', $src);
    }
}
