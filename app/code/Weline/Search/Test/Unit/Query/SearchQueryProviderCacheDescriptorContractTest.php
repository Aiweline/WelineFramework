<?php
declare(strict_types=1);

namespace Weline\Search\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Query\BinQueryDescriptorAttributeResolver;
use Weline\Search\Extends\Module\Weline_Framework\Query\SearchQueryProvider;

final class SearchQueryProviderCacheDescriptorContractTest extends TestCase
{
    public function testHotWordsAndTypesAreCdnCacheableWhileSearchIsNot(): void
    {
        $provider = (new ReflectionClass(SearchQueryProvider::class))->newInstanceWithoutConstructor();
        $merged = (new BinQueryDescriptorAttributeResolver())->merge($provider, $provider->getDescriptor());
        $byName = [];
        foreach (($merged['operations'] ?? []) as $operation) {
            if (\is_array($operation) && isset($operation['name'])) {
                $byName[(string)$operation['name']] = $operation;
            }
        }

        $policy = new BinQueryCachePolicy();
        self::assertArrayHasKey('hotWords', $byName);
        self::assertArrayHasKey('types', $byName);
        self::assertArrayHasKey('search', $byName);

        self::assertTrue(($byName['hotWords']['external'] ?? false) === true);
        self::assertTrue($policy->isCacheableOperation($byName['hotWords']));
        self::assertSame('5m', $byName['hotWords']['cache']['ttl'] ?? null);
        self::assertSame(
            ['area', 'locale', 'website_id', 'store_id', 'channel_id'],
            $byName['hotWords']['cache']['vary'] ?? null
        );

        self::assertTrue(($byName['types']['external'] ?? false) === true);
        self::assertTrue($policy->isCacheableOperation($byName['types']));
        self::assertSame('30m', $byName['types']['cache']['ttl'] ?? null);

        self::assertFalse($policy->isCacheableOperation($byName['search']));
    }
}
