<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceDevPreview;

final class MaintenanceDevToolPanelHookContractTest extends TestCase
{
    public function testTabsAfterHookDefersToDynamicRegistration(): void
    {
        $source = $this->hook('tabs-after.phtml');

        self::assertStringContainsString('search-areas-after', $source);
        self::assertStringNotContainsString('dev-tool-tab', $source);
        self::assertStringNotContainsString('w:icon', $source);
    }

    public function testSearchAreasAfterHookRegistersFarRightParentWithSubtabs(): void
    {
        $source = $this->hook('search-areas-after.phtml');

        self::assertStringContainsString('dev-tool-search-area-maintenance-advanced', $source);
        self::assertStringContainsString('order: 9990', $source);
        self::assertStringContainsString('WelinePanel.registerTab', $source);
        self::assertStringContainsString('WelineAdvancedMaintenance', $source);
        self::assertStringContainsString('registerChild', $source);
        self::assertStringContainsString('wam-subtab', $source);
        self::assertStringContainsString('MaintenanceDevPreview::URI_MARKER', $source);
        self::assertStringContainsString('target="_blank"', $source);
        self::assertStringNotContainsString('<iframe', $source);
        self::assertStringNotContainsString('w:icon', $source);
        self::assertStringNotContainsString('dev-tool-maintenance-preview-refresh', $source);
    }

    private function hook(string $filename): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/view/hooks/Weline_DeveloperWorkspace/backend/partials/dev-tool-panel/'
            . $filename,
        );
    }
}
