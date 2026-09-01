<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Greenfield plan P2: Dashboard/WidgetDemo E2E fixtures must not require theme_layout.
 */
final class GreenfieldPlanFixtureSweepContractTest extends TestCase
{
    public function testDashboardAndWidgetDemoFixturesUseScopedWorkspace(): void
    {
        $dashboard = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Dashboard/test/e2e/backend/dashboard-default-layout-fixture.php'
        );
        $widgetDemo = (string)file_get_contents(
            dirname(__DIR__, 3) . '/WidgetDemo/test/e2e/backend/widget-demo-default-injection-fixture.php'
        );
        $dashboardService = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Dashboard/Service/DashboardViewService.php'
        );

        foreach ([$dashboard, $widgetDemo] as $fixture) {
            self::assertStringContainsString('fixture_clear_scoped_layout', $fixture);
            self::assertStringContainsString('ThemeScopedLayoutWriteService', $fixture);
            self::assertStringContainsString('theme_layout dropped', $fixture);
            self::assertStringNotContainsString('rewrite this fixture to ThemeScopedLayoutWriteService', $fixture);
        }

        self::assertStringContainsString('dashboard_scoped_layout_seed', $dashboardService);
        self::assertStringNotContainsString('projectDraft($context', $dashboardService);
        self::assertStringNotContainsString('dashboard_legacy_layout_import', $dashboardService);
    }
}
