<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\ModuleInstallerService;
use Weline\AppStore\Service\ModuleUpdateService;

class ModuleUpdateServiceTest extends TestCase
{
    public function testAvailableUpdateDownloadsInstallsAndRecordsUpgradeOptions(): void
    {
        $installer = new class extends ModuleInstallerService {
            public array $downloads = [];
            public array $installs = [];

            public function __construct()
            {
            }

            public function download(string $licenseKey, ?int $moduleId = null, ?string $version = null, ?string $downloadIp = null): array
            {
                $this->downloads[] = [$licenseKey, $moduleId, $version, $downloadIp];

                return [
                    'success' => true,
                    'log_id' => 901,
                    'module_name' => 'Weline_AppStore',
                    'version' => '1.2.0',
                    'file_path' => '/tmp/Weline_AppStore-1.2.0.zip',
                    'file_size' => 1234,
                    'file_hash' => 'hash-123',
                    'module_info' => [
                        'display_name' => '平台应用商城',
                        'description' => '官网返回的模块说明',
                    ],
                ];
            }

            public function install(string $zipPath, array $options = []): array
            {
                $this->installs[] = [$zipPath, $options];

                return [
                    'success' => true,
                    'module_name' => 'Weline_AppStore',
                    'version' => '1.2.0',
                    'previous_version' => (string)($options['previous_version'] ?? ''),
                    'install_record_path' => '/tmp/install-records/2026-05.jsonl',
                    'record_action' => (string)($options['action'] ?? ''),
                ];
            }
        };

        $module = new AppStoreInstalledModule();
        $module->setModuleName('Weline_AppStore');
        $module->setVersion('1.1.0');
        $module->setLicenseKey('local-license');
        $module->setPlatformModuleId(1001);

        $service = new ModuleUpdateService($installer);
        $result = $service->update($module, [
            'module_name' => 'Weline_AppStore',
            'latest_version' => '1.2.0',
            'update_available' => true,
            'license_key' => '',
            'platform_module_id' => 0,
        ]);

        $this->assertSame([['local-license', 1001, '1.2.0', null]], $installer->downloads);
        $this->assertCount(1, $installer->installs);
        $this->assertSame('/tmp/Weline_AppStore-1.2.0.zip', $installer->installs[0][0]);
        $this->assertSame('upgrade', $installer->installs[0][1]['action']);
        $this->assertSame('1.1.0', $installer->installs[0][1]['previous_version']);
        $this->assertSame(901, $installer->installs[0][1]['download_log_id']);
        $this->assertSame('hash-123', $installer->installs[0][1]['download_file_hash']);
        $this->assertSame(1234, $installer->installs[0][1]['download_file_size']);
        $this->assertSame('平台应用商城', $installer->installs[0][1]['display_name']);
        $this->assertSame('upgrade', $result['record_action']);
        $this->assertSame('1.2.0', $result['version']);
        $this->assertSame('1.1.0', $result['previous_version']);
    }
}
