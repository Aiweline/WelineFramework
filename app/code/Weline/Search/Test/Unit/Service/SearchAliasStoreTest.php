<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\SearchAliasStore;

final class SearchAliasStoreTest extends TestCase
{
    public function testMissingRowSoftDefaultsToDirectWithoutPersisting(): void
    {
        $store = SearchAliasStore::forTesting();

        self::assertTrue($store->isMissingDefault(0));
        self::assertSame(
            [
                'website_id' => 0,
                'alias' => SearchAliasStore::ALIAS_DIRECT,
                'generation' => 0,
                'version' => 0,
            ],
            $store->state(0),
        );
        self::assertTrue($store->isMissingDefault(0));
    }

    public function testCasFromMissingDefaultCreatesDurableIndexAlias(): void
    {
        $store = SearchAliasStore::forTesting();
        $cas = $store->compareAndSwap(
            0,
            SearchAliasStore::ALIAS_DIRECT,
            0,
            0,
            SearchAliasStore::ALIAS_INDEX,
            18,
        );

        self::assertTrue($cas['ok']);
        self::assertSame(SearchAliasStore::ALIAS_INDEX, $cas['alias']);
        self::assertSame(18, $cas['generation']);
        self::assertSame(1, $cas['version']);
        self::assertFalse($store->isMissingDefault(0));
    }
}
