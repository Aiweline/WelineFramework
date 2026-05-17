<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type\Value as AttributeValueModel;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Event\EventsManager;

class AttributeFilterServiceTest extends TestCase
{
    public function testBuildAttributeDataWithValuesSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())->method('getId')->willReturn(0);
        $attribute->expects(self::never())->method('w_getValueModel');

        $result = $this->invokePrivate($service, 'buildAttributeDataWithValues', [[$attribute], [1, 2, 3]]);

        self::assertSame([], $result);
    }

    public function testBuildAttributeMetadataResultSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())->method('getId')->willReturn(0);
        $attribute->expects(self::never())->method('getCode');

        $result = $this->invokePrivate($service, 'buildAttributeMetadataResult', [[$attribute]]);

        self::assertSame([], $result);
    }

    public function testMapAttributePrefersRealAttributeIdFieldOverCompositePrimaryId(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())
            ->method('getData')
            ->with(EavAttribute::schema_fields_attribute_id)
            ->willReturn(8);
        $attribute->expects(self::never())->method('getId');
        $attribute->method('getTypeModel')->willThrowException(new \RuntimeException('type not needed'));
        $attribute->method('getCode')->willReturn('brand');
        $attribute->method('getName')->willReturn('Brand');
        $attribute->method('getTypeId')->willReturn(5);
        $attribute->method('getSetId')->willReturn(1);
        $attribute->method('getGroupId')->willReturn(2);
        $attribute->method('isVisibleOnFront')->willReturn(true);
        $attribute->method('isFilterable')->willReturn(true);
        $attribute->method('isSearchable')->willReturn(true);
        $attribute->method('hasOption')->willReturn(true);
        $attribute->method('getMultipleValued')->willReturn(false);

        $result = $this->invokePrivate($service, 'mapAttribute', [$attribute]);

        self::assertSame(8, $result['attribute_id']);
        self::assertSame('brand', $result['code']);
    }

    public function testGetAttributeValuesWithCountsUsesAttributeIdFieldBeforeCompositePrimaryId(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $valueModel = new class extends AttributeValueModel {
            public array $whereCalls = [];

            public function reset(): static
            {
                return $this;
            }

            public function fields(string|array $fields): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->whereCalls[] = [$field, $value, $condition, $where_logic, $array_where_logic_type];

                return $this;
            }

            public function group(string $fields): static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return [
                    ['value' => '42', 'count' => 2],
                ];
            }
        };

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())
            ->method('getData')
            ->with(EavAttribute::schema_fields_attribute_id)
            ->willReturn(8);
        $attribute->expects(self::never())->method('getId');
        $attribute->expects(self::once())->method('w_getValueModel')->willReturn($valueModel);

        $result = $this->invokePrivate($service, 'getAttributeValuesWithCounts', [$attribute, [2, 3]]);

        self::assertSame(['attribute_id', 8, '=', 'AND', 'AND'], $valueModel->whereCalls[0]);
        self::assertSame(['42'], $result['values']);
        self::assertSame(['42' => 2], $result['counts']);
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
