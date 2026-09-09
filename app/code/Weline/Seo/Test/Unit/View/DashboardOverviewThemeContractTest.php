<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class DashboardOverviewThemeContractTest extends TestCase
{
    public function testDashboardOverviewUsesSeoAdminEmptyGuide(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Dashboard/index.phtml';
        $css = $root . '/view/statics/css/seo-admin.css';
        $proto = $root . '/view/statics/prototype/seo-dashboard-overview-ui.html';

        self::assertFileExists($template);
        self::assertFileExists($css);
        self::assertFileExists($proto);

        $src = (string) file_get_contents($template);
        $cssSrc = (string) file_get_contents($css);
        $protoSrc = (string) file_get_contents($proto);

        self::assertStringContainsString('data-testid="seo-dashboard-management"', $src);
        self::assertStringContainsString('data-seo-dash-ui-variant="A"', $src);
        self::assertStringContainsString('seo-admin__header', $src);
        self::assertStringContainsString('w-stat-tiles seo-dashboard-stats', $src);
        self::assertStringContainsString('data-testid="seo-dashboard-empty"', $src);
        self::assertStringContainsString('seo-dashboard-next', $src);
        self::assertStringContainsString('推荐下一步', $src);
        self::assertStringContainsString('seo/backend/embed/index', $src);
        self::assertStringNotContainsString('stat-card', $src);
        self::assertStringNotContainsString('class="empty-state"', $src);

        self::assertStringContainsString('.seo-dashboard-next__grid', $cssSrc);
        self::assertStringContainsString('--weline-theme-surface', $cssSrc);
        self::assertStringContainsString('?variant=A|B|C', $protoSrc);
        self::assertStringContainsString("verdict:'A'", $protoSrc);
    }
}
