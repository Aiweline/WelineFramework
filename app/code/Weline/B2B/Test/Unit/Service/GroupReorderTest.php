<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\CustomerGroupStore;

require_once dirname(__DIR__) . '/bootstrap.php';

final class GroupReorderTest extends TestCase
{
    public function testReorderGroupsRewritesTierRanksInOrder(): void
    {
        $store = CustomerGroupStore::forTesting();
        $store->ensureSystemVipLadder(0);
        $rows = $store->listAdminRows(20);
        self::assertGreaterThanOrEqual(3, count($rows));
        self::assertSame(0, (int)$rows[0]['tier_rank']);

        $ids = array_map(static fn (array $r): string => (string)$r['group_id'], $rows);
        $reversed = array_reverse($ids);
        $ordered = $store->reorderGroups($reversed);
        self::assertSame($reversed[0], $ordered[0]['group_id']);
        self::assertSame(0, $ordered[0]['tier_rank']);

        $after = $store->listAdminRows(20);
        self::assertSame($reversed[0], $after[0]['group_id']);
        self::assertSame(0, (int)$after[0]['tier_rank']);
        self::assertSame($reversed[1], $after[1]['group_id']);
        self::assertSame(1, (int)$after[1]['tier_rank']);
    }

    public function testUpdateCapabilitiesAcceptsTierRank(): void
    {
        $store = CustomerGroupStore::forTesting();
        $vip0 = $store->ensureSystemVipLadder(0);
        $store->updateGroupCapabilities($vip0->groupId, ['tier_rank' => 42]);
        $meta = $store->groupCreditMeta($vip0->groupId);
        self::assertSame(42, (int)($meta['tier_rank'] ?? -1));
    }
}
