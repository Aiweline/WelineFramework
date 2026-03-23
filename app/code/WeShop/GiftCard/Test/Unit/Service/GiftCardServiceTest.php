<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Service\GiftCardService;

class GiftCardServiceTest extends TestCase
{
    public function testGetCustomerGiftCardSummaryCalculatesTotals(): void
    {
        $service = new class() extends GiftCardService {
            public function getCustomerGiftCards(int $customerId, int $limit = 20): array
            {
                return [
                    ['status' => 'active', 'amount' => '100.00', 'balance' => '80.50'],
                    ['status' => 'redeemed', 'amount' => '20.00', 'balance' => '0.00'],
                    ['status' => 'active', 'amount' => '30.00', 'balance' => '10.00'],
                ];
            }
        };

        $summary = $service->getCustomerGiftCardSummary(7);

        $this->assertSame(3, $summary['count']);
        $this->assertSame(2, $summary['active_count']);
        $this->assertSame(90.5, $summary['total_balance']);
        $this->assertSame(150.0, $summary['total_amount']);
    }
}
