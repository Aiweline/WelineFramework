<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Manager\ObjectManager;
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

    public function testResolveDownloadUrlKeepsAbsoluteUrl(): void
    {
        $service = $this->makeServiceWithPlatformUrl('https://app.example.test/CNY/zh_Hans_CN');
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolveDownloadUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'https://cdn.example.test/pkg.zip',
            $method->invoke($service, 'https://cdn.example.test/pkg.zip')
        );
    }

    public function testResolveDownloadUrlUsesPlatformOriginForRootRelativeUrl(): void
    {
        $service = $this->makeServiceWithPlatformUrl('http://127.0.0.1:9502/CNY/zh_Hans_CN');
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolveDownloadUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'http://127.0.0.1:9502/CNY/zh_Hans_CN/package.zip',
            $method->invoke($service, '/CNY/zh_Hans_CN/package.zip')
        );
    }

    public function testResolveDownloadUrlAddsPlatformContextToApiRootRelativeUrl(): void
    {
        $service = $this->makeServiceWithPlatformUrl('http://127.0.0.1:9502/CNY/zh_Hans_CN');
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolveDownloadUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'http://127.0.0.1:9502/CNY/zh_Hans_CN/api/v1/platform/module/package',
            $method->invoke($service, '/api/v1/platform/module/package')
        );
    }

    public function testResolveDownloadUrlUsesPlatformBaseForRelativeUrl(): void
    {
        $service = $this->makeServiceWithPlatformUrl('http://127.0.0.1:9502/CNY/zh_Hans_CN');
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolveDownloadUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'http://127.0.0.1:9502/CNY/zh_Hans_CN/package.zip',
            $method->invoke($service, 'package.zip')
        );
    }

    public function testResolvePlatformUrlUsesEnvironmentOverride(): void
    {
        $previous = getenv('WELINE_APPSTORE_PLATFORM_URL');
        putenv('WELINE_APPSTORE_PLATFORM_URL=https://platform.example.test/base/');

        try {
            $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolvePlatformUrl');
            $method->setAccessible(true);

            $this->assertSame('https://platform.example.test/base', $method->invoke($service));
        } finally {
            if (is_string($previous)) {
                putenv('WELINE_APPSTORE_PLATFORM_URL=' . $previous);
            } else {
                putenv('WELINE_APPSTORE_PLATFORM_URL');
            }
        }
    }

    public function testNormalizePlatformApiBaseUrlRemovesCurrencyLocaleSuffix(): void
    {
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'normalizePlatformApiBaseUrl');
        $method->setAccessible(true);

        $this->assertSame(
            'http://127.0.0.1:9502',
            $method->invoke(null, 'http://127.0.0.1:9502/CNY/zh_Hans_CN')
        );
        $this->assertSame(
            'https://apps.example.test',
            $method->invoke(null, 'https://apps.example.test/usd/en_US/')
        );
    }

    public function testGetCurrentDomainUsesCurrentRequestHostBeforeLoopbackGlobals(): void
    {
        $previousServerHost = $_SERVER['HTTP_HOST'] ?? null;
        $previousInstances = $this->getObjectManagerInstances();

        try {
            $_SERVER['HTTP_HOST'] = '127.0.0.1:9502';
            $request = new class {
                public function getServer(string $key = ''): string|array
                {
                    return $key === 'HTTP_HOST' ? 'p11005ce4.weline.test' : '';
                }
            };
            ObjectManager::setInstance(\Weline\Framework\Http\Request::class, $request);

            $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(ModuleInstallerService::class, 'getCurrentDomain');
            $method->setAccessible(true);

            $this->assertSame('p11005ce4.weline.test', $method->invoke($service));
        } finally {
            if ($previousServerHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previousServerHost;
            }
            $this->setObjectManagerInstances($previousInstances);
        }
    }

    public function testResolveDownloadDomainUsesExplicitStoreDomain(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'resolveDownloadDomain');
        $method->setAccessible(true);

        $this->assertSame(
            'p11005ce4.weline.test',
            $method->invoke($service, 'https://p11005ce4.weline.test/backend')
        );
    }

    public function testExtractBoundLicenseDomainFromPlatformMessage(): void
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'extractBoundLicenseDomain');
        $method->setAccessible(true);

        $this->assertSame(
            '127.0.0.1',
            $method->invoke($service, new \RuntimeException('Domain does not match the bound license domain: 127.0.0.1'))
        );
    }

    public function testBuildWlsReloadCommandTargetsCurrentInstance(): void
    {
        $previousEnv = getenv('WLS_INSTANCE');
        $previousServer = $_SERVER['WLS_INSTANCE'] ?? null;
        $previousSuperEnv = $_ENV['WLS_INSTANCE'] ?? null;

        putenv('WLS_INSTANCE=ai-appstore-terminal-9503');
        unset($_SERVER['WLS_INSTANCE'], $_ENV['WLS_INSTANCE']);

        try {
            $service = new class extends ModuleInstallerService {
                public function __construct()
                {
                }

                public function exposeBuildWlsReloadCommand(): string
                {
                    return $this->buildWlsReloadCommand();
                }
            };

            $command = $service->exposeBuildWlsReloadCommand();

            $this->assertStringContainsString('server:reload', $command);
            $this->assertStringContainsString('ai-appstore-terminal-9503', $command);
            $this->assertStringEndsWith(' -n', $command);
            $this->assertStringNotContainsString('&', $command);
        } finally {
            if (is_string($previousEnv)) {
                putenv('WLS_INSTANCE=' . $previousEnv);
            } else {
                putenv('WLS_INSTANCE');
            }

            if ($previousServer !== null) {
                $_SERVER['WLS_INSTANCE'] = $previousServer;
            } else {
                unset($_SERVER['WLS_INSTANCE']);
            }

            if ($previousSuperEnv !== null) {
                $_ENV['WLS_INSTANCE'] = $previousSuperEnv;
            } else {
                unset($_ENV['WLS_INSTANCE']);
            }
        }
    }

    public function testWlsRunningCheckUsesCurrentInstanceRecord(): void
    {
        $previousEnv = getenv('WLS_INSTANCE');
        $previousServer = $_SERVER['WLS_INSTANCE'] ?? null;
        $previousSuperEnv = $_ENV['WLS_INSTANCE'] ?? null;
        $instanceName = 'ai-appstore-terminal-phpunit-' . getmypid();
        $instanceDir = BP . 'var' . DS . 'server' . DS . 'instances';
        $instanceFile = $instanceDir . DS . $instanceName . '.json';

        if (!is_dir($instanceDir)) {
            mkdir($instanceDir, 0777, true);
        }
        file_put_contents($instanceFile, json_encode(['master_pid' => getmypid()], JSON_THROW_ON_ERROR));
        putenv('WLS_INSTANCE=' . $instanceName);
        unset($_SERVER['WLS_INSTANCE'], $_ENV['WLS_INSTANCE']);

        try {
            $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(ModuleInstallerService::class, 'isWlsRunning');
            $method->setAccessible(true);

            $this->assertTrue($method->invoke($service));
        } finally {
            if (is_file($instanceFile)) {
                unlink($instanceFile);
            }

            if (is_string($previousEnv)) {
                putenv('WLS_INSTANCE=' . $previousEnv);
            } else {
                putenv('WLS_INSTANCE');
            }

            if ($previousServer !== null) {
                $_SERVER['WLS_INSTANCE'] = $previousServer;
            } else {
                unset($_SERVER['WLS_INSTANCE']);
            }

            if ($previousSuperEnv !== null) {
                $_ENV['WLS_INSTANCE'] = $previousSuperEnv;
            } else {
                unset($_ENV['WLS_INSTANCE']);
            }
        }
    }

    public function testBuildCommandUpgradeCommandUsesFreshCliProcess(): void
    {
        $service = new class extends ModuleInstallerService {
            public function __construct()
            {
            }

            public function exposeBuildCommandUpgradeCommand(): string
            {
                return $this->buildCommandUpgradeCommand();
            }
        };

        $command = $service->exposeBuildCommandUpgradeCommand();

        $this->assertStringContainsString('bin' . DS . 'w', $command);
        $this->assertStringContainsString('command:upgrade', $command);
        $this->assertStringNotContainsString('&', $command);
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

    public function testAppendInstallRecordPersistsUpgradeAction(): void
    {
        $recordDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_appstore_records_' . uniqid('', true);
        $service = new class($recordDir) extends ModuleInstallerService {
            public function __construct(private string $recordDir)
            {
            }

            protected function getInstallRecordDir(): string
            {
                return $this->recordDir;
            }
        };
        $method = new \ReflectionMethod(ModuleInstallerService::class, 'appendInstallRecord');
        $method->setAccessible(true);

        try {
            $path = $method->invoke($service, [
                'action' => 'upgrade',
                'module_name' => 'Weline_AppStore',
                'version' => '1.2.0',
                'previous_version' => '1.1.0',
            ]);

            $this->assertFileExists($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($lines);
            $payload = json_decode((string)end($lines), true);
            $this->assertIsArray($payload);
            $this->assertSame('upgrade', $payload['action']);
            $this->assertSame('Weline_AppStore', $payload['module_name']);
            $this->assertSame('1.1.0', $payload['previous_version']);
            $this->assertNotEmpty($payload['recorded_at']);
        } finally {
            $this->removeDirectory($recordDir);
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

    public function testDownloadDefersLogSaveUntilAfterFileTransfer(): void
    {
        $source = file_get_contents((string)(new ReflectionClass(ModuleInstallerService::class))->getFileName());
        $this->assertIsString($source);

        $downloadStart = strpos($source, 'public function download(');
        $transferCall = strpos($source, '$this->downloadFile(', (int)$downloadStart);
        $this->assertNotFalse($downloadStart);
        $this->assertNotFalse($transferCall);

        $beforeTransfer = substr($source, (int)$downloadStart, (int)$transferCall - (int)$downloadStart);
        $this->assertStringNotContainsString('$log->save();', $beforeTransfer);
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

    private function makeServiceWithPlatformUrl(string $platformUrl): ModuleInstallerService
    {
        $service = (new ReflectionClass(ModuleInstallerService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(ModuleInstallerService::class, 'platformApiUrl');
        $property->setAccessible(true);
        $property->setValue($service, $platformUrl);

        return $service;
    }

    /**
     * @return array<string, object>
     */
    private function getObjectManagerInstances(): array
    {
        $property = new ReflectionProperty(ObjectManager::class, 'instances');
        $property->setAccessible(true);

        return (array)$property->getValue();
    }

    /**
     * @param array<string, object> $instances
     */
    private function setObjectManagerInstances(array $instances): void
    {
        $property = new ReflectionProperty(ObjectManager::class, 'instances');
        $property->setAccessible(true);
        $property->setValue(null, $instances);
    }
}
