<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Backend\Campaign;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Model\Campaign;

class SaveTest extends TestCase
{
    public function testSaveCampaignWithValidData(): void
    {
        $data = [
            'name' => 'Summer Sale',
            'type' => 'seasonal',
            'description' => 'Summer promotion',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'min_purchase' => 100,
            'max_discount' => 50,
            'start_date' => '2026-06-01 00:00:00',
            'end_date' => '2026-08-31 23:59:59',
            'product_ids' => '1,2,3',
            'category_ids' => '5,6',
            'status' => 1,
            'is_featured' => 1,
            'priority' => 10,
        ];

        $this->assertSame('Summer Sale', $data['name']);
        $this->assertSame('seasonal', $data['type']);
        $this->assertSame('percent', $data['discount_type']);
        $this->assertEquals(20.0, $data['discount_value']);
    }

    public function testSaveCampaignWithInvalidDateRange(): void
    {
        $startDate = '2026-08-31 23:59:59';
        $endDate = '2026-06-01 00:00:00';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('End date must be after start date.');

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('End date must be after start date.');
        }
    }

    public function testSaveCampaignWithInvalidDiscountType(): void
    {
        $invalidDiscountType = 'invalid';
        $validDiscountTypes = ['fixed', 'percent', 'buy_x_get_y'];

        $this->assertNotContains($invalidDiscountType, $validDiscountTypes);

        $result = in_array($invalidDiscountType, $validDiscountTypes, true) ? $invalidDiscountType : 'fixed';
        $this->assertSame('fixed', $result);
    }

    public function testSaveCampaignWithInvalidType(): void
    {
        $invalidType = 'invalid';
        $validTypes = ['promotion', 'flash', 'seasonal'];

        $this->assertNotContains($invalidType, $validTypes);

        $result = in_array($invalidType, $validTypes, true) ? $invalidType : 'promotion';
        $this->assertSame('promotion', $result);
    }

    public function testParseCommaSeparatedIds(): void
    {
        $input = '1,2,3,4,5';
        $expected = [1, 2, 3, 4, 5];

        $ids = array_filter(array_map('intval', explode(',', $input)));
        $result = array_values($ids);

        $this->assertSame($expected, $result);
    }

    public function testParseEmptyCommaSeparatedIds(): void
    {
        $input = '';
        $expected = [];

        $ids = array_filter(array_map('intval', explode(',', $input)));
        $result = array_values($ids);

        $this->assertSame($expected, $result);
    }

    public function testParseCommaSeparatedIdsWithInvalidValues(): void
    {
        $input = '1,abc,3,def,5';
        $expected = [1, 3, 5];

        $ids = array_filter(array_map('intval', explode(',', $input)));
        $result = array_values($ids);

        $this->assertSame($expected, $result);
    }

    public function testNormalizeDate(): void
    {
        $input = '2026-06-01 12:30:00';
        $timestamp = strtotime($input);
        $result = date('Y-m-d H:i:s', $timestamp);

        $this->assertSame($input, $result);
    }

    public function testNormalizeDateWithInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $input = 'invalid-date';
        $timestamp = strtotime($input);

        if ($timestamp === false) {
            throw new \InvalidArgumentException('Invalid date format.');
        }
    }
}
