<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Policy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Policy\ProcessMemoryEvictionPolicy;
use Weline\Framework\Cache\Store\ProcessMemoryStore;
use Weline\Framework\Runtime\ProcessMemoryReclaimableAdapter;

final class ProcessMemoryStoreEvictionTest extends TestCase
{
    public function testPolicySkipsPinnedAndPrefersColdHeat(): void
    {
        $victims = ProcessMemoryEvictionPolicy::selectVictims(
            [
                'hot' => ['pin' => 0, 'heat' => 90, 'bytes' => 10],
                'cold' => ['pin' => 0, 'heat' => 10, 'bytes' => 10],
                'pinned' => ['pin' => 1, 'heat' => 1, 'bytes' => 999],
            ],
            ProcessMemoryEvictionPolicy::TIER_SOFT,
            null,
            1
        );

        self::assertSame(['cold'], $victims);
    }

    public function testStoreSoftEvictsHalfButKeepsPinned(): void
    {
        $store = new ProcessMemoryStore(64);
        foreach (['a', 'b', 'c', 'd'] as $i => $k) {
            $store->set($k, (string)$i, null, 50);
        }
        $store->pin('a');
        $evicted = $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_SOFT);
        self::assertCount(2, $evicted); // ceil(3 unpinned / 2) = 2
        self::assertNotContains('a', $evicted);
        self::assertSame(2, $store->getItemCount());
        self::assertSame('0', $store->get('a'));
    }

    public function testHardEvictsAllUnpinned(): void
    {
        $store = new ProcessMemoryStore(64);
        $store->set('keep', 'x', 'fr_FR', 10);
        $store->set('drop1', 'y', 'fr_FR', 10);
        $store->set('drop2', 'z', 'de_DE', 10);
        $store->pin('keep');

        $evicted = $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_HARD);
        self::assertEqualsCanonicalizing(['drop1', 'drop2'], $evicted);
        self::assertSame(1, $store->getItemCount());
        self::assertSame('x', $store->get('keep'));
    }

    public function testBucketPinProtectsLocaleKeys(): void
    {
        $store = new ProcessMemoryStore(64);
        $store->set('w1', 'a', 'ja_JP', 10);
        $store->set('w2', 'b', 'ja_JP', 10);
        $store->set('w3', 'c', 'ko_KR', 10);
        $store->pinBucket('ja_JP');

        $evicted = $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_HARD);
        self::assertSame(['w3'], $evicted);
        self::assertEqualsCanonicalizing(['w1', 'w2'], $store->keysInBucket('ja_JP'));
    }

    public function testReclaimableAdapterCompactUsesSoftPolicy(): void
    {
        $store = new ProcessMemoryStore(64);
        for ($i = 0; $i < 8; $i++) {
            $store->set('k' . $i, \str_repeat('x', 20), null, 20);
        }
        $adapter = new ProcessMemoryReclaimableAdapter($store, 'test_store', 40);
        $before = $store->getItemCount();
        $result = $adapter->compact();
        self::assertFalse($result['skipped']);
        self::assertLessThan($before, $store->getItemCount());
        self::assertSame('test_store', $result['name']);
    }
}
