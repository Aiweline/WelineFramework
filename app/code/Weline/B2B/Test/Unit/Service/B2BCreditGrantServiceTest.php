<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BCreditGrantService;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;
use Weline\B2B\Service\CustomerGroupStore;

require_once dirname(__DIR__) . '/bootstrap.php';

final class B2BCreditGrantServiceTest extends TestCase
{
    public function testTopUpIsIdempotentAndNeverClawsBack(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->updateGroupCapabilities(SystemVipLadder::groupId(1), [
            'credit_limit_minor' => 1000,
        ]);
        $groupId = SystemVipLadder::groupId(1);
        $ledger = new FakeCustomerAssetFacade();

        $service = B2BCreditGrantService::forTesting(
            $store,
            B2BPaymentAssetPolicyProvider::forTesting(true),
            $ledger,
        );

        $first = $service->grantToTarget('c1', 0, $groupId);
        self::assertTrue($first['ok']);
        self::assertSame(1000, $first['granted_minor']);
        self::assertSame(1000, $ledger->balance);

        $second = $service->grantToTarget('c1', 0, $groupId);
        self::assertSame(0, $second['granted_minor']);
        self::assertSame('already_at_or_above_target', $second['skipped']);
        self::assertSame(1000, $ledger->balance);

        $ledger->balance = 1500;
        $third = $service->grantToTarget('c1', 0, $groupId);
        self::assertSame(0, $third['granted_minor']);
        self::assertSame(1500, $ledger->balance);
    }

    public function testDisabledSwitchSkipsGrant(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $service = B2BCreditGrantService::forTesting(
            $store,
            B2BPaymentAssetPolicyProvider::forTesting(false),
            null,
        );
        $result = $service->grantToTarget('c1', 0, SystemVipLadder::groupId(0));
        self::assertSame('credit_disabled', $result['skipped']);
    }
}
