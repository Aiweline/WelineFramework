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
