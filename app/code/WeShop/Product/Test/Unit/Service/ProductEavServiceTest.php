<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Service\ProductEavService;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavEntity;

class ProductEavServiceTest extends TestCase
{
    public function testGetAttributeGroupsReturnsFetchArrayRows(): void
    {
        $groupRows = [
            [
                Group::schema_fields_ID => 3,
                Group::schema_fields_name => '默认属性组',
                Group::schema_fields_set_id => 3,
            ],
        ];

        $attributeGroup = new class($groupRows) extends Group {
            public function __construct(private readonly array $rows)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function order(...$args): static
            {
                return $this;
            }

            public function select($columns = [], bool $to_fields = false): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return $this->rows;
            }
        };

        $service = new ProductEavService(
            $this->createMock(EavEntity::class),
            $this->createMock(EavAttribute::class),
            $this->createMock(Set::class),
            $attributeGroup,
            $this->createMock(Option::class)
        );

        $this->assertSame($groupRows, $service->getAttributeGroups(3));
    }

    public function testGetAttributeOptionsReturnsFetchArrayRows(): void
    {
        $optionRows = [
            [
                Option::fields_option_id => 11,
                Option::fields_code => 'apple',
                Option::fields_value => 'Apple',
                Option::fields_swatch_color => '#000000',
                Option::fields_swatch_image => null,
                Option::fields_swatch_text => 'A',
            ],
        ];

        $optionModel = new class($optionRows) extends Option {
            public function __construct(private readonly array $rows)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function select($columns = [], bool $to_fields = false): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return $this->rows;
            }
        };

        $service = new ProductEavService(
            $this->createMock(EavEntity::class),
            $this->createMock(EavAttribute::class),
            $this->createMock(Set::class),
            $this->createMock(Group::class),
            $optionModel
        );

        $options = $service->getAttributeOptions(8);

        $this->assertCount(1, $options);
        $this->assertSame(11, $options[0]['option_id']);
        $this->assertSame('Apple', $options[0]['value']);
        $this->assertTrue($options[0]['is_swatch']);
    }

    public function testGetSearchIndexDataBuildsSearchTextAndFacetEntries(): void
    {
        $service = $this->getMockBuilder(ProductEavService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductAttributes'])
            ->getMock();

        $service->expects($this->once())
            ->method('getProductAttributes')
            ->with(2, null)
            ->willReturn([
                [
                    'group_id' => 3,
                    'group_name' => '默认属性组',
                    'attributes' => [
                        [
                            'attribute_id' => 8,
                            'code' => 'brand',
                            'name' => '品牌',
                            'frontend_is_searchable' => true,
                            'frontend_is_filterable' => true,
                            'has_option' => true,
                            'multiple_valued' => false,
                            'is_swatch' => false,
                            'display_value' => 'Apple',
                            'value' => '11',
                            'selected_options' => [
                                [
                                    'selected' => 1,
                                    'option_id' => 11,
                                    'value' => 'Apple',
                                    'swatch_text' => 'A',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $indexData = $service->getSearchIndexData(2);

        $this->assertSame(['品牌 Apple'], $indexData['eav_search_text']);
        $this->assertCount(1, $indexData['eav_facets']);
        $this->assertSame('brand', $indexData['eav_facets'][0]['attribute_code']);
        $this->assertSame('11', $indexData['eav_facets'][0]['value_keyword']);
        $this->assertSame('Apple', $indexData['eav_facets'][0]['value_text']);
    }
}
