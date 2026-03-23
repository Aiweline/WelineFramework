<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Service\GiftCardPageDataService;
use WeShop\GiftCard\Service\GiftCardService;

class GiftCardPageDataServiceTest extends TestCase
{
    public function testBuildMapsGiftCardsAndSummary(): void
    {
        $giftCardService = $this->createMock(GiftCardService::class);
        $giftCardService->expects($this->once())
            ->method('getCustomerGiftCards')
            ->with(8, 50)
            ->willReturn([
                [
                    'card_id' => 11,
                    'card_number' => 'GC-1001',
                    'amount' => '100.00',
                    'balance' => '80.00',
                    'status' => 'active',
                    'expires_at' => '2026-12-31 23:59:59',
                    'created_at' => '2026-03-24 10:00:00',
                ],
                [
                    'card_id' => 12,
                    'card_number' => 'GC-1002',
                    'amount' => '50.00',
                    'balance' => '0.00',
                    'status' => 'redeemed',
                    'expires_at' => '',
                    'created_at' => '2026-03-22 09:00:00',
                ],
                'invalid-row',
            ]);
        $giftCardService->expects($this->once())
            ->method('getCustomerGiftCardSummary')
            ->with(8)
            ->willReturn([
                'count' => 2,
                'active_count' => 1,
                'total_balance' => 80.0,
                'total_amount' => 150.0,
            ]);

        $service = new GiftCardPageDataService($giftCardService);
        $result = $service->build(8);

        $this->assertSame(2, $result['gift_card_count']);
        $this->assertSame(1, $result['gift_card_active_count']);
        $this->assertSame(80.0, $result['gift_card_total_balance']);
        $this->assertSame(150.0, $result['gift_card_total_amount']);
        $this->assertCount(2, $result['gift_cards']);
        $this->assertSame('GC-1001', $result['gift_cards'][0]['card_number']);
        $this->assertSame(80.0, $result['gift_cards'][0]['balance']);
    }
}
