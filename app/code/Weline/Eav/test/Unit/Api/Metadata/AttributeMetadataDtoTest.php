<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Api\Metadata;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Metadata\AttributeGroupMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeOptionMetadata;
use Weline\Eav\Api\Metadata\AttributeSetMetadata;

final class AttributeMetadataDtoTest extends TestCase
{
    public function testHierarchySerializesAsStableImmutableArrays(): void
    {
        $option = new AttributeOptionMetadata(
            id: 7,
            value: '7',
            code: 'red',
            label: '红色',
            sortOrder: 7,
            swatchColor: '#ff0000',
        );
        $attribute = new AttributeMetadata(
            id: 11,
            entityId: 3,
            code: 'color',
            name: '颜色',
            typeCode: 'select',
            fieldType: 'varchar',
            element: 'select',
            setId: 5,
            groupId: 6,
            required: true,
            multiple: false,
            enabled: true,
            hasOption: true,
            sortOrder: 11,
            options: [$option],
        );
        $group = new AttributeGroupMetadata(6, 3, 5, 'spec', '规格', 6, [$attribute]);
        $set = new AttributeSetMetadata(5, 3, 'default', '默认', 5, [$group]);

        self::assertSame('red', $set->toArray()['groups'][0]['attributes'][0]['options'][0]['code']);
        self::assertSame('none', $set->toArray()['groups'][0]['attributes'][0]['compare_mode']);
        self::assertSame('7', $set->toArray()['groups'][0]['attributes'][0]['options'][0]['value']);
        self::assertTrue($set->toArray()['groups'][0]['attributes'][0]['required']);
        self::assertSame('#ff0000', $set->toArray()['groups'][0]['attributes'][0]['options'][0]['swatch_color']);
    }

    public function testHierarchyRejectsMutableUntypedChildren(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttributeSetMetadata(1, 2, 'default', '默认', 1, [['not' => 'a dto']]);
    }
}
