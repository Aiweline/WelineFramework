<?php

declare(strict_types=1);

namespace Weline\Api\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Api\Service\ApiDemoPackageService;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllApiServiceContractsPass().
 */
final class ApiServiceContractsTest extends TestCase
{
    public function testAllApiServiceContractsPass(): void
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
            'ApiDemoPackageService::testResolveDemoIdTrueUsesProvider' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testResolveDemoIdTrueUsesProvider();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testListAvailableLangsWhenPresent' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testListAvailableLangsWhenPresent();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testBuildDownloadUrlsProjectsOnlyExistingLangs' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testBuildDownloadUrlsProjectsOnlyExistingLangs();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testAssertSafeDemoDirRejectsTraversalAndOutsideSymlink' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testAssertSafeDemoDirRejectsTraversalAndOutsideSymlink();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testAssertSafeDemoDirRejectsSymlinkEscape' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testAssertSafeDemoDirRejectsSymlinkEscape();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testZipDemoContainsLangTreeOnly' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testZipDemoContainsLangTreeOnly();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testApiDocServiceProjectsDemosWhenDescriptorPresent' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testApiDocServiceProjectsDemosWhenDescriptorPresent();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testFrontendScriptsRenderExampleDemos' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testFrontendScriptsRenderExampleDemos();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
            'ApiDemoPackageService::testApiDemoIsFrontendRestWithStripAlias' => function () {
            $this->setUp_ApiDemoPackageService();
            try {
                $this->assert_ApiDemoPackageService_testApiDemoIsFrontendRestWithStripAlias();
            } finally {
                $this->tearDown_ApiDemoPackageService();
            }
        },
        ];
    }

private string $fixtureRoot_ApiDemoPackageService = '';

