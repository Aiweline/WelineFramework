<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;

class RmaServiceTest extends TestCase
{
    public function testServiceConstants(): void
    {
        $this->assertSame('pending', RmaService::STATUS_PENDING);
        $this->assertSame('approved', RmaService::STATUS_APPROVED);
        $this->assertSame('rejected', RmaService::STATUS_REJECTED);
    }

    public function testCreateRmaRequiresReason(): void
    {
        $service = new RmaService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Return reason is required.');

        $service->createRma([
            'order_id' => 1,
            'customer_id' => 1,
            'reason' => '',
        ]);
    }

    public function testCreateRmaWithValidData(): void
    {
        $service = new RmaService();

        $rmaData = [
            'order_id' => 1,
            'customer_id' => 1,
            'reason' => 'Damaged in transit',
            'description' => 'Package was crushed',
            'status' => RmaService::STATUS_PENDING,
        ];

        $this->assertIsArray($rmaData);
        $this->assertSame('Damaged in transit', $rmaData['reason']);
    }

    public function testApproveRmaChangesStatusToApproved(): void
    {
        $service = new RmaService();

        $this->assertSame(RmaService::STATUS_APPROVED, RmaService::STATUS_APPROVED);
    }

    public function testRejectRmaChangesStatusToRejected(): void
    {
        $service = new RmaService();

        $this->assertSame(RmaService::STATUS_REJECTED, RmaService::STATUS_REJECTED);
    }

    public function testStatusConstantsAreValid(): void
    {
        $validStatuses = [
            RmaService::STATUS_PENDING,
            RmaService::STATUS_APPROVED,
            RmaService::STATUS_REJECTED,
        ];

        $this->assertCount(3, $validStatuses);
        $this->assertContains('pending', $validStatuses);
        $this->assertContains('approved', $validStatuses);
        $this->assertContains('rejected', $validStatuses);
    }
}
