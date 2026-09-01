<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use Weline\Framework\Test\TestCore;

class PixelErrorMonitorSeverityBadgeContractTest extends TestCore
{
    public function testIndexShowsSeverityBadgeColumn(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/PixelErrorMonitor/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('重要程度', $src);
        self::assertStringContainsString('w-badge', $src);
        self::assertStringContainsString('severityBadgeTone', $src);
        self::assertStringContainsString('box-shadow: inset 3px 0 0', $src);
        self::assertStringContainsString('pixel-error-detail-dialog', $src);
    }

    public function testDetailPageRemovedInFavorOfModal(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/PixelErrorMonitor/detail.phtml';
        self::assertFileDoesNotExist($path);
    }
}
