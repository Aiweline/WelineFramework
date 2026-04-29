<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\HotKeyTracker;

class HotKeyTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HotKeyTracker::reset();
    }

    public function testKeyBecomesHotAfterReachingThreshold(): void
    {
        $tracker = new HotKeyTracker(threshold: 5, windowSeconds: 60);

        for ($i = 0; $i < 4; $i++) {
            $tracker->touch('product', 'sku-1');
        }
        $this->assertFalse($tracker->isHot('product', 'sku-1'));

        $tracker->touch('product', 'sku-1');
        $this->assertTrue($tracker->isHot('product', 'sku-1'));
        $this->assertSame(5, $tracker->getHits('product', 'sku-1'));
    }

    public function testListHotKeysReturnsHotEntriesSortedByHits(): void
    {
        $tracker = new HotKeyTracker(threshold: 3, windowSeconds: 60);

        for ($i = 0; $i < 10; $i++) {
            $tracker->touch('a', 'k1');
        }
        for ($i = 0; $i < 5; $i++) {
            $tracker->touch('b', 'k2');
        }
        $tracker->touch('c', 'cold');

        $rows = $tracker->listHotKeys();
        $this->assertCount(2, $rows);
        $this->assertSame('k1', $rows[0]['key']);
        $this->assertSame(10, $rows[0]['hits']);
        $this->assertSame('k2', $rows[1]['key']);
    }

    public function testIsolatesIdentitiesUnderSameKey(): void
    {
        $tracker = new HotKeyTracker(threshold: 3, windowSeconds: 60);

        for ($i = 0; $i < 5; $i++) {
            $tracker->touch('product', 'shared-key');
        }
        $this->assertTrue($tracker->isHot('product', 'shared-key'));
        $this->assertFalse($tracker->isHot('order', 'shared-key'));
    }
}
