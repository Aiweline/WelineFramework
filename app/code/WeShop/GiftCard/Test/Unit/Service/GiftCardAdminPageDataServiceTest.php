<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Model\GiftCard;
use WeShop\GiftCard\Service\GiftCardAdminPageDataService;
use WeShop\GiftCard\Service\GiftCardService;

class GiftCardAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataNormalizesFiltersAndRecords(): void
    {
        $giftCardService = new class() extends GiftCardService {
            public array $receivedFilters = [];

            public function getGiftCardList(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        GiftCard::schema_fields_ID => 7,
                        GiftCard::schema_fields_CUSTOMER_ID => 18,
                        GiftCard::schema_fields_CARD_NUMBER => 'GC-1007',
                        GiftCard::schema_fields_AMOUNT => '100.00',
                        GiftCard::schema_fields_BALANCE => '80.00',
                        GiftCard::schema_fields_STATUS => self::STATUS_ACTIVE,
                        GiftCard::schema_fields_EXPIRES_AT => '2026-12-31 23:59:59',
                        GiftCard::schema_fields_CREATED_AT => '2026-03-24 10:00:00',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }

            public function getGiftCardSummary(): array
            {
                return [
                    'total' => 1,
                    self::STATUS_ACTIVE => 1,
                    self::STATUS_REDEEMED => 0,
                    self::STATUS_EXPIRED => 0,
                    self::STATUS_DISABLED => 0,
                    'total_balance' => 80.0,
                    'total_amount' => 100.0,
                ];
            }
        };

        $service = new GiftCardAdminPageDataService($giftCardService);
        $data = $service->getPageData(1, 20, [
            'customer_id' => '18',
            'card_number' => '1007',
            'status' => 'active',
        ]);

        $this->assertSame([
            'customer_id' => 18,
            'card_number' => '1007',
            'status' => 'active',
        ], $giftCardService->receivedFilters);
        $this->assertCount(1, $data['giftCards']);
        $this->assertSame('GC-1007', $data['giftCards'][0]['card_number']);
        $this->assertSame($giftCardService->getStatusOptions()['active'], $data['giftCards'][0]['status_label']);
        $this->assertSame(0, $data['editingRecord']['card_id']);
        $this->assertSame('active', $data['editingRecord']['status']);
        $this->assertSame(100.0, $data['summary']['total_amount']);
    }

    public function testGetPageDataReturnsEmptyEditingRecordWhenNoEditingId(): void
    {
        $giftCardService = new class() extends GiftCardService {
            public function getGiftCardList(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                return ['items' => [], 'pagination' => []];
            }

            public function getGiftCardSummary(): array
            {
                return [
                    'total' => 0,
                    self::STATUS_ACTIVE => 0,
                    self::STATUS_REDEEMED => 0,
                    self::STATUS_EXPIRED => 0,
                    self::STATUS_DISABLED => 0,
                    'total_balance' => 0.0,
                    'total_amount' => 0.0,
                ];
            }
        };

        $service = new GiftCardAdminPageDataService($giftCardService);
        $data = $service->getPageData();

        $this->assertSame(0, $data['editingRecord']['card_id']);
        $this->assertSame('', $data['editingRecord']['card_number']);
        $this->assertSame(0.0, $data['editingRecord']['amount']);
        $this->assertSame('active', $data['editingRecord']['status']);
    }
}
