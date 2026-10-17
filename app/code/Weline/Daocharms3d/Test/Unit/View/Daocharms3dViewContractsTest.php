<?php

declare(strict_types=1);

namespace Weline\Daocharms3d\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDaocharms3dViewContractsPass().
 */
final class Daocharms3dViewContractsTest extends TestCase
{
    public function testAllDaocharms3dViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'DaocharmsTownDesignMount::testTownLivesInWidgetAndDesignHomepage' => function () {
            $this->assert_DaocharmsTownDesignMount_testTownLivesInWidgetAndDesignHomepage();
        },
        ];
    }

private function assert_DaocharmsTownDesignMount_testTownLivesInWidgetAndDesignHomepage(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Daocharms3d';
    $repoRoot = '/Users/weline/Project/Official/框架';
    $widget = $moduleRoot . '/view/templates/frontend/widgets/daocharms-town.phtml';
    $projector = $moduleRoot . '/Service/TownCatalogProjector.php';
    $designHome = $repoRoot . '/app/design/Weline/daocharms-3d/frontend/layouts/homepage/default.phtml';
    self::assertFileExists($widget);
    self::assertFileExists($projector);
    self::assertFileExists($designHome);
    self::assertFileDoesNotExist($moduleRoot . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertFileDoesNotExist($moduleRoot . '/view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml');
    $widgetSrc = (string) file_get_contents($widget);
    self::assertStringContainsString('@widget.code {daocharms-town}', $widgetSrc);
    self::assertStringContainsString('@widget.placement {injection}', $widgetSrc);
    self::assertStringContainsString('@widget.default_injections', $widgetSrc);
    self::assertStringContainsString('"slot":"daocharms-town"', $widgetSrc);
    self::assertStringContainsString('"required":true', $widgetSrc);
    self::assertStringContainsString('TownCatalogProjector', $widgetSrc);
    $homeSrc = (string) file_get_contents($designHome);
    self::assertStringContainsString('id="daocharms-town"', $homeSrc);
    self::assertStringNotContainsString('<w:widget type="content" name="daocharms-town"', $homeSrc);
}
}
