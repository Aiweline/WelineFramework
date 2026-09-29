<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

/**
 * §0 R5/R6: upgrade solidifies layout templates under generated/ (not purge-only).
 */
final class ThemeLayoutEntityUpgradePurgeContractTest extends TestCase
{
    public function testPathsExposeGeneratedRootAndLegacyMigrate(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function purgeAllEntities', $src);
        self::assertStringContainsString('function purgeEntityTree', $src);
        self::assertStringContainsString('function assertPurgeableEntityRoot', $src);
        self::assertStringContainsString('function legacyVarRoot', $src);
        self::assertStringContainsString('function migrateLegacyVarTreeToGenerated', $src);
        self::assertStringContainsString('GENERATED_DIR', $src);
        self::assertStringContainsString('theme_layout_entity_purge_outside_allowed_root', $src);
        self::assertStringContainsString('theme_layout_entity_purge_basename_mismatch', $src);
        self::assertStringContainsString('ROOT_SEGMENT', $src);
    }

    public function testDefaultRootIsUnderGenerated(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP undefined');
        }
        $paths = new ThemeLayoutEntityPaths();
        $root = \str_replace('\\', '/', $paths->root());
        self::assertStringContainsString('/generated/theme-layout-entities/', $root);
        self::assertStringNotContainsString('/var/runtime/theme-layout-entities/', $root);
    }

    public function testUpgradeEventsRegisterSolidificationObserver(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertNotFalse($xml);
        foreach (['Weline_Framework_Setup::upgrade_after', 'Weline_Framework_Deploy::upgrade_after', 'Weline_Deploy::core_update_after'] as $event) {
            $observers = $xml->xpath('//*[local-name()="event" and @name="' . $event . '"]/*[local-name()="observer"]');
            $classes = array_map(static fn($observer): string => (string)$observer['instance'], $observers);
            self::assertContains('Weline\\Theme\\Observer\\SetupUpgradeAfterPurgeLayoutEntities', $classes, $event);
        }
    }

    public function testThemeUpgradeCommandWiresLayoutSolidify(): void
    {
        $upgrade = \dirname(__DIR__, 3) . '/Console/Theme/Upgrade.php';
        self::assertFileExists($upgrade);
        $src = (string)\file_get_contents($upgrade);
        self::assertStringContainsString('ThemeLayoutEntityUpgradeSolidifyService', $src);
        self::assertStringContainsString('cutoverFromThemeCommand', $src);
        self::assertStringContainsString('purge 旧布局固化物', $src);
    }

    public function testDeployUpgradeAndCoreUpdateDispatchLifecyclePurgeEvents(): void
    {
        $welineRoot = \dirname(__DIR__, 4);
        $deployUpgrade = $welineRoot . '/Framework/Console/Console/Deploy/Upgrade.php';
        self::assertFileExists($deployUpgrade);
        $deploySrc = (string)\file_get_contents($deployUpgrade);
        self::assertStringContainsString("dispatch('Weline_Framework_Deploy::upgrade_after'", $deploySrc);

        $coreUpdate = $welineRoot . '/Deploy/Console/Update/Core.php';
        self::assertFileExists($coreUpdate);
        $coreSrc = (string)\file_get_contents($coreUpdate);
        self::assertStringContainsString("dispatch('Weline_Deploy::core_update_after'", $coreSrc);
    }

    public function testOfflineGcHooksReuseThemeRuntimeCacheCleaner(): void
    {
        $cleaner = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeRuntimeCacheCleaner.php'
        );
        self::assertStringContainsString('function sweepOrphanLayoutEntityArtifacts', $cleaner);
        self::assertStringContainsString('listVersionModeDirectories', $cleaner);

        $paths = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );
        self::assertStringContainsString('function listVersionModeDirectories', $paths);
        self::assertStringContainsString('function purgeVersionModeDirectory', $paths);
    }

    public function testListAndPurgeVersionModeDirectoryLeavesNeighborIntact(): void
    {
        $root = sys_get_temp_dir() . '/theme-purge-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
        $paths = new ThemeLayoutEntityPaths($root);
        $formal = new \Weline\Theme\Api\Version\ThemeVersionIdentity(7, 'shop.cn.app', 'test', 'frontend', 10, 'formal', 2);
        $draft = $formal->withVersion(10, 'draft', 2);
        $keep = $paths->pageLayoutPhtml($formal, 'homepage');
        $drop = $paths->pageLayoutPhtml($draft, 'homepage');
        try {
            mkdir(dirname($keep), 0770, true); mkdir(dirname($drop), 0770, true);
            file_put_contents($keep, 'FORMAL'); file_put_contents($drop, 'DRAFT');
            self::assertCount(2, $paths->listVersionModeDirectories());
            self::assertGreaterThan(0, $paths->purgeVersionModeDirectory($paths->versionModeDir($draft)));
            self::assertFileDoesNotExist($drop);
            self::assertSame('FORMAL', file_get_contents($keep));
        } finally {
            $paths->purgeAllEntities(); @rmdir(dirname($root));
        }
    }

    public function testPurgeEntityTreeRemovesSiblingThemeLayoutEntitiesUnderVar(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP undefined');
        }

        $varTmp = \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'tmp';
        if (!\is_dir($varTmp) && !@\mkdir($varTmp, 0775, true) && !\is_dir($varTmp)) {
            self::markTestSkipped('cannot create var/tmp');
        }

        $root = $varTmp . \DIRECTORY_SEPARATOR . ThemeLayoutEntityPaths::ROOT_SEGMENT;
        if (\is_dir($root)) {
            $this->deleteDir($root);
        }
        $page = $root . '/2/default.__store__/pages/abc/s1';
        self::assertTrue(@\mkdir($page, 0775, true) || \is_dir($page));
        self::assertNotFalse(\file_put_contents($page . '/layout.phtml', 'R43-STORE-PREVIEW'));

        $neighbor = $varTmp . \DIRECTORY_SEPARATOR . 'keep-neighbor.txt';
        self::assertNotFalse(\file_put_contents($neighbor, 'keep'));

        $paths = new ThemeLayoutEntityPaths();
        $deleted = $paths->purgeEntityTree($root);
        self::assertGreaterThan(0, $deleted);
        self::assertDirectoryDoesNotExist($root);
        self::assertFileExists($neighbor);

        @\unlink($neighbor);
    }

    public function testAssertPurgeableEntityRootRejectsBp(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP undefined');
        }
        $paths = new ThemeLayoutEntityPaths();
        $this->expectException(\RuntimeException::class);
        $paths->assertPurgeableEntityRoot((string)BP);
    }

    private function deleteDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($dir);
    }
}
