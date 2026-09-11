<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\AccountMembershipTierPresenter;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipSpendQuery;

require_once dirname(__DIR__) . '/bootstrap.php';

final class AccountMembershipTierPresenterTest extends TestCase
{
    public function testPresentWithoutMembershipReturnsEmpty(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $presenter = new AccountMembershipTierPresenter($store, MembershipSpendQuery::forTesting());
        $out = $presenter->present('nobody', 0);
        self::assertFalse($out['has_membership']);
        self::assertSame([], $out['benefits']);
        self::assertNull($out['next']);
    }

    public function testPresentVip0ShowsBenefitsAndNextVip1(): void
    {
        $store = CustomerGroupStore::forTesting();
        $vip0 = $store->ensureSystemVipLadder(0);
        $store->assignCustomer('c-1', $vip0->groupId);
        $presenter = new AccountMembershipTierPresenter(
            $store,
            MembershipSpendQuery::forTesting([
                [
                    'customer_id' => 'c-1',
                    'website_id' => 0,
                    'order_type' => 'tob',
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'currency' => 'CNY',
                    'money_snapshot_json' => json_encode([
                        'goods_subtotal_taxed_minor' => 1000,
                        'currency_code' => 'CNY',
                    ], JSON_THROW_ON_ERROR),
                ],
            ]),
        );
        $out = $presenter->present('c-1', 0);
        self::assertTrue($out['has_membership']);
        self::assertSame($vip0->groupId, $out['group_id']);
        self::assertNotSame('', $out['group_name']);
        self::assertNotEmpty($out['benefits']);
        self::assertIsArray($out['next']);
        self::assertSame(SystemVipLadder::groupId(1), $out['next']['group_id']);
        self::assertSame(1000, $out['spend_minor']);
        self::assertGreaterThan(0, (int)$out['next']['spend_threshold_minor']);
    }
}
