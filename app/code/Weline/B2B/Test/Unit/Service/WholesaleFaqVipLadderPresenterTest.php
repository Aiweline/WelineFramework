<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BBaseCurrencyResolver;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\WholesaleFaqVipLadderPresenter;

require_once dirname(__DIR__) . '/bootstrap.php';

final class WholesaleFaqVipLadderPresenterTest extends TestCase
{
    public function testPresentListsActiveGroupsSortedByTierRankWithSpendAndCredit(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->updateDisplayName(SystemVipLadder::groupId(1), '铜牌批发');
        $store->updateGroupCapabilities(SystemVipLadder::groupId(1), [
            'description' => '测试说明 VIP1',
            'spend_threshold_minor' => 12_345_00,
            'credit_limit_minor' => 2_000_00,
        ]);
        $store->updateGroupCapabilities(SystemVipLadder::groupId(2), [
            'status' => CustomerGroup::STATUS_DISABLED,
        ]);

        $currency = new class extends B2BBaseCurrencyResolver {
            public function forWebsite(int $websiteId): string
            {
                return 'CNY';
            }
        };
        $presenter = new WholesaleFaqVipLadderPresenter(
            $store,
            $currency,
            B2BPaymentAssetPolicyProvider::forTesting(true),
        );
        $view = $presenter->present(0);

        self::assertSame('CNY', $view['currency_code']);
        self::assertTrue($view['credit_enabled']);
        self::assertNotEmpty($view['groups']);

        $codes = array_map(static fn(array $g): string => (string)$g['code'], $view['groups']);
        self::assertContains('vip0', $codes);
        self::assertContains('vip1', $codes);
        self::assertNotContains('vip2', $codes, 'disabled groups must be omitted');

        $ranks = array_map(static fn(array $g): int => (int)$g['tier_rank'], $view['groups']);
        $sorted = $ranks;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $ranks);

        $vip1 = null;
        foreach ($view['groups'] as $row) {
            if ((string)$row['group_id'] === SystemVipLadder::groupId(1)) {
                $vip1 = $row;
                break;
            }
        }
        self::assertNotNull($vip1);
        self::assertSame('铜牌批发', $vip1['name']);
        self::assertSame('测试说明 VIP1', $vip1['description']);
        self::assertSame(12_345_00, $vip1['spend_threshold_minor']);
        self::assertSame(2_000_00, $vip1['credit_limit_minor']);
        self::assertSame('12345.00', $vip1['spend_threshold_major']);
        self::assertSame('2000.00', $vip1['credit_limit_major']);
    }

    public function testPresentRespectsCustomTierRankOrder(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->reorderGroups([
            SystemVipLadder::groupId(3),
            SystemVipLadder::groupId(0),
            SystemVipLadder::groupId(1),
        ]);

        $presenter = new WholesaleFaqVipLadderPresenter(
            $store,
            new class extends B2BBaseCurrencyResolver {
                public function forWebsite(int $websiteId): string
                {
                    return 'USD';
                }
            },
            B2BPaymentAssetPolicyProvider::forTesting(false),
        );
        $view = $presenter->present(0);
        self::assertFalse($view['credit_enabled']);
        self::assertSame('USD', $view['currency_code']);
        self::assertSame(SystemVipLadder::groupId(3), $view['groups'][0]['group_id']);
        self::assertSame(0, (int)$view['groups'][0]['tier_rank']);
    }
}
