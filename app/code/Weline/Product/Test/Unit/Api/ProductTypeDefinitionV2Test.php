<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\DefaultProductProvider;
use Weline\Product\Service\Provider\BuiltInProductProviderCatalog;

final class ProductTypeDefinitionV2Test extends TestCase
{
    public function testFiveBuiltInTypesExposeImmutableDefinitions(): void
    {
        $providers = array_merge([new DefaultProductProvider()], BuiltInProductProviderCatalog::additionalProviders());
        $types = [];
        foreach ($providers as $provider) {
            $definition = $provider->getDefinition();
            $types[$definition->code] = $definition->toArray();
        }

        self::assertSame(
            ['simple', 'configurable', 'virtual', 'downloadable', 'bundle'],
            array_keys($types),
        );
        self::assertSame(1, $types['simple']['offer_cardinality']['maximum']);
        self::assertTrue($types['configurable']['capabilities']['variants']);
        self::assertFalse($types['virtual']['capabilities']['shipping']);
        self::assertTrue($types['downloadable']['capabilities']['digital_delivery']);
        self::assertTrue($types['bundle']['capabilities']['composition']);
    }
}
