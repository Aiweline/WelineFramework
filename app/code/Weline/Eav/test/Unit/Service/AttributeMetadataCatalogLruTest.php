<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Eav\Service\AttributeMetadataCatalog;

/**
 * Ch4：AttributeMetadataCatalog 进程袋淘汰由 FIFO 改为 LRU。
 */
final class AttributeMetadataCatalogLruTest extends TestCase
{
    private ReflectionProperty $bag;
    private ReflectionMethod $remember;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(AttributeMetadataCatalog::class);
        $this->bag = $reflection->getProperty('processBag');
        $this->bag->setAccessible(true);
        $this->bag->setValue(null, []);
        $this->remember = $reflection->getMethod('rememberRequest');
        $this->remember->setAccessible(true);
    }

    protected function tearDown(): void
    {
        $this->bag->setValue(null, []);
        AttributeMetadataCatalog::clearProcessCache();
    }

    public function testCacheHitMovesEntryToTail(): void
    {
        $this->bag->setValue(null, ['a' => 1, 'b' => 2, 'c' => 3]);
        $catalog = (new ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();

        $value = $this->remember->invoke(
            $catalog,
            'a',
            static function (): never {
                throw new \RuntimeException('cache hit must not rebuild');
            },
        );

        self::assertSame(1, $value);
        self::assertSame(['b', 'c', 'a'], \array_keys($this->bag->getValue()));
    }

    public function testFullBagEvictsLeastRecentlyUsedNotOldestInserted(): void
    {
        $max = 256;
        $seed = [];
        for ($i = 0; $i < $max; $i++) {
            $seed['k' . $i] = $i;
        }
        $this->bag->setValue(null, $seed);
        $catalog = (new ReflectionClass(AttributeMetadataCatalog::class))->newInstanceWithoutConstructor();

        // k0 刚被访问，成为最近使用；插入新键时应淘汰 k1（最久未使用），而非 k0。
        $this->remember->invoke($catalog, 'k0', static fn(): int => 0);
        $this->remember->invoke($catalog, 'fresh', static fn(): string => 'fresh');

        $keys = \array_keys($this->bag->getValue());
        self::assertContains('k0', $keys);
        self::assertNotContains('k1', $keys);
        self::assertContains('fresh', $keys);
        self::assertCount($max, $keys);
    }
}
