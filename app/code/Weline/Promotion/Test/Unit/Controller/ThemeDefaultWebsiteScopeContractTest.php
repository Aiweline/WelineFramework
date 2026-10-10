<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class ThemeDefaultWebsiteScopeContractTest extends TestCase
{
    public function testThemeIndexTreatsWebsiteIdZeroAsScoped(): void
    {
        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Theme.php');
        self::assertStringContainsString("hasGet('website_id')", $ctrl);
        self::assertStringNotContainsString(
            "scope_filter_active', \$websiteId > 0 ||",
            $ctrl
        );

        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/promotion/theme/index.phtml'
        );
        self::assertStringContainsString(
            "'website_id' => \$scopeFilterActive ? \$filterWebsiteId : null",
            $tpl
        );
        self::assertStringNotContainsString(
            "'website_id' => \$filterWebsiteId > 0 ? \$filterWebsiteId : null",
            $tpl
        );
    }
}
