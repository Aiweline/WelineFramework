<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\ProductAttributeMetadataCatalog;

final class ProductAttributeMetadataCatalogTest extends TestCase
{
    private ProductAttributeMetadataCatalog $catalog;

    protected function setUp(): void
    {
        $option = new AttributeOptionMetadata(7, '7', 'red', '红色', 7);
        $attributes = [
            new AttributeMetadata(11, 3, 'color', '颜色', 'varchar', 'varchar', 'select', 5, 6, true, false, true, true, 11, [$option]),
            new AttributeMetadata(12, 3, 'weight', '重量', 'decimal', 'decimal', 'input', 5, 6, false, false, true, false, 12),
            new AttributeMetadata(13, 3, 'active', '启用', 'boolean', 'boolean', 'checkbox', 5, 6, false, false, true, false, 13),
            new AttributeMetadata(14, 3, 'tags', '标签', 'varchar', 'varchar', 'select', 5, 6, false, true, true, true, 14, [$option]),
        ];
        $set = new AttributeSetMetadata(
            5,
            3,
            'default',
            '默认',
            5,
            [new AttributeGroupMetadata(6, 3, 5, 'general', '常规', 6, $attributes)],
        );
        $metadata = new StaticProductMetadataCatalog([$set]);
        $entity = (new ReflectionClass(ProductCatalogAttributeEntity::class))
            ->newInstanceWithoutConstructor();
        $this->catalog = new ProductAttributeMetadataCatalog($metadata, $entity);
    }

    public function testEditorCatalogAddsProductValueTypesAndScopeStates(): void
    {
        $attributes = $this->catalog->editorCatalog()[0]['groups'][0]['attributes'];

        self::assertSame(['select', 'number', 'boolean', 'multiselect'], array_column($attributes, 'value_type'));
        self::assertSame(['explicit', 'cleared', 'inherit'], $attributes[0]['scope_states']);
    }

    public function testNormalizeRowsCanonicalizesKnownValuesAndPreservesUnknownRows(): void
    {
        $rows = $this->catalog->normalizeRows([
            ['attribute_code' => 'color', 'value' => 'red'],
            ['attribute_code' => 'weight', 'value' => '0'],
            ['attribute_code' => 'active', 'value' => 'yes'],
            ['attribute_code' => 'tags', 'value' => ['red', '7', 'red']],
            [
                'attribute_code' => 'legacy_payload',
                'value_type' => 'legacy_blob',
                'value' => ['keep' => true],
                'migration_conflict' => 'manual',
            ],
        ]);

        self::assertSame('7', $rows[0]['value']);
        self::assertSame(0, $rows[1]['value']);
        self::assertTrue($rows[2]['value']);
        self::assertSame(['7'], $rows[3]['value']);
        self::assertSame('legacy_blob', $rows[4]['value_type']);
        self::assertSame(['keep' => true], $rows[4]['value']);
        self::assertSame('manual', $rows[4]['migration_conflict']);
    }

    public function testClearedAndInheritRemainDistinct(): void
    {
        $rows = $this->catalog->normalizeRows([
            ['attribute_code' => 'color', 'scope_state' => 'cleared', 'value' => '7'],
            ['attribute_code' => 'weight', 'scope_state' => 'inherit', 'value' => 5],
        ]);

        self::assertNull($rows[0]['value']);
        self::assertSame('cleared', $rows[0]['scope_state']);
        self::assertSame('inherit', $rows[1]['scope_state']);
        self::assertSame(5, $rows[1]['value']);
    }

    public function testRejectsInvalidOptionAndDuplicateScopedKey(): void
    {
        try {
            $this->catalog->normalizeRows([
                ['attribute_code' => 'color', 'value' => 'blue'],
            ]);
            self::fail('Invalid option must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('product_attribute_option_invalid', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_attribute_duplicate');
        $this->catalog->normalizeRows([
            ['attribute_code' => 'weight', 'value' => 1],
            ['attribute_code' => 'weight', 'value' => 2],
        ]);
    }
}

/** @internal */
final readonly class StaticProductMetadataCatalog implements AttributeMetadataCatalogInterface
{
    /** @param list<AttributeSetMetadata> $sets */
    public function __construct(private array $sets)
    {
    }

    public function catalog(EntityDefinitionInterface $entity): array
    {
        return $this->sets;
    }

    public function attributeIndexByEntityCode(string $entityCode): array
    {
        $index = [];
        foreach ($this->sets as $set) {
            foreach ($set->groups as $group) {
                foreach ($group->attributes as $attribute) {
                    $index[$attribute->code] = $attribute;
                }
            }
        }

        return $index;
    }
}
