<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Model\SourceItem;
use WeShop\Inventory\Service\SourceItemManagementService;

class SourceItemManagementServiceTest extends TestCase
{
    public function testGetStatusOptionsReturnsExpectedLabels(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $options = $service->getStatusOptions();

        $this->assertArrayHasKey(SourceItem::STATUS_IN_STOCK, $options);
        $this->assertArrayHasKey(SourceItem::STATUS_OUT_OF_STOCK, $options);
        $this->assertNotEmpty($options[SourceItem::STATUS_IN_STOCK]);
        $this->assertNotEmpty($options[SourceItem::STATUS_OUT_OF_STOCK]);
    }

    public function testGetProductStockSummaryThrowsOnInvalidProductId(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $this->expectException(\InvalidArgumentException::class);
        $service->getProductStockSummary(0);
    }

    public function testUpdateSourceItemThrowsOnInvalidId(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid source item id.');
        $service->updateSourceItem(0, ['quantity' => 10]);
    }

    public function testUpdateSourceItemThrowsOnNegativeQuantity(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity cannot be negative.');
        $service->updateSourceItem(1, ['quantity' => -5]);
    }

    public function testUpdateSourceItemThrowsOnNonNumericQuantity(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be a valid number.');
        $service->updateSourceItem(1, ['quantity' => 'not-a-number']);
    }

    public function testUpdateSourceItemThrowsOnNonNumericThreshold(): void
    {
        $sourceItemModel = $this->createMock(SourceItem::class);
        $service = new SourceItemManagementService($sourceItemModel);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Low stock threshold must be a valid integer.');
        $service->updateSourceItem(1, [
            'quantity' => 5,
            'low_stock_threshold' => 'abc',
        ]);
    }
}
