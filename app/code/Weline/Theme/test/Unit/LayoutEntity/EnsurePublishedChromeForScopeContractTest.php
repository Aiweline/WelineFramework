<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Global header/footer chrome must always be ensure-baked for a scope:
 * page-only solidify, afterLayoutWrite, and migrate bootstrap all hit the gate.
 */
final class EnsurePublishedChromeForScopeContractTest extends TestCase
{
    public function testBakeCoordinatorExposesEnsureAndWiresWritePaths(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );

        self::assertStringContainsString('function ensurePublishedChromeForScope(', $src);
        self::assertStringContainsString('function bootstrapMissingChromeScopes(', $src);
        self::assertStringContainsString('markPublished(', $src);

        $afterStart = \strpos($src, 'function afterLayoutWrite(');
        self::assertNotFalse($afterStart);
        $afterEnd = \strpos($src, 'function bakeChromeFromNodes(', $afterStart);
        self::assertNotFalse($afterEnd);
        $afterBody = \substr($src, $afterStart, $afterEnd - $afterStart);
        self::assertStringContainsString('ensurePublishedChromeForScope(', $afterBody);
        self::assertStringContainsString('syncCarrierChromePayloadIfStale(', $afterBody);

        $pageStart = \strpos($src, 'function bakePageFromNodes(');
        self::assertNotFalse($pageStart);
        $pageEnd = \strpos($src, 'function rebakeAfterInjectionCollect(', $pageStart);
        self::assertNotFalse($pageEnd);
        $pageBody = \substr($src, $pageStart, $pageEnd - $pageStart);
        self::assertStringContainsString('ensurePublishedChromeForScope(', $pageBody);

        $dynamicStart = \strpos($src, 'function dynamicSolidifyPublishedPage(');
        self::assertNotFalse($dynamicStart);
        $dynamicEnd = \strpos($src, 'function rematerializePublishedPageAt(', $dynamicStart);
        self::assertNotFalse($dynamicEnd);
        $dynamicBody = \substr($src, $dynamicStart, $dynamicEnd - $dynamicStart);
        // dynamicSolidify goes through bakePageFromNodes which ensures chrome.
        self::assertStringContainsString('bakePageFromNodes(', $dynamicBody);

        $rebakeStart = \strpos($src, 'function rebakeAfterInjectionCollect(');
        self::assertNotFalse($rebakeStart);
        $rebakeEnd = \strpos($src, 'function dynamicSolidifyPublishedPage(', $rebakeStart);
        self::assertNotFalse($rebakeEnd);
        $rebakeBody = \substr($src, $rebakeStart, $rebakeEnd - $rebakeStart);
        self::assertStringContainsString('bootstrapMissingChromeScopes(', $rebakeBody);
    }

    public function testLegacyMigrateBindingsCommandRemoved(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Theme/Layout/MigrateBindings.php';
        self::assertFileDoesNotExist($path);
        $convert = \dirname(__DIR__, 3) . '/Console/Theme/Layout/ConvertVersionArtifacts.php';
        self::assertFileExists($convert);
    }

    public function testEnsureCurrentPromotesOrphanScopeVersions(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeScopeVersionService.php'
        );
        $start = \strpos($src, 'function ensureCurrent(');
        self::assertNotFalse($start);
        $end = \strpos($src, 'function getCurrent(', $start);
        self::assertNotFalse($end);
        $body = \substr($src, $start, $end - $start);
        self::assertStringContainsString('loadLatestForScope(', $body);
        self::assertStringContainsString('setIsCurrent(true)', $body);
    }
}
