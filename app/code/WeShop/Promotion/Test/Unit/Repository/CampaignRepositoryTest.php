<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Repository;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Model\Campaign;
use WeShop\Promotion\Repository\CampaignRepository;
use WeShop\Promotion\Repository\CampaignRepositoryInterface;

class CampaignRepositoryTest extends TestCase
{
    public function testCampaignRepositoryImplementsInterface(): void
    {
        $repository = new CampaignRepository();
        $this->assertInstanceOf(CampaignRepositoryInterface::class, $repository);
    }

    public function testCampaignModelHasCorrectSchema(): void
    {
        $campaign = new Campaign();
        $this->assertSame('weshop_campaign', Campaign::schema_table);
        $this->assertSame('campaign_id', Campaign::schema_primary_key);
        $this->assertContains('campaign_id', $campaign->_unit_primary_keys);
    }

    public function testCampaignModelHasRequiredFields(): void
    {
        $this->assertSame('name', Campaign::schema_fields_NAME);
        $this->assertSame('type', Campaign::schema_fields_TYPE);
        $this->assertSame('description', Campaign::schema_fields_DESCRIPTION);
        $this->assertSame('discount_type', Campaign::schema_fields_DISCOUNT_TYPE);
        $this->assertSame('discount_value', Campaign::schema_fields_DISCOUNT_VALUE);
        $this->assertSame('min_purchase', Campaign::schema_fields_MIN_PURCHASE);
        $this->assertSame('max_discount', Campaign::schema_fields_MAX_DISCOUNT);
        $this->assertSame('start_date', Campaign::schema_fields_START_DATE);
        $this->assertSame('end_date', Campaign::schema_fields_END_DATE);
        $this->assertSame('status', Campaign::schema_fields_STATUS);
        $this->assertSame('is_featured', Campaign::schema_fields_IS_FEATURED);
        $this->assertSame('priority', Campaign::schema_fields_PRIORITY);
    }

    public function testCampaignModelGettersSetters(): void
    {
        $campaign = new Campaign();

        $campaign->setName('Test Campaign');
        $this->assertSame('Test Campaign', $campaign->getName());

        $campaign->setType('flash');
        $this->assertSame('flash', $campaign->getType());

        $campaign->setDescription('Test Description');
        $this->assertSame('Test Description', $campaign->getDescription());

        $campaign->setDiscountType('percent');
        $this->assertSame('percent', $campaign->getDiscountType());

        $campaign->setDiscountValue(25.5);
        $this->assertSame(25.5, $campaign->getDiscountValue());

        $campaign->setMinPurchase(100.0);
        $this->assertSame(100.0, $campaign->getMinPurchase());

        $campaign->setMaxDiscount(50.0);
        $this->assertSame(50.0, $campaign->getMaxDiscount());

        $campaign->setStartDate('2026-06-01 00:00:00');
        $this->assertSame('2026-06-01 00:00:00', $campaign->getStartDate());

        $campaign->setEndDate('2026-08-31 23:59:59');
        $this->assertSame('2026-08-31 23:59:59', $campaign->getEndDate());

        $campaign->setStatus(1);
        $this->assertSame(1, $campaign->getStatus());

        $campaign->setIsFeatured(true);
        $this->assertTrue($campaign->isFeatured());

        $campaign->setPriority(10);
        $this->assertSame(10, $campaign->getPriority());
    }

    public function testCampaignModelProductIdsHandling(): void
    {
        $campaign = new Campaign();

        $productIds = [1, 2, 3, 4, 5];
        $campaign->setProductIds($productIds);
        $this->assertSame($productIds, $campaign->getProductIds());

        $campaign->setProductIds([]);
        $this->assertSame([], $campaign->getProductIds());
    }

    public function testCampaignModelCategoryIdsHandling(): void
    {
        $campaign = new Campaign();

        $categoryIds = [10, 20, 30];
        $campaign->setCategoryIds($categoryIds);
        $this->assertSame($categoryIds, $campaign->getCategoryIds());

        $campaign->setCategoryIds([]);
        $this->assertSame([], $campaign->getCategoryIds());
    }

    public function testCampaignModelIsValidLogic(): void
    {
        $campaign = new Campaign();

        $campaign->setStatus(0);
        $this->assertFalse($campaign->isValid());

        $campaign->setStatus(1);
        $campaign->setStartDate('2026-01-01 00:00:00');
        $campaign->setEndDate('2026-12-31 23:59:59');
        $this->assertTrue($campaign->isValid());

        $campaign->setStartDate('2027-01-01 00:00:00');
        $campaign->setEndDate('2027-12-31 23:59:59');
        $this->assertFalse($campaign->isValid());

        $campaign->setStartDate('2025-01-01 00:00:00');
        $campaign->setEndDate('2025-12-31 23:59:59');
        $this->assertFalse($campaign->isValid());
    }

    public function testCampaignModelStatusLabel(): void
    {
        $campaign = new Campaign();

        $campaign->setStartDate('2027-01-01 00:00:00');
        $campaign->setEndDate('2027-12-31 23:59:59');
        $this->assertSame('未开始', $campaign->getStatusLabel());

        $campaign->setStartDate('2025-01-01 00:00:00');
        $campaign->setEndDate('2025-12-31 23:59:59');
        $this->assertSame('已结束', $campaign->getStatusLabel());

        $campaign->setStartDate('2026-01-01 00:00:00');
        $campaign->setEndDate('2026-12-31 23:59:59');
        $this->assertSame('进行中', $campaign->getStatusLabel());
    }
}
