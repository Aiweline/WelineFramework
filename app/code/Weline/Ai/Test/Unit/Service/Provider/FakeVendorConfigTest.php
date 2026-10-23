<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\VendorConfigManager;

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR);
    require_once BP . 'app/code/Weline/Framework/App/DirectoryList.php';
    \Weline\Framework\App\DirectoryList::defineBootstrapConstants(BP);
}


/**
 * T2 验收：fake 供应商标配（etc/vendors/fake.json）可被加载，并能按模型前缀反查供应商。
 */
final class FakeVendorConfigTest extends TestCase
{
    public function testFakeVendorConfigLoads(): void
    {
        VendorConfigManager::clearCache();
        $config = VendorConfigManager::getProviderConfig('fake');
        $this->assertIsArray($config, 'fake.json 未加载');
        $this->assertSame('fake', $config['code'] ?? null);
        $this->assertSame('fake-translator', $config['test_model'] ?? null);
        $this->assertTrue(VendorConfigManager::isProviderSupported('fake'));
    }

    public function testModelPrefixResolvesToFakeProvider(): void
    {
        VendorConfigManager::clearCache();
        $this->assertSame('fake', VendorConfigManager::getProviderByModelCode('fake-translator'));
    }
}
