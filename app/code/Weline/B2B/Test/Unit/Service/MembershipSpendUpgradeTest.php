<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Cron\MembershipSpendUpgrade;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BCreditGrantService;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;
use Weline\B2B\Service\CustomerGroupStore;
use Weline\B2B\Service\MembershipSpendQuery;

require_once dirname(__DIR__) . '/bootstrap.php';

final class MembershipSpendUpgradeTest extends TestCase
{
    public function testUpgradesSystemVipWhenSpendMeetsHigherTier(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->updateGroupCapabilities(SystemVipLadder::groupId(1), [
            'spend_threshold_minor' => 100,
            'credit_limit_minor' => 500,
        ]);
        $store->updateGroupCapabilities(SystemVipLadder::groupId(2), [
            'spend_threshold_minor' => 200,
            'credit_limit_minor' => 800,
        ]);
        $store->assignCustomer('c-upgrade', SystemVipLadder::groupId(0));

        $spend = MembershipSpendQuery::forTesting([
            [
                'customer_id' => 'c-upgrade',
                'website_id' => 0,
                'order_type' => 'tob',
                'payment_status' => 'paid',
                'status' => 'paid',
                'money_snapshot_json' => json_encode(['goods_subtotal_taxed_minor' => 250]),
            ],
        ]);
        $ledger = new FakeCustomerAssetFacade();
        $grant = B2BCreditGrantService::forTesting(
            $store,
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $ledger,
        );
        $cron = new MembershipSpendUpgrade();
        $result = $cron->runUpgrade(
            $store,
            $spend,
            $grant,
            B2BPaymentAssetPolicyProvider::forTesting(true),
        );

        self::assertSame(1, $result['upgraded']);
        self::assertSame(
            SystemVipLadder::groupId(2),
            $store->groupForCustomer('c-upgrade', 0)?->groupId,
        );
        self::assertSame(800, $ledger->balance);
    }

    public function testSkipsWhenCreditDisabled(): void
    {
        $cron = new MembershipSpendUpgrade();
        $result = $cron->runUpgrade(
            CustomerGroupStore::forTesting(),
            MembershipSpendQuery::forTesting(),
            B2BCreditGrantService::forTesting(
                CustomerGroupStore::forTesting(),
                B2BPaymentAssetPolicyProvider::forTesting(false),
            ),
            B2BPaymentAssetPolicyProvider::forTesting(false),
        );
        self::assertSame('credit_disabled', $result['reason'] ?? null);
    }

    public function testCustomGroupMembershipIsIgnored(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->put(new \Weline\B2B\Model\CustomerGroup('g-custom', 0, 'custom'));
        $store->assignCustomer('c-custom', 'g-custom');
        $cron = new MembershipSpendUpgrade();
        $result = $cron->runUpgrade(
            $store,
            MembershipSpendQuery::forTesting([
                [
                    'customer_id' => 'c-custom',
                    'website_id' => 0,
                    'order_type' => 'tob',
                    'payment_status' => 'paid',
                    'status' => 'paid',
                    'money_snapshot_json' => json_encode(['goods_subtotal_taxed_minor' => 999999]),
                ],
            ]),
            B2BCreditGrantService::forTesting(
                $store,
                B2BPaymentAssetPolicyProvider::forTesting(true),
                new FakeCustomerAssetFacade(),
            ),
            B2BPaymentAssetPolicyProvider::forTesting(true),
        );
        self::assertSame(0, $result['upgraded']);
        self::assertSame('g-custom', $store->groupForCustomer('c-custom', 0)?->groupId);
    }
}