private function setUp_ApiDemoPackageService(): void
{
    $this->fixtureRoot_ApiDemoPackageService = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-api-demo-' . \uniqid('', true);
    \mkdir($this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/sample_demo/php', 0777, true);
    \file_put_contents($this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/sample_demo/php/README.md', "# php demo\n");
    \mkdir($this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/sample_demo/js', 0777, true);
    \file_put_contents($this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/sample_demo/js/index.js', "console.log('demo');\n");
    \mkdir($this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/empty_demo/php', 0777, true);
    \mkdir($this->fixtureRoot_ApiDemoPackageService . '/outside/secret', 0777, true);
    \file_put_contents($this->fixtureRoot_ApiDemoPackageService . '/outside/secret/leak.txt', "secret\n");
}

private function tearDown_ApiDemoPackageService(): void
{
    $this->removeTree_ApiDemoPackageService($this->fixtureRoot_ApiDemoPackageService);
}

private function service_ApiDemoPackageService(): ApiDemoPackageService
{
    $root = $this->fixtureRoot_ApiDemoPackageService;
    return new ApiDemoPackageService(static function (string $moduleName) use ($root): ?string {
        return $moduleName === 'Weline_Fixture' ? $root : null;
    });
}

private function assert_ApiDemoPackageService_testResolveDemoIdTrueUsesProvider(): void
{
    $service = $this->service_ApiDemoPackageService();
    self::assertSame('sample_demo', $service->resolveDemoId(true, 'sample_demo'));
    self::assertSame('custom_id', $service->resolveDemoId('custom_id', 'sample_demo'));
    self::assertNull($service->resolveDemoId('', 'sample_demo'));
    self::assertNull($service->resolveDemoId(null, 'sample_demo'));
    self::assertNull($service->resolveDemoId('../evil', 'sample_demo'));
    self::assertNull($service->resolveDemoId(true, 'Bad-Id'));
}

private function assert_ApiDemoPackageService_testListAvailableLangsWhenPresent(): void
{
    $service = $this->service_ApiDemoPackageService();
    self::assertSame(['php', 'js'], $service->listAvailableLangs('Weline_Fixture', 'sample_demo'));
    self::assertSame([], $service->listAvailableLangs('Weline_Fixture', 'missing_demo'));
    self::assertSame([], $service->listAvailableLangs('Weline_Fixture', 'empty_demo'));
    self::assertSame([], $service->listAvailableLangs('Weline_Missing', 'sample_demo'));
}

private function assert_ApiDemoPackageService_testBuildDownloadUrlsProjectsOnlyExistingLangs(): void
{
    $service = $this->service_ApiDemoPackageService();
    $urls = $service->buildDownloadUrls('Weline_Fixture', 'sample_demo');
    self::assertCount(2, $urls);
    self::assertSame('php', $urls[0]['lang']);
    self::assertSame('/api/api-demo/download?module=Weline_Fixture&demo=sample_demo&lang=php', $urls[0]['url']);
    self::assertSame('js', $urls[1]['lang']);
    self::assertStringContainsString('lang=js', $urls[1]['url']);
    self::assertSame([], $service->buildDownloadUrls('Weline_Fixture', 'missing_demo'));
}

private function assert_ApiDemoPackageService_testAssertSafeDemoDirRejectsTraversalAndOutsideSymlink(): void
{
    $service = $this->service_ApiDemoPackageService();
    $safe = $service->assertSafeDemoDir('Weline_Fixture', 'sample_demo', 'php');
    self::assertDirectoryExists($safe);
    self::assertStringContainsString('source' . DIRECTORY_SEPARATOR . 'api-demo', $safe);
    $this->expectException(\InvalidArgumentException::class);
    $service->assertSafeDemoDir('Weline_Fixture', 'sample_demo', 'go');
}

private function assert_ApiDemoPackageService_testAssertSafeDemoDirRejectsSymlinkEscape(): void
{
    if (!\function_exists('symlink')) {
        self::markTestSkipped('symlink unavailable');
    }
    $escapeDemo = $this->fixtureRoot_ApiDemoPackageService . '/source/api-demo/escape_demo';
    $target = $this->fixtureRoot_ApiDemoPackageService . '/outside/secret';
    \mkdir($escapeDemo, 0777, true);
    if (!@\symlink($target, $escapeDemo . '/php')) {
        self::markTestSkipped('cannot create symlink fixture');
    }
    $service = $this->service_ApiDemoPackageService();
    $this->expectException(\InvalidArgumentException::class);
    $service->assertSafeDemoDir('Weline_Fixture', 'escape_demo', 'php');
}

private function assert_ApiDemoPackageService_testZipDemoContainsLangTreeOnly(): void
{
    $service = $this->service_ApiDemoPackageService();
    $binary = $service->zipDemo('Weline_Fixture', 'sample_demo', 'php');
    self::assertNotSame('', $binary);
    $tmp = \tempnam(\sys_get_temp_dir(), 'weline-api-demo-zip-');
    self::assertNotFalse($tmp);
    \file_put_contents($tmp, $binary);
    $zip = new \ZipArchive();
    self::assertTrue($zip->open($tmp));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
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

private function assert_ApiDemoPackageService_testApiDocServiceProjectsDemosWhenDescriptorPresent(): void
{
    $doc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Api' . '/Service/ApiDocService.php');
    self::assertStringContainsString('apiDemoPackageService', $doc);
    self::assertStringContainsString("buildDownloadUrls", $doc);
    self::assertStringContainsString("example['demos']", $doc);
    self::assertStringContainsString("demo_auth_hint", $doc);
    self::assertStringContainsString('WELINE_REMOTE_TYPE', $doc);
    self::assertStringContainsString("resolveDemoId", $doc);
}

private function assert_ApiDemoPackageService_testFrontendScriptsRenderExampleDemos(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Api';
    $welineRoot = \dirname($moduleRoot);
    $themeJs = $welineRoot . '/Theme/view/statics/ui/pages/weline-developer-api.js';
    $dwJs = $welineRoot . '/DeveloperWorkspace/view/statics/js/api-docs.js';
    foreach ([$themeJs, $dwJs] as $path) {
        self::assertFileExists($path, $path);
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('function hasDemos(api)', $src, $path);
        self::assertStringContainsString('!hasDemos(api)', $src, $path);
        self::assertStringContainsString('example.demos', $src, $path);
        self::assertStringContainsString('demoDownload', $src, $path);
        self::assertStringContainsString('demo_auth_hint', $src, $path);
        self::assertStringNotContainsString('demo-download', $src, $path);
    }
}

private function assert_ApiDemoPackageService_testApiDemoIsFrontendRestWithStripAlias(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Api';
    $controller = $moduleRoot . '/Api/ApiDemo.php';
    self::assertFileExists($controller);
    $src = (string) \file_get_contents($controller);
    self::assertStringContainsString('namespace Weline\Api\Api;', $src);
    self::assertStringContainsString('extends FrontendRestController', $src);
    self::assertStringContainsString('function getDownload()', $src);
    self::assertFileDoesNotExist($moduleRoot . '/Controller/ApiDemo.php');
    $helper = \dirname($moduleRoot) . '/Framework/Module/Helper/Data.php';
    $helperSrc = (string) \file_get_contents($helper);
    self::assertStringContainsString('collectFrontendApiDemoRouteAlias', $helperSrc);
    self::assertStringContainsString("str_starts_with(\$route, 'api/api-demo')", $helperSrc);
}

private function removeTree_ApiDemoPackageService(string $path): void
{
    if ($path === '' || !\is_dir($path)) {
        return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
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
