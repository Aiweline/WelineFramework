<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class WidgetRegistryRefreshCronContractTest extends TestCase
{
    public function testCronReusesWidgetRegistryRefreshService(): void
    {
        $cron = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cron/WidgetRegistryRefresh.php',
        );

        self::assertStringContainsString('WidgetRegistryRefreshService', $cron);
        self::assertStringContainsString('refreshService->refresh', $cron);
        self::assertStringContainsString("'cron_widget_registry_refresh'", $cron);
        self::assertStringContainsString("execute_name(): string", $cron);
        self::assertStringContainsString("'widget_registry_refresh'", $cron);
        self::assertStringContainsString("'20 * * * *'", $cron);
        self::assertStringContainsString('unlock_timeout', $cron);
        self::assertStringNotContainsString('WidgetScanner', $cron);
        self::assertStringNotContainsString("__('", $cron);

        $refresh = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetRegistryRefreshService.php',
        );
        self::assertStringContainsString('refreshWithReport', $refresh);
        self::assertStringContainsString('Weline_Widget::widget_install_after', $refresh);
    }
}
