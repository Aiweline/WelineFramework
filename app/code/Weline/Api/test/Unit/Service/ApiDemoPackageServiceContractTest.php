<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Api\Service\ApiDemoPackageService;

/**
 * Contract: api-demo 探测 / URL 投影 / realpath 卡死（禁止穿越 source/api-demo）。
 */
final class ApiDemoPackageServiceContractTest extends TestCase
{
    private string $fixtureRoot = '';

    protected function setUp(): void
    {
        $this->fixtureRoot = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-api-demo-' . \uniqid('', true);
        \mkdir($this->fixtureRoot . '/source/api-demo/sample_demo/php', 0777, true);
        \file_put_contents($this->fixtureRoot . '/source/api-demo/sample_demo/php/README.md', "# php demo\n");
        \mkdir($this->fixtureRoot . '/source/api-demo/sample_demo/js', 0777, true);
        \file_put_contents($this->fixtureRoot . '/source/api-demo/sample_demo/js/index.js', "console.log('demo');\n");
        \mkdir($this->fixtureRoot . '/source/api-demo/empty_demo/php', 0777, true);
        \mkdir($this->fixtureRoot . '/outside/secret', 0777, true);
        \file_put_contents($this->fixtureRoot . '/outside/secret/leak.txt', "secret\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
    }

    private function service(): ApiDemoPackageService
    {
        $root = $this->fixtureRoot;

        return new ApiDemoPackageService(static function (string $moduleName) use ($root): ?string {
            return $moduleName === 'Weline_Fixture' ? $root : null;
        });
    }

    public function testResolveDemoIdTrueUsesProvider(): void
    {
        $service = $this->service();
        self::assertSame('sample_demo', $service->resolveDemoId(true, 'sample_demo'));
        self::assertSame('custom_id', $service->resolveDemoId('custom_id', 'sample_demo'));
        self::assertNull($service->resolveDemoId('', 'sample_demo'));
        self::assertNull($service->resolveDemoId(null, 'sample_demo'));
        self::assertNull($service->resolveDemoId('../evil', 'sample_demo'));
        self::assertNull($service->resolveDemoId(true, 'Bad-Id'));
    }

    public function testListAvailableLangsWhenPresent(): void
    {
        $service = $this->service();
        self::assertSame(['php', 'js'], $service->listAvailableLangs('Weline_Fixture', 'sample_demo'));
        self::assertSame([], $service->listAvailableLangs('Weline_Fixture', 'missing_demo'));
        self::assertSame([], $service->listAvailableLangs('Weline_Fixture', 'empty_demo'));
        self::assertSame([], $service->listAvailableLangs('Weline_Missing', 'sample_demo'));
    }

    public function testBuildDownloadUrlsProjectsOnlyExistingLangs(): void
    {
        $service = $this->service();
        $urls = $service->buildDownloadUrls('Weline_Fixture', 'sample_demo');
        self::assertCount(2, $urls);
        self::assertSame('php', $urls[0]['lang']);
        self::assertSame(
            '/api/api-demo/download?module=Weline_Fixture&demo=sample_demo&lang=php',
            $urls[0]['url']
        );
        self::assertSame('js', $urls[1]['lang']);
        self::assertStringContainsString('lang=js', $urls[1]['url']);
        self::assertSame([], $service->buildDownloadUrls('Weline_Fixture', 'missing_demo'));
    }

    public function testAssertSafeDemoDirRejectsTraversalAndOutsideSymlink(): void
    {
        $service = $this->service();
        $safe = $service->assertSafeDemoDir('Weline_Fixture', 'sample_demo', 'php');
        self::assertDirectoryExists($safe);
        self::assertStringContainsString('source' . DIRECTORY_SEPARATOR . 'api-demo', $safe);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertSafeDemoDir('Weline_Fixture', 'sample_demo', 'go');
    }

    public function testAssertSafeDemoDirRejectsSymlinkEscape(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('symlink unavailable');
        }

        $escapeDemo = $this->fixtureRoot . '/source/api-demo/escape_demo';
        $target = $this->fixtureRoot . '/outside/secret';
        \mkdir($escapeDemo, 0777, true);
        if (!@\symlink($target, $escapeDemo . '/php')) {
            self::markTestSkipped('cannot create symlink fixture');
        }

        $service = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $service->assertSafeDemoDir('Weline_Fixture', 'escape_demo', 'php');
    }

    public function testZipDemoContainsLangTreeOnly(): void
    {
        $service = $this->service();
        $binary = $service->zipDemo('Weline_Fixture', 'sample_demo', 'php');
        self::assertNotSame('', $binary);

        $tmp = \tempnam(\sys_get_temp_dir(), 'weline-api-demo-zip-');
        self::assertNotFalse($tmp);
        \file_put_contents($tmp, $binary);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $zip->close();
        @\unlink($tmp);

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertStringStartsWith('sample_demo-php/', $name);
            self::assertStringNotContainsString('..', $name);
            self::assertStringNotContainsString('secret', $name);
        }
    }

    public function testApiDocServiceProjectsDemosWhenDescriptorPresent(): void
    {
        $doc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/ApiDocService.php');
        self::assertStringContainsString('apiDemoPackageService', $doc);
        self::assertStringContainsString("buildDownloadUrls", $doc);
        self::assertStringContainsString("example['demos']", $doc);
        self::assertStringContainsString("demo_auth_hint", $doc);
        self::assertStringContainsString('WELINE_REMOTE_TYPE', $doc);
        self::assertStringContainsString("resolveDemoId", $doc);
    }

    public function testFrontendScriptsRenderExampleDemos(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $welineRoot = \dirname($moduleRoot);
        $themeJs = $welineRoot . '/Theme/view/statics/ui/pages/weline-developer-api.js';
        $dwJs = $welineRoot . '/DeveloperWorkspace/view/statics/js/api-docs.js';
        foreach ([$themeJs, $dwJs] as $path) {
            self::assertFileExists($path, $path);
            $src = (string)\file_get_contents($path);
            self::assertStringContainsString('function hasDemos(api)', $src, $path);
            self::assertStringContainsString('!hasDemos(api)', $src, $path);
            self::assertStringContainsString('example.demos', $src, $path);
            self::assertStringContainsString('demoDownload', $src, $path);
            self::assertStringContainsString('demo_auth_hint', $src, $path);
            self::assertStringNotContainsString('demo-download', $src, $path);
        }
    }

    public function testApiDemoIsFrontendRestWithStripAlias(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $controller = $moduleRoot . '/Api/ApiDemo.php';
        self::assertFileExists($controller);
        $src = (string)\file_get_contents($controller);
        self::assertStringContainsString('namespace Weline\\Api\\Api;', $src);
        self::assertStringContainsString('extends FrontendRestController', $src);
        self::assertStringContainsString('function getDownload()', $src);
        self::assertFileDoesNotExist($moduleRoot . '/Controller/ApiDemo.php');

        $helper = \dirname($moduleRoot) . '/Framework/Module/Helper/Data.php';
        $helperSrc = (string)\file_get_contents($helper);
        self::assertStringContainsString('collectFrontendApiDemoRouteAlias', $helperSrc);
        self::assertStringContainsString("str_starts_with(\$route, 'api/api-demo')", $helperSrc);
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isLink() || $item->isFile()) {
                @\unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @\rmdir($item->getPathname());
            }
        }
        @\rmdir($path);
    }
}
