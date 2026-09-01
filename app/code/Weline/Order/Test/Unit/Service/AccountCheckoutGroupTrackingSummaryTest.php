<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\AccountCheckoutGroupPresenter;

final class AccountCheckoutGroupTrackingSummaryTest extends TestCase
{
    public function testShippedGroupExposesTrackingSummary(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g1',
            'display_number' => 'WL-G1',
            'status' => 'fulfilled',
            'grand_total_minor' => 1000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'o1',
                    'display_number' => 'WL-1',
                    'status' => 'fulfilled',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'shipped',
                ],
            ],
        ]);

        self::assertSame('已发货，发往目的地', $view['tracking_summary']);
    }

    public function testPendingPaymentHasNoTrackingSummary(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();
        $view = $presenter->present([
            'group_uuid' => 'g2',
            'display_number' => 'WL-G2',
            'status' => 'pending',
            'grand_total_minor' => 1000,
            'currency' => 'CNY',
            'orders' => [
                [
                    'order_uuid' => 'o2',
                    'display_number' => 'WL-2',
                    'status' => 'pending',
                    'amount_minor' => 1000,
                    'fulfillment_status' => 'none',
                ],
            ],
        ]);

        self::assertSame('', $view['tracking_summary']);
    }
}
