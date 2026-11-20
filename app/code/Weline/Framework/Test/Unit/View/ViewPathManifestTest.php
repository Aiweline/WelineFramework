<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\ViewPathManifest;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}
require_once BP . 'app/autoload.php';

final class ViewPathManifestTest extends TestCase
{
    private string $dir;
    private string $targetFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/weline_view_manifest_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0770, true);
        $this->targetFile = $this->dir . '/resolved_target.phtml';
        file_put_contents($this->targetFile, '<p>target</p>');

        ViewPathManifest::resetForTest();
        ViewPathManifest::setDirectoryForTest($this->dir);
        ViewPathManifest::setEnabledOverrideForTest(true);
        ViewPathManifest::setGenerationOverrideForTest('gen-test-1');
    }

    protected function tearDown(): void
    {
        ViewPathManifest::resetForTest();
        ViewPathManifest::setEnabledOverrideForTest(null);
        ViewPathManifest::setDirectoryForTest(null);
        ViewPathManifest::setGenerationOverrideForTest(null);
        $this->removeTree($this->dir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink($dir . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($dir);
    }

    private const SCOPE = 'area:frontend|theme_id:1|theme_path:/x/theme';
    private const ENV = 'env-a';

    public function testRecordThenResolveHits(): void
    {
        $filename = '/src/module/view/tpl/default.phtml';
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));

        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, self::ENV);
        $this->assertSame($this->targetFile, ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }

    public function testResolveReturnsNullWhenTargetDeleted(): void
    {
        $filename = '/src/module/view/tpl/gone.phtml';
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, self::ENV);
        $this->assertSame($this->targetFile, ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));

        unlink($this->targetFile);
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }

    public function testEnvMismatchInvalidatesBag(): void
    {
        $filename = '/src/module/view/tpl/default.phtml';
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, 'env-a');
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, 'env-b'));

        // 新 env 下可重建：record 会覆盖同文件（不产孤儿袋）
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, 'env-b');
        $this->assertSame($this->targetFile, ViewPathManifest::resolve($filename, self::SCOPE, 'env-b'));
        $this->assertCount(1, glob($this->dir . '/scope-*.php') ?: []);
    }

    public function testGenerationMismatchInvalidatesBag(): void
    {
        $filename = '/src/module/view/tpl/default.phtml';
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, self::ENV);
        $this->assertSame($this->targetFile, ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));

        ViewPathManifest::setGenerationOverrideForTest('gen-test-2');
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }

    public function testPreviewScopeIsNotPersisted(): void
    {
        $filename = '/src/module/view/tpl/preview.phtml';
        $previewScope = self::SCOPE . '|preview_token:abcdef0123456789';
        ViewPathManifest::record($filename, $this->targetFile, $previewScope, self::ENV);
        $this->assertNull(ViewPathManifest::resolve($filename, $previewScope, self::ENV));

        $requestThemeScope = self::SCOPE . '|request_theme:frontend:9';
        ViewPathManifest::record($filename, $this->targetFile, $requestThemeScope, self::ENV);
        $this->assertNull(ViewPathManifest::resolve($filename, $requestThemeScope, self::ENV));
    }

    public function testNonFileResolutionIsNotRecorded(): void
    {
        $filename = '/src/module/view/tpl/missing.phtml';
        ViewPathManifest::record($filename, $this->dir . '/does-not-exist.phtml', self::SCOPE, self::ENV);
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }

    public function testDisabledManifestNeverResolves(): void
    {
        ViewPathManifest::setEnabledOverrideForTest(false);
        $filename = '/src/module/view/tpl/default.phtml';
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, self::ENV);
        $this->assertNull(ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }

    public function testPersistedFileSurvivesAcrossBagReload(): void
    {
        $filename = '/src/module/view/tpl/default.phtml';
        ViewPathManifest::record($filename, $this->targetFile, self::SCOPE, self::ENV);
        $this->assertNotEmpty(glob($this->dir . '/scope-*.php'));

        // 模拟另一个 Worker 冷启动：清进程内 bag，重读文件
        ViewPathManifest::resetForTest();
        ViewPathManifest::setGenerationOverrideForTest('gen-test-1');
        $this->assertSame($this->targetFile, ViewPathManifest::resolve($filename, self::SCOPE, self::ENV));
    }
}
