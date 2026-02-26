<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Api\QuickBuildServiceInterface;
use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

class QuickBuildAggregatorTest extends TestCase
{
    private ?QuickBuildAggregator $aggregator = null;

    public function setUp(): void
    {
        try {
            $this->aggregator = ObjectManager::getInstance(QuickBuildAggregator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ObjectManager 不可用: ' . $e->getMessage());
        }
    }

    public function testQueryServicesReturnsArray(): void
    {
        $services = $this->aggregator->queryServices('all');
        $this->assertIsArray($services);
    }

    public function testQueryServicesAllContainsRegisteredModules(): void
    {
        $services = $this->aggregator->queryServices('all');
        $this->assertIsArray($services);

        if (empty($services)) {
            $this->markTestSkipped('事件系统未在当前测试环境初始化，跳过集成断言');
        }

        $modules = array_column($services, 'module');
        $this->assertContains('Weline_Websites', $modules, '应包含域名服务模块');
        $this->assertContains('Weline_Cdn', $modules, '应包含 CDN 服务模块');
        $this->assertContains('Weline_Server', $modules, '应包含 SSL 服务模块');
        $this->assertContains('Weline_Saas', $modules, '应包含一站式配置模块');
    }

    public function testQueryServicesCategoryFilter(): void
    {
        $allServices = $this->aggregator->queryServices('all');
        if (empty($allServices)) {
            $this->markTestSkipped('事件系统未在当前测试环境初始化');
        }

        $domainServices = $this->aggregator->queryServices('domain');
        $this->assertIsArray($domainServices);
        foreach ($domainServices as $svc) {
            $this->assertSame('domain', $svc['category'], '分类过滤应仅返回 domain 类服务');
        }
    }

    public function testQueryServicesSortedByOrder(): void
    {
        $services = $this->aggregator->queryServices('all');
        if (count($services) < 2) {
            $this->markTestSkipped('服务数不足以测试排序');
        }

        $prevOrder = -1;
        foreach ($services as $svc) {
            $order = $svc['order'] ?? 999;
            $this->assertGreaterThanOrEqual($prevOrder, $order, '服务应按 order 升序排列');
            $prevOrder = $order;
        }
    }

    public function testQueryRegistrarAccountsReturnsArray(): void
    {
        $accounts = $this->aggregator->queryRegistrarAccounts();
        $this->assertIsArray($accounts);
    }

    public function testCheckAvailabilityWithInvalidAccount(): void
    {
        $results = $this->aggregator->checkAvailability(0, ['example.com']);
        $this->assertIsArray($results);
    }

    public function testPurchaseDomainWithInvalidAccount(): void
    {
        $result = $this->aggregator->purchaseDomain(0, [['domain' => 'test.invalid', 'years' => 1]]);
        $this->assertIsArray($result);
    }

    public function testStartProvisioningWithEmptyDomain(): void
    {
        $result = $this->aggregator->startProvisioning('', 0);
        $this->assertIsArray($result);
        $this->assertFalse($result['success'] ?? true);
    }

    public function testQueryProvisioningOrdersReturnsArray(): void
    {
        $orders = $this->aggregator->queryProvisioningOrders();
        $this->assertIsArray($orders);
    }

    public function testInterfaceConstants(): void
    {
        $this->assertSame('domain', QuickBuildServiceInterface::CATEGORY_DOMAIN);
        $this->assertSame('dns', QuickBuildServiceInterface::CATEGORY_DNS);
        $this->assertSame('cdn', QuickBuildServiceInterface::CATEGORY_CDN);
        $this->assertSame('ssl', QuickBuildServiceInterface::CATEGORY_SSL);
        $this->assertSame('template', QuickBuildServiceInterface::CATEGORY_TEMPLATE);
        $this->assertSame('provisioning', QuickBuildServiceInterface::CATEGORY_PROVISIONING);
    }

    public function testServiceStructureFields(): void
    {
        $services = $this->aggregator->queryServices('all');
        if (empty($services)) {
            $this->markTestSkipped('无可用服务');
        }

        $requiredKeys = ['module', 'category', 'name', 'description', 'icon', 'order', 'available'];

        foreach ($services as $svc) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $svc, "服务 {$svc['name']} 缺少必需键 '$key'");
            }
        }
    }
}
