<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Console\Console\Deploy\Upgrade;

/**
 * UT：deploy:upgrade 整树扁平铺平契约（contracts UT-1/2/5 可测封装）。
 * 使用临时目录与真实文件落盘，禁止假 JSON 冒充 HTTP。
 */
final class ModuleFlatStaticsPublishTest extends TestCase
{
    private string $workspace = '';

    private Upgrade $upgrade;

    private ReflectionMethod $publishModuleFlatStatics;

    private ReflectionMethod $resolveFlatStaticModuleRoot;

    private ReflectionMethod $recursiveCopy;

    protected function setUp(): void
    {
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }

        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-flat-static-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->workspace, 0775, true));

        $this->upgrade = (new ReflectionClass(Upgrade::class))->newInstanceWithoutConstructor();
        $this->publishModuleFlatStatics = new ReflectionMethod(Upgrade::class, 'publishModuleFlatStatics');
        $this->resolveFlatStaticModuleRoot = new ReflectionMethod(Upgrade::class, 'resolveFlatStaticModuleRoot');
        $this->recursiveCopy = new ReflectionMethod(Upgrade::class, 'recursiveCopy');
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            $this->removeTree($this->workspace);
        }
    }

    public function testPublishModuleFlatStaticsCopiesEntireTreeRelativeToStaticsRoot(): void
    {
        $moduleBase = $this->workspace . DIRECTORY_SEPARATOR . 'module';
        $statics = $moduleBase . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'statics';
        $nested = $statics . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'nested';
        self::assertTrue(mkdir($nested, 0775, true));
        self::assertTrue(mkdir($statics . DIRECTORY_SEPARATOR . 'css', 0775, true));
        self::assertNotFalse(file_put_contents($statics . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'foo.js', 'console.log(1);'));
        self::assertNotFalse(file_put_contents($nested . DIRECTORY_SEPARATOR . 'bar.js', 'console.log(2);'));
        self::assertNotFalse(file_put_contents($statics . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'a.css', '/* a */'));

        $staticRoot = $this->workspace . DIRECTORY_SEPARATOR . 'pub-static';
        self::assertTrue(mkdir($staticRoot, 0775, true));

        $this->publishModuleFlatStatics->invoke($this->upgrade, 'Weline_FixtureDemo', $statics, $staticRoot);

        $flatJs = $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'FixtureDemo'
            . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'foo.js';
        $flatNested = $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'FixtureDemo'
            . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'bar.js';
        $flatCss = $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'FixtureDemo'
            . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'a.css';

        self::assertFileExists($flatJs);
        self::assertSame('console.log(1);', (string)file_get_contents($flatJs));
        self::assertFileExists($flatNested);
        self::assertSame('console.log(2);', (string)file_get_contents($flatNested));
        self::assertFileExists($flatCss);
        self::assertStringNotContainsString('view' . DIRECTORY_SEPARATOR . 'statics', $flatJs);
    }

    public function testNoStaticsDirectoryDoesNotCreateFlatModuleRoot(): void
    {
        $missing = $this->workspace . DIRECTORY_SEPARATOR . 'no-statics';
        $staticRoot = $this->workspace . DIRECTORY_SEPARATOR . 'pub-static-empty';
        self::assertTrue(mkdir($staticRoot, 0775, true));

        $this->publishModuleFlatStatics->invoke($this->upgrade, 'Weline_NoStatics', $missing, $staticRoot);

        self::assertDirectoryDoesNotExist(
            $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'NoStatics'
        );
        self::assertSame(['.', '..'], scandir($staticRoot));
    }

    public function testInvalidModuleNameDoesNotPolluteStaticRoot(): void
    {
        $statics = $this->workspace . DIRECTORY_SEPARATOR . 'bad-module' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'statics';
        self::assertTrue(mkdir($statics, 0775, true));
        self::assertNotFalse(file_put_contents($statics . DIRECTORY_SEPARATOR . 'x.js', 'x'));

        $staticRoot = $this->workspace . DIRECTORY_SEPARATOR . 'pub-static-bad';
        self::assertTrue(mkdir($staticRoot, 0775, true));

        $this->publishModuleFlatStatics->invoke($this->upgrade, 'InvalidName', $statics, $staticRoot);
        $this->publishModuleFlatStatics->invoke($this->upgrade, '_Trailing', $statics, $staticRoot);
        $this->publishModuleFlatStatics->invoke($this->upgrade, 'Vendor_', $statics, $staticRoot);

        self::assertNull($this->resolveFlatStaticModuleRoot->invoke($this->upgrade, 'InvalidName', $staticRoot));
        self::assertSame(['.', '..'], scandir($staticRoot));
    }

    public function testOverlayRecursiveCopyStillLandsUnderThemePath(): void
    {
        $statics = $this->workspace . DIRECTORY_SEPARATOR . 'overlay-src' . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'statics';
        self::assertTrue(mkdir($statics . DIRECTORY_SEPARATOR . 'js', 0775, true));
        self::assertNotFalse(file_put_contents($statics . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'keep.js', 'keep'));

        $staticRoot = $this->workspace . DIRECTORY_SEPARATOR . 'pub-static-overlay';
        $overlayTarget = $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'hanfu'
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'FixtureDemo'
            . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'statics';
        self::assertTrue(mkdir($overlayTarget, 0775, true));

        $this->recursiveCopy->invoke($this->upgrade, $statics, $overlayTarget);
        $this->publishModuleFlatStatics->invoke($this->upgrade, 'Weline_FixtureDemo', $statics, $staticRoot);

        $overlayFile = $overlayTarget . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'keep.js';
        $flatFile = $staticRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'FixtureDemo'
            . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'keep.js';

        self::assertFileExists($overlayFile);
        self::assertFileExists($flatFile);
        self::assertSame('keep', (string)file_get_contents($overlayFile));
        self::assertSame('keep', (string)file_get_contents($flatFile));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
