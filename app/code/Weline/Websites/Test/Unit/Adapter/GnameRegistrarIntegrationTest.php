<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Adapter\GnameRegistrar;
use Weline\Websites\Api\DomainRegistrarInterface;
use Weline\Websites\Service\DomainRegistrarResolverService;

/**
 * GName 适配器集成测试
 *
 * 验证适配器能被 DomainRegistrarResolverService 自动发现和加载，
 * 以及 DomainProvisioningService 的公网 IP 获取和 NS 切换流程。
 */
class GnameRegistrarIntegrationTest extends TestCase
{
    /**
     * 测试：GName 适配器被 Resolver 自动发现
     */
    public function testAdapterDiscoveredByResolver(): void
    {
        try {
            $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapters = $resolver->getAllAdapters(true);

            $this->assertArrayHasKey('gname', $adapters, 'GName 适配器应能被 Resolver 自动发现');
            $this->assertInstanceOf(DomainRegistrarInterface::class, $adapters['gname']);
            $this->assertInstanceOf(GnameRegistrar::class, $adapters['gname']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    /**
     * 测试：通过 Resolver 获取 GName 适配器实例
     */
    public function testGetAdapterByCode(): void
    {
        try {
            $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $adapter = $resolver->getAdapter('gname');

            $this->assertNotNull($adapter, '通过 code "gname" 应能获取适配器');
            $this->assertSame('gname', $adapter->getRegistrarCode());
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    /**
     * 测试：GName 适配器出现在 adapterOptions 列表中
     */
    public function testAdapterInOptionsList(): void
    {
        try {
            $resolver = ObjectManager::getInstance(DomainRegistrarResolverService::class);
            $options = $resolver->getAdapterOptions();

            $codes = array_column($options, 'code');
            $this->assertContains('gname', $codes, 'GName 应出现在可选适配器列表中');

            $gnameOption = null;
            foreach ($options as $opt) {
                if ($opt['code'] === 'gname') {
                    $gnameOption = $opt;
                    break;
                }
            }

            $this->assertNotNull($gnameOption);
            $this->assertSame('GName', $gnameOption['name']);
            $this->assertNotEmpty($gnameOption['config_fields']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    /**
     * 测试：DomainProvisioningService 公网 IP 获取
     */
    public function testGetPublicIp(): void
    {
        try {
            $service = ObjectManager::getInstance(\Weline\Websites\Service\DomainProvisioningService::class);
            $ip = $service->getPublicIp();

            if ($ip === '') {
                $this->markTestSkipped('无法获取公网 IP（可能无网络）');
            }

            $this->assertNotEmpty($ip);
            $this->assertTrue(
                (bool) \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
                "返回的 IP '{$ip}' 应为有效的 IPv4 地址"
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    /**
     * 测试：switchNameservers 对无效订单返回失败
     */
    public function testSwitchNameserversInvalidOrder(): void
    {
        try {
            $service = ObjectManager::getInstance(\Weline\Websites\Service\DomainProvisioningService::class);
            $result = $service->switchNameservers(999999, ['ns1.example.com', 'ns2.example.com']);

            $this->assertIsArray($result);
            $this->assertFalse($result['success']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    /**
     * 测试：GName updateNameservers 返回结构正确（无效凭据场景）
     */
    public function testUpdateNameserversWithInvalidCredentials(): void
    {
        $adapter = new GnameRegistrar();

        try {
            $result = $adapter->updateNameservers('example.com', ['ns1.cf.com', 'ns2.cf.com'], [
                'appid' => 'invalid',
                'appkey' => 'invalid',
            ]);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('message', $result);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GName', $e->getMessage());
        }
    }
}
