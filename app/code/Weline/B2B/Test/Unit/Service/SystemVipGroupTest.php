<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\CustomerGroup;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\CustomerGroupStore;

require_once dirname(__DIR__) . '/bootstrap.php';

final class SystemVipGroupTest extends TestCase
{
    public function testEnsureSystemVipLadderCreatesThirteenNonDeletableTiers(): void
    {
        $store = CustomerGroupStore::forTesting();
        $vip0 = $store->ensureSystemVipLadder(0);

        self::assertSame(SystemVipLadder::groupId(0), $vip0->groupId);
        self::assertSame(SystemVipLadder::code(0), $vip0->code);
        self::assertTrue($store->isSystemGroup($vip0->groupId));

        $rows = $store->listAdminRows(50);
        $ladder = array_values(array_filter(
            $rows,
            static fn(array $row): bool => SystemVipLadder::isSystemVipGroupId((string)$row['group_id']),
        ));
        self::assertCount(13, $ladder);
        self::assertSame(SystemVipLadder::groupId(0), (string)$ladder[0]['group_id']);
        self::assertSame(0, (int)$ladder[0]['tier_rank']);

        $store->updateDisplayName($vip0->groupId, '金牌VIP0');
        $rows = $store->listAdminRows();
        self::assertSame('金牌VIP0', $rows[0]['name']);
        self::assertTrue($rows[0]['is_system']);

        $options = $store->listActiveOptions();
        self::assertSame(SystemVipLadder::groupId(0), (string)$options[0]['value']);

        foreach (SystemVipLadder::seedDefinitions() as $seed) {
            try {
                $store->deleteGroup($seed['group_id']);
                self::fail('expected system group delete to be blocked: ' . $seed['group_id']);
            } catch (B2BConflictException $e) {
                self::assertSame('b2b_system_group_protected', $e->errorCode);
            }
        }
    }

    public function testLegacyVipMembershipMigratesToVip0(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->put(new CustomerGroup(
            SystemVipLadder::LEGACY_GROUP_ID,
            0,
            SystemVipLadder::LEGACY_CODE,
            CustomerGroup::STATUS_ACTIVE,
        ));
        $store->assignCustomer('c-legacy', SystemVipLadder::LEGACY_GROUP_ID);
        $store->ensureSystemVipLadder(0);

        self::assertSame(SystemVipLadder::groupId(0), $store->groupForCustomer('c-legacy', 0)?->groupId);
        self::assertNull($store->get(SystemVipLadder::LEGACY_GROUP_ID));
    }

    public function testCustomGroupCanBeDeleted(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->put(new CustomerGroup('g-custom', 0, 'custom', CustomerGroup::STATUS_ACTIVE));
        $store->updateDisplayName('g-custom', '自定义组');
        $store->deleteGroup('g-custom');
        self::assertNull($store->get('g-custom'));
    }

    public function testSpendLadderIsNaturalAndVip12AtLeastOneMillionMajor(): void
    {
        $seeds = SystemVipLadder::seedDefinitions();
        self::assertCount(13, $seeds);
        self::assertSame(0, (int)$seeds[0]['spend_threshold_minor']);
        self::assertSame(100_000_000, (int)$seeds[12]['spend_threshold_minor']);
        self::assertSame(15_000_000, (int)$seeds[12]['credit_limit_minor']);

        $prevSpend = -1;
        foreach ($seeds as $seed) {
            $spend = (int)$seed['spend_threshold_minor'];
            $credit = (int)$seed['credit_limit_minor'];
            self::assertTrue($spend > $prevSpend);
            self::assertTrue($credit <= $spend);
            $prevSpend = $spend;
        }

        self::assertTrue(SystemVipLadder::isLegacyLinearAmounts(1, 100_000, 500_000));
        self::assertFalse(SystemVipLadder::isLegacyLinearAmounts(1, 100_000, 500_001));

        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $store->updateGroupCapabilities(SystemVipLadder::groupId(12), [
            'credit_limit_minor' => 12 * 100_000,
            'spend_threshold_minor' => 12 * 500_000,
        ]);
        $store->ensureSystemVipLadder(0);
        $meta = $store->groupCreditMeta(SystemVipLadder::groupId(12));
        self::assertNotNull($meta);
        self::assertSame(100_000_000, (int)$meta['spend_threshold_minor']);
        self::assertSame(15_000_000, (int)$meta['credit_limit_minor']);
    }

    public function testCapabilitiesUpdateWithNameChangesDisplayNameAndMeta(): void
    {
        $store = CustomerGroupStore::forTesting();
        $vip0 = $store->ensureSystemVipLadder(0);

        $store->updateDisplayName($vip0->groupId, 'VIP0 改名');
        $store->updateGroupCapabilities($vip0->groupId, [
            'description' => '入门说明',
            'credit_limit_minor' => 12_345,
            'spend_threshold_minor' => 6_789,
            'status' => CustomerGroup::STATUS_ACTIVE,
        ]);

        $meta = $store->groupCreditMeta($vip0->groupId);
        self::assertNotNull($meta);
        self::assertSame('VIP0 改名', (string)$meta['name']);
        self::assertSame('入门说明', (string)$meta['description']);
        self::assertSame(12_345, (int)$meta['credit_limit_minor']);
        self::assertSame(6_789, (int)$meta['spend_threshold_minor']);
        self::assertSame(CustomerGroup::STATUS_ACTIVE, (string)$meta['status']);

        $rows = $store->listAdminRows();
        self::assertSame('VIP0 改名', (string)$rows[0]['name']);
        self::assertSame('入门说明', (string)$rows[0]['description']);
    }
}
