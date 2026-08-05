<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Product\Extends\Module\Weline_Framework\Schema\ProductShardSchemaProvider;
use Weline\Product\Model\ProductShardKey;

final class ProductShardProviderDiscoveryTest extends TestCase
{
    public function testCompiledExtendsRegistryDiscoversProductWebsiteFamily(): void
    {
        $provider = (new ShardSchemaFamilyProviderRegistry())->get(ProductShardKey::FAMILY_CODE);

        self::assertInstanceOf(ProductShardSchemaProvider::class, $provider);
        self::assertSame(ProductShardKey::FAMILY_CODE, $provider->getFamilyCode());
    }
}
