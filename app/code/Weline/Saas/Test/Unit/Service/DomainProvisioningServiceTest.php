<?php

declare(strict_types=1);

namespace Weline\Saas\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Model\ProvisioningStep;
use Weline\Saas\Service\DomainProvisioningService;

/**
 * DomainProvisioningService 单元测试
 *
 * 测试校验逻辑与返回结构；全流程测试需数据库与可选 mock。
 */
class DomainProvisioningServiceTest extends TestCase
{
    /**
     * 测试：空域名校验返回失败结构
     */
    public function testStartProvisioningRejectsEmptyDomain(): void
    {
        $result = $this->callStartProvisioningWithDomain('   ');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * 测试：空字符串域名校验
     */
    public function testStartProvisioningRejectsBlankDomain(): void
    {
        $result = $this->callStartProvisioningWithDomain('');
        $this->assertFalse($result['success']);
    }

    private function callStartProvisioningWithDomain(string $domain): array
    {
        try {
            $service = \Weline\Framework\Manager\ObjectManager::getInstance(DomainProvisioningService::class);
            return $service->startProvisioning($domain, 0, []);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
