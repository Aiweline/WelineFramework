<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service\LocalTranslation;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Service\LocalTranslation\EavLocalTranslationRegistry;

final class EavLocalTranslationRegistryTest extends TestCase
{
    /**
     * @dataProvider nodeTypesProvider
     */
    public function testResolveUsesLocalDescriptionIdColumn(string $nodeType): void
    {
        $descriptor = EavLocalTranslationRegistry::resolve($nodeType);
        $modelClass = (string)$descriptor['model'];

        self::assertSame('id', $descriptor['id_field']);
        self::assertSame('id', $modelClass::schema_fields_ID);
    }

    /**
     * @return list<array{0:string}>
     */
    public static function nodeTypesProvider(): array
    {
        return array_map(
            static fn(string $type): array => [$type],
            EavLocalTranslationRegistry::translatableNodeTypes(),
        );
    }
}
