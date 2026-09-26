<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBindingStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;

/**
 * Binding behavior under Paths v3: config-only keeps structure; versions isolate paths.
 */
final class ThemeLayoutEntityBindingBehaviorTest extends TestCase
{
    private string $root = '';
    private ThemeLayoutEntityPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = \sys_get_temp_dir() . '/weline-binding-behavior-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0777, true);
        $this->paths = new ThemeLayoutEntityPaths($this->root);
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

    private function identity(int $versionId, string $mode = ThemeVersionIdentity::MODE_FORMAL, int $revision = 1): ThemeVersionIdentity
    {
        return new ThemeVersionIdentity(901, 'test.default.default', 'normal', 'frontend', $versionId, $mode, $revision);
    }

    public function testConfigurationSwitchKeepsOldBindingReadableAndDoesNotRewriteTemplate(): void
    {
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $identity = $this->identity(5, ThemeVersionIdentity::MODE_DRAFT, 1);
        $layoutHash = \hash('sha256', 'binding-behavior-page');
        $structure = \hash('sha256', 'struct-cfg-switch');
        $template = $this->paths->pagePhtml($identity, $layoutHash, $structure);
        $structureJson = $this->paths->pageStructureJson($identity, $layoutHash, $structure);
        \mkdir(\dirname($template), 0775, true);
        \file_put_contents($template, 'same layout');
        \file_put_contents($structureJson, "{}\n");
        \touch($template, 1234567890);

        $old = $store->publishPageBinding($identity, $layoutHash, $structure, ['uid' => ['config' => ['title' => 'old']]], []);
        $new = $store->publishPageBinding($identity, $layoutHash, $structure, ['uid' => ['config' => ['title' => 'new']]], []);

        self::assertSame('old', \json_decode((string)\file_get_contents($old->configPath), true)['uid']['config']['title']);
        $current = $store->readPageBinding($identity, $layoutHash);
        self::assertNotNull($current);
        self::assertSame('new', \json_decode((string)\file_get_contents($current->configPath), true)['uid']['config']['title']);
        self::assertSame($old->templatePath, $new->templatePath);
        self::assertNotSame($old->configPath, $new->configPath);
        self::assertNotSame($old->cacheKey(), $new->cacheKey());
        \clearstatcache(true, $template);
        self::assertSame(1234567890, \filemtime($template));

        $otherIdentity = $this->identity(5, ThemeVersionIdentity::MODE_DRAFT, 99);
        self::assertNull($store->readPageBinding($otherIdentity, $layoutHash));
    }

    public function testChromeVersionsKeepConfigSnapshotsAndIsolatePathsAcrossTv(): void
    {
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $structure = \hash('sha256', 'chrome-struct-shared');
        $v1 = $this->identity(1);
        $v2 = $this->identity(2);

        foreach ([$v1, $v2] as $identity) {
            $dir = $this->paths->chromeStructureDir($identity, $structure);
            \mkdir($dir, 0775, true);
            \file_put_contents($dir . 'chrome.phtml', 'chrome-bytes');
            \file_put_contents($dir . 'structure.json', "{}\n");
        }

        $old = $store->publishChromeBinding($v1, $structure, ['title' => 'old'], []);
        $new = $store->publishChromeBinding($v2, $structure, ['title' => 'new'], []);

        // Bytes may match; paths must each belong to their theme version.
        self::assertSame('chrome-bytes', (string)\file_get_contents($old->templatePath));
        self::assertSame('chrome-bytes', (string)\file_get_contents($new->templatePath));
        self::assertNotSame($old->templatePath, $new->templatePath);
        self::assertStringContainsString('/tv1/', $old->templatePath);
        self::assertStringContainsString('/tv2/', $new->templatePath);

        self::assertSame('old', \json_decode((string)\file_get_contents($store->readChromeBinding($v1)->configPath), true)['title']);
        self::assertSame('new', \json_decode((string)\file_get_contents($store->readChromeBinding($v2)->configPath), true)['title']);

        // Same tv: config-only publish keeps structure path.
        $sameTvNext = $store->publishChromeBinding($v1, $structure, ['title' => 'old-2'], []);
        self::assertSame($old->templatePath, $sameTvNext->templatePath);
        self::assertNotSame($old->configPath, $sameTvNext->configPath);
    }

    public function testMissingStructureCannotReplaceCompleteBinding(): void
    {
        $store = new ThemeLayoutEntityBindingStore($this->paths);
        $identity = $this->identity(3);
        $layoutHash = \hash('sha256', 'missing-structure');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_layout_binding_structure_missing');
        $store->publishPageBinding($identity, $layoutHash, \hash('sha256', 'absent'), [], []);
    }
}
