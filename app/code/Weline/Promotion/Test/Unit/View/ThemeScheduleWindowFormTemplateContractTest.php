<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeScheduleWindowFormTemplateContractTest extends TestCase
{
    public function testThemeFormExposesUtcWindowFields(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/promotion/theme/form.phtml');
        self::assertIsString($src);
        self::assertStringContainsString('promotion-theme-starts-at', $src);
        self::assertStringContainsString('promotion-theme-ends-at', $src);
        self::assertStringContainsString('按站点时区', $src);
        self::assertStringContainsString('utcSqlToLocalInput', $src);
    }
}
