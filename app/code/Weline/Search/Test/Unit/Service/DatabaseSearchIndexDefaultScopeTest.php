<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Model\Shard\SearchDocument;
use Weline\Search\Service\DatabaseSearchIndexStore;

final class DatabaseSearchIndexDefaultScopeTest extends TestCase
{
    public function testDefaultStoreAndChannelZeroAreValidSearchScope(): void
    {
        $store = (new \ReflectionClass(DatabaseSearchIndexStore::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($store, 'normalizeIdentity');
        $method->setAccessible(true);

        $identity = $method->invoke($store, 0, [
            'entity_type' => 'product_offer',
            'entity_id' => 'offer-1',
            'website_id' => 0,
            'website_code' => 'default',
            'store_id' => 0,
            'store_code' => 'default',
            'channel_id' => 0,
            'channel_code' => 'default',
            'locale' => '',
            'currency' => '',
        ]);

        self::assertSame(0, $identity[SearchDocument::schema_fields_STORE_ID]);
        self::assertSame(0, $identity[SearchDocument::schema_fields_CHANNEL_ID]);
    }
}
