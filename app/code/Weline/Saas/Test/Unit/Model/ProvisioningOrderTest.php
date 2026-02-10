<?php

declare(strict_types=1);

namespace Weline\Saas\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Saas\Model\ProvisioningOrder;

/**
 * ProvisioningOrder 模型单元测试
 */
class ProvisioningOrderTest extends TestCase
{
    public function testStatusConstants(): void
    {
        $this->assertSame('pending', ProvisioningOrder::STATUS_PENDING);
        $this->assertSame('step_purchase', ProvisioningOrder::STATUS_STEP_PURCHASE);
        $this->assertSame('step_dns', ProvisioningOrder::STATUS_STEP_DNS);
        $this->assertSame('step_cdn', ProvisioningOrder::STATUS_STEP_CDN);
        $this->assertSame('step_ssl', ProvisioningOrder::STATUS_STEP_SSL);
        $this->assertSame('completed', ProvisioningOrder::STATUS_COMPLETED);
        $this->assertSame('failed', ProvisioningOrder::STATUS_FAILED);
    }

    public function testStepConstants(): void
    {
        $this->assertSame('purchase', ProvisioningOrder::STEP_PURCHASE);
        $this->assertSame('dns', ProvisioningOrder::STEP_DNS);
        $this->assertSame('cdn', ProvisioningOrder::STEP_CDN);
        $this->assertSame('ssl', ProvisioningOrder::STEP_SSL);
    }

    public function testTableName(): void
    {
        $this->assertSame('saas_provisioning_order', ProvisioningOrder::table);
    }
}
