<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

/**
 * setup:upgrade purge of var/runtime/theme-layout-entities (disk bake only).
 */
final class ThemeLayoutEntityUpgradePurgeContractTest extends TestCase
{
    public function testPathsExposePurgeAllEntitiesWithVarRuntimeSafety(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function purgeAllEntities', $src);
        self::assertStringContainsString('function purgeEntityTree', $src);
        self::assertStringContainsString('function assertPurgeableEntityRoot', $src);
        self::assertStringContainsString('theme_layout_entity_purge_outside_var', $src);
        self::assertStringContainsString('theme_layout_entity_purge_basename_mismatch', $src);
        self::assertStringContainsString('theme_layout_entity_purge_symlink_root_forbidden', $src);
        self::assertStringContainsString('ROOT_SEGMENT', $src);
    }

    public function testObserverRegisteredAfterWidgetAndThemeStaticPublish(): void
    {
        $eventXml = \dirname(__DIR__, 3) . '/etc/event.xml';
        self::assertFileExists($eventXml);
        $xml = (string)\file_get_contents($eventXml);

        self::assertStringContainsString('Weline_Framework_Setup::upgrade_after', $xml);
        self::assertStringContainsString(
            'Weline\\Theme\\Observer\\SetupUpgradeAfterPurgeLayoutEntities',
            $xml
        );
        self::assertStringContainsString('setup_upgrade_purge_layout_entities', $xml);
        self::assertMatchesRegularExpression(
            '/setup_upgrade_purge_layout_entities[\s\S]*?sort="300"/',
            $xml
        );

        $observer = \dirname(__DIR__, 3) . '/Observer/SetupUpgradeAfterPurgeLayoutEntities.php';
        self::assertFileExists($observer);
        $src = (string)\file_get_contents($observer);
        self::assertStringContainsString('purgeAllEntities', $src);
        self::assertStringContainsString("clearAllThemeRelatedCaches(", $src);
        self::assertStringContainsString("'setup_upgrade_layout_entities_invalidated'", $src);
        self::assertStringNotContainsString('->rebakeAfterInjectionCollect', $src);
        self::assertStringNotContainsString('rebakeAfterInjectionCollect(', $src);
        self::assertStringContainsString('dynamicSolidify', $src);
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
        self::assertNotFalse(\file_put_contents($page . '/shell.phtml', 'old-shell'));
        self::assertNotFalse(\file_put_contents($page . '/structure.json', '{"x":1}'));

        $neighbor = $varTmp . \DIRECTORY_SEPARATOR . 'keep-neighbor.txt';
        self::assertNotFalse(\file_put_contents($neighbor, 'keep'));

        $paths = new ThemeLayoutEntityPaths();
        $deleted = $paths->purgeEntityTree($root);
        self::assertGreaterThan(0, $deleted);
        self::assertDirectoryDoesNotExist($root);
        self::assertFileExists($neighbor);
        self::assertSame(0, $paths->purgeEntityTree($root));

        @\unlink($neighbor);
    }

    public function testAssertPurgeableEntityRootRejectsBpAndVarRuntimeParents(): void
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
