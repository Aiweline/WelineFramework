<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\EntityRenderBinding;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

/**
 * Task 2 isolation: same V/R for page+chrome; config-only keeps PHTML; non-inherit path isolation.
 */
final class ThemeVersionArtifactIsolationTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = \sys_get_temp_dir() . '/weline-theme-artifact-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && \is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
            }
            @\rmdir($this->root);
        }
        parent::tearDown();
    }

    private function paths(): ThemeLayoutEntityPaths
    {
        return new ThemeLayoutEntityPaths($this->root);
    }

    public function testPageAndChromeShareSameVersionRevisionPaths(): void
    {
        $paths = $this->paths();
        $identity = new ThemeVersionIdentity(7, 'shop.default.default', 'normal', 'frontend', 5, 'formal', 3);
        $layoutHash = \hash('sha256', 'layout:home');
        $structureKey = \hash('sha256', 'struct:home');

        $pageBinding = $paths->pageBindingJson($identity, $layoutHash);
        $chromeBinding = $paths->chromeBindingJson($identity);
        self::assertStringContainsString('/tv5/formal/', $pageBinding);
        self::assertStringContainsString('/tv5/formal/', $chromeBinding);
        self::assertStringContainsString('/v5-g3/', $pageBinding);
        self::assertStringContainsString('/v5-g3/', $chromeBinding);
        self::assertStringContainsString('/pages/' . $layoutHash . '/', $pageBinding);
        self::assertStringContainsString('/chrome/bindings/', $chromeBinding);

        $pageStruct = $paths->pagePhtml($identity, $layoutHash, $structureKey);
        $chromeStruct = $paths->chromePhtml($identity, $structureKey);
        self::assertStringContainsString('/structures/v5/' . $structureKey . '/', $pageStruct);
        self::assertStringContainsString('/structures/v5/' . $structureKey . '/', $chromeStruct);
    }

    public function testNonInheritSameStructureHashUsesDistinctVersionPaths(): void
    {
        $paths = $this->paths();
        $structureKey = \hash('sha256', 'same-struct');
        $layoutHash = \hash('sha256', 'same-layout');
        $v10 = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'formal', 1);
        $v11 = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 11, 'formal', 1);
        $path10 = $paths->pagePhtml($v10, $layoutHash, $structureKey);
        $path11 = $paths->pagePhtml($v11, $layoutHash, $structureKey);
        self::assertNotSame($path10, $path11);
        self::assertStringContainsString('/tv10/', $path10);
        self::assertStringContainsString('/tv11/', $path11);
    }

    public function testBindingStoreRejectsV2SchemaAndRequiresMatchingIdentity(): void
    {
        $paths = $this->paths();
        $store = new ThemeLayoutEntityBindingStore($paths);
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 9, 'draft', 1);
        $layoutHash = \hash('sha256', 'page');
        $structureKey = \hash('sha256', 's1');
        $phtml = $paths->pagePhtml($identity, $layoutHash, $structureKey);
        $structureJson = $paths->pageStructureJson($identity, $layoutHash, $structureKey);
        \mkdir(\dirname($phtml), 0777, true);
        \file_put_contents($phtml, "<!-- layout -->\n");
        \file_put_contents($structureJson, "{}\n");

        $binding = $store->publishPageBinding($identity, $layoutHash, $structureKey, ['a' => 1], []);
        self::assertInstanceOf(EntityRenderBinding::class, $binding);
        self::assertSame($identity->themeVersionId, $binding->identity->themeVersionId);
        self::assertSame(3, $binding->toManifestArray()['schema_version']);

        $manifest = $paths->pageBindingJson($identity, $layoutHash);
        \file_put_contents($manifest, \json_encode([
            'schema_version' => 2,
            'structure_key' => 's' . $structureKey,
            'config_key' => \hash('sha256', 'x'),
        ], \JSON_THROW_ON_ERROR));
        self::assertNull($store->readPageBinding($identity, $layoutHash));
    }

    public function testConfigOnlyPublishDoesNotRewriteExistingStructurePhtml(): void
    {
        $paths = $this->paths();
        $store = new ThemeLayoutEntityBindingStore($paths);
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 4, 'formal', 2);
        $layoutHash = \hash('sha256', 'cfg-only');
        $structureKey = \hash('sha256', 'struct-cfg');
        $phtml = $paths->pagePhtml($identity, $layoutHash, $structureKey);
        $structureJson = $paths->pageStructureJson($identity, $layoutHash, $structureKey);
        \mkdir(\dirname($phtml), 0777, true);
        \file_put_contents($phtml, "<!-- stable structure -->\n");
        \file_put_contents($structureJson, "{}\n");
        \clearstatcache(true, $phtml);
        $mtimeBefore = \filemtime($phtml);
        self::assertNotFalse($mtimeBefore);

        $store->publishPageBinding($identity, $layoutHash, $structureKey, ['color' => 'red'], ['css' => []]);
        \usleep(20000);
        $store->publishPageBinding($identity, $layoutHash, $structureKey, ['color' => 'blue'], ['css' => ['a.css']]);
        \clearstatcache(true, $phtml);
        $mtimeAfter = \filemtime($phtml);
        self::assertSame($mtimeBefore, $mtimeAfter);
        self::assertSame("<!-- stable structure -->\n", (string)\file_get_contents($phtml));
    }
}
