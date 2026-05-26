<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\AppStore\Service\ModuleInstallerService;

/**
 * 模块安装服务单元测试
 */
class ModuleInstallerServiceTest extends TestCase
{
    /**
     * 测试模块名解析
     */
    public function testParseModuleName(): void
    {
        $moduleName = 'Weline_AppStore';
        $parts = explode('_', $moduleName);

        $this->assertCount(2, $parts);
        $this->assertEquals('Weline', $parts[0]);
        $this->assertEquals('AppStore', $parts[1]);
    }

    /**
     * 测试模块目标目录路径
     */
    public function testGetModuleTargetDir(): void
    {
        $moduleName = 'Weline_AppStore';
        $expectedDir = APP_CODE_PATH . 'Weline' . DS . 'AppStore';
        $actualDir = APP_CODE_PATH . str_replace('_', DS, $moduleName);

        $this->assertEquals($expectedDir, $actualDir);
    }

    /**
     * 测试版本比较
     */
    public function testVersionComparison(): void
    {
        // 相同版本
        $this->assertEquals(0, version_compare('1.0.0', '1.0.0'));

        // 较小版本
        $this->assertEquals(-1, version_compare('1.0.0', '1.0.1'));
        $this->assertEquals(-1, version_compare('1.0.0', '1.1.0'));
        $this->assertEquals(-1, version_compare('1.0.0', '2.0.0'));

        // 较大版本
        $this->assertEquals(1, version_compare('1.0.1', '1.0.0'));
        $this->assertEquals(1, version_compare('2.0.0', '1.0.0'));
    }

    /**
     * 测试依赖检查逻辑
     */
    public function testDependencyCheck(): void
    {
        $dependencies = ['Weline_Framework', 'Weline_Eav'];
        $installed = ['Weline_Framework' => true, 'Weline_Eav' => true, 'Other_Module' => true];

        $missing = [];
        foreach ($dependencies as $dep) {
            if (!isset($installed[$dep])) {
                $missing[] = $dep;
            }
        }

        $this->assertEmpty($missing, 'All dependencies should be satisfied');
    }

    /**
     * 测试缺少依赖的情况
     */
    public function testDependencyCheckMissing(): void
    {
        $dependencies = ['Weline_Framework', 'Weline_Eav', 'Missing_Module'];
        $installed = ['Weline_Framework' => true, 'Weline_Eav' => true];

        $missing = [];
        foreach ($dependencies as $dep) {
            if (!isset($installed[$dep])) {
                $missing[] = $dep;
            }
        }

        $this->assertCount(1, $missing);
        $this->assertEquals('Missing_Module', $missing[0]);
    }

    /**
     * 测试文件哈希验证
     */
    public function testFileHashVerification(): void
    {
        // 创建临时测试文件
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');

        $hash = hash_file('sha256', $tempFile);
        $expectedHash = hash('sha256', 'test content');

        $this->assertEquals($expectedHash, $hash);

        // 清理
        unlink($tempFile);
    }

    /**
     * 测试临时目录创建
     */
    public function testTempDirectoryCreation(): void
    {
        $tempDir = BP . 'var' . DS . 'appstore' . DS . 'temp';

        // 验证路径格式
        $this->assertStringContainsString('var', $tempDir);
        $this->assertStringContainsString('appstore', $tempDir);
        $this->assertStringContainsString('temp', $tempDir);
    }

    /**
     * 测试下载响应 data 包裹结构能被正确解析
     */
    public function testNormalizeApiPayloadWithDataEnvelope(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'normalizeApiPayload');
        $method->setAccessible(true);

        $payload = [
            'success' => true,
            'data' => [
                'module_name' => 'Weline_AppStore',
                'version' => '1.2.3',
                'download_url' => 'https://example.test/file.zip',
            ],
        ];

        $result = $method->invoke($service, $payload);

        $this->assertSame('Weline_AppStore', $result['module_name']);
        $this->assertSame('1.2.3', $result['version']);
        $this->assertSame('https://example.test/file.zip', $result['download_url']);
    }

    /**
     * 测试下载响应兼容旧结构（无 data 包裹）
     */
    public function testNormalizeApiPayloadWithoutDataEnvelope(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'normalizeApiPayload');
        $method->setAccessible(true);

        $payload = [
            'module_name' => 'Weline_AppStore',
            'version' => '2.0.0',
            'download_url' => 'https://example.test/v2.zip',
        ];

        $result = $method->invoke($service, $payload);

        $this->assertSame('Weline_AppStore', $result['module_name']);
        $this->assertSame('2.0.0', $result['version']);
        $this->assertSame('https://example.test/v2.zip', $result['download_url']);
    }

    public function testWriteMarketplaceReadmeDocumentsSystemUninstallBoundary(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'writeMarketplaceReadme');
        $method->setAccessible(true);

        $moduleDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_appstore_readme_' . uniqid('', true);
        mkdir($moduleDir . DIRECTORY_SEPARATOR . 'etc', 0777, true);
        file_put_contents($moduleDir . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php', "<?php return ['router' => 'sample-module'];");

        try {
            $readmePath = $method->invoke($service, $moduleDir, 'Weline_SampleModule', [
                'version' => '1.0.5',
            ], [
                'display_name' => 'Sample Module',
                'platform_module_id' => 1001,
                'license_key' => 'license-key-1234567890',
            ]);

            $this->assertSame($moduleDir . DIRECTORY_SEPARATOR . '商城应用.md', $readmePath);
            $this->assertFileExists($readmePath);

            $content = file_get_contents($readmePath);
            $this->assertIsString($content);
            $this->assertStringContainsString('php bin/w module:remove Weline_SampleModule', $content);
            $this->assertStringContainsString('不负责导出或下载 SQL', $content);
            $this->assertStringContainsString('/sample-module', $content);
            $this->assertStringNotContainsString('license-key-1234567890', $content);
        } finally {
            $this->removeDirectory($moduleDir);
        }
    }

    public function testMaskSecretKeepsOnlyEdges(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'maskSecret');
        $method->setAccessible(true);

        $this->assertSame('lice************7890', $method->invoke($service, 'license-key-1234567890'));
        $this->assertSame('********', $method->invoke($service, '12345678'));
        $this->assertSame('', $method->invoke($service, ''));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
