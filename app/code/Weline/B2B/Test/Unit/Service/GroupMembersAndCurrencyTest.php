<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BBaseCurrencyResolver;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipSpendQuery;
use Weline\Order\Model\Order;

require_once dirname(__DIR__) . '/bootstrap.php';

final class GroupMembersAndCurrencyTest extends TestCase
{
    public function testListMembersByGroupSupportsSearch(): void
    {
        $store = CustomerGroupStore::forTesting();
        $vip0 = $store->ensureSystemVipLadder(0);
        $store->assignCustomer('c-alpha', $vip0->groupId);
        $store->assignCustomer('c-beta', $vip0->groupId);

        $all = $store->listMembersByGroup($vip0->groupId);
        self::assertCount(2, $all);

        $filtered = $store->listMembersByGroup($vip0->groupId, 'alpha');
        self::assertCount(1, $filtered);
        self::assertSame('c-alpha', $filtered[0]['customer_id']);
    }

    public function testSpendQueryConvertsForeignCurrencyBeforeAccumulate(): void
    {
        $currency = new class extends B2BBaseCurrencyResolver {
            public function forWebsite(int $websiteId): string
            {
                return 'CNY';
            }

            public function convertMinorToWebsiteDefault(
                int $amountMinor,
                string $sourceCurrency,
                int $websiteId,
            ): ?int {
                $source = strtoupper(trim($sourceCurrency));
                if ($source === '' || $source === 'CNY') {
                    return max(0, $amountMinor);
                }
                if ($source === 'USD') {
                    return max(0, (int)round($amountMinor * 7));
                }

                return null;
            }
        };

        $spend = MembershipSpendQuery::forTesting([
            [
                'customer_id' => 'c1',
                'website_id' => 0,
                'order_type' => 'tob',
                'payment_status' => Order::PAYMENT_STATUS_PAID,
                'status' => 'complete',
                'currency' => 'USD',
                'money_snapshot_json' => json_encode([
                    'goods_subtotal_taxed_minor' => 100,
                    'currency_code' => 'USD',
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'customer_id' => 'c1',
                'website_id' => 0,
                'order_type' => 'tob',
                'payment_status' => Order::PAYMENT_STATUS_PAID,
                'status' => 'complete',
                'currency' => 'CNY',
                'money_snapshot_json' => json_encode([
                    'goods_subtotal_taxed_minor' => 50,
                    'currency_code' => 'CNY',
                ], JSON_THROW_ON_ERROR),
            ],
        ], $currency);

        // 100 USD minor * 7 + 50 CNY = 750
        self::assertSame(750, $spend->sumPaidTobGoodsMinor('c1', 0));
    }

    public function testAssignAndRemoveMemberViaStore(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->put(new CustomerGroup('g-custom', 0, 'custom', CustomerGroup::STATUS_ACTIVE));
        $store->assignCustomer('c-1', 'g-custom');
        self::assertSame('g-custom', $store->groupForCustomer('c-1', 0)?->groupId);
        self::assertTrue($store->unassignCustomer('c-1', 0));
        self::assertNull($store->groupForCustomer('c-1', 0));
        self::assertSame(SystemVipLadder::groupId(0), $store->ensureSystemVipLadder(0)->groupId);
    }
}
