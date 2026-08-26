<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Compare\Service\CompareNumericValueParser;
use Weline\Compare\Service\CompareSpecificationMatrix;
use Weline\Eav\Api\Metadata\AttributeMetadata;
use Weline\Eav\Api\Metadata\AttributeMetadataCatalogInterface;
use Weline\Eav\Api\Metadata\CompareMode;

final class CompareSpecificationMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testBuildRowsUnionsSpecificationsAcrossProducts(): void
    {
        $matrix = $this->matrixWithCatalog();
        $rows = $matrix->buildRows([
            [
                'product_id' => 1,
                'specifications' => [
                    ['code' => 'brand', 'value' => 'Xiaomi'],
                    ['code' => 'unlock_methods', 'value' => '指纹'],
                ],
            ],
            [
                'product_id' => 2,
                'specifications' => [
                    ['code' => 'material', 'value' => '羊毛'],
                    ['code' => 'brand', 'value' => '青山美宿'],
                ],
            ],
        ]);

        self::assertCount(3, $rows);
        self::assertSame('brand', $rows[0]['code']);
        self::assertSame(['Xiaomi', '青山美宿'], $rows[0]['values']);
        self::assertTrue($rows[0]['differs']);
        self::assertSame(CompareMode::NONE, $rows[0]['compare_mode']);
        self::assertSame('material', $rows[1]['code']);
        self::assertSame(['', '羊毛'], $rows[1]['values']);
        self::assertTrue($rows[1]['differs']);
        self::assertSame('unlock_methods', $rows[2]['code']);
        self::assertSame(['指纹', ''], $rows[2]['values']);
        self::assertTrue($rows[2]['differs']);
    }

    public function testBuildRowsUsesCatalogLabelsAndCompareMode(): void
    {
        $matrix = $this->matrixWithCatalog([
            'warranty_months' => $this->metadata('warranty_months', '质保(月)', CompareMode::HIGHER_BETTER),
            'brand' => $this->metadata('brand', '品牌', CompareMode::NONE),
        ]);
        $rows = $matrix->buildRows([
            [
                'product_id' => 1,
                'specifications' => [
                    ['code' => 'warranty_months', 'value' => '12'],
                    ['code' => 'brand', 'value' => 'A'],
                ],
            ],
            [
                'product_id' => 2,
                'specifications' => [
                    ['code' => 'warranty_months', 'value' => '24'],
                    ['code' => 'brand', 'value' => 'B'],
                ],
            ],
        ]);

        self::assertSame('质保(月)', $this->rowByCode($rows, 'warranty_months')['label']);
        self::assertSame(CompareMode::HIGHER_BETTER, $this->rowByCode($rows, 'warranty_months')['compare_mode']);
        self::assertSame('品牌', $this->rowByCode($rows, 'brand')['label']);
        self::assertSame(CompareMode::NONE, $this->rowByCode($rows, 'brand')['compare_mode']);
    }

    public function testHigherBetterHighlightsBestNumericCell(): void
    {
        $matrix = $this->matrixWithCatalog([
            'warranty_months' => $this->metadata('warranty_months', '质保(月)', CompareMode::HIGHER_BETTER),
        ]);
        $row = [
            'values' => ['12', '24'],
            'compare_mode' => CompareMode::HIGHER_BETTER,
        ];

        self::assertSame('', trim($matrix->specCellHighlightClass(0, $row)));
        self::assertSame('storefront-compare__cell--best-price', trim($matrix->specCellHighlightClass(1, $row)));
    }

    public function testLowerBetterHighlightsBestNumericCell(): void
    {
        $matrix = $this->matrixWithCatalog([
            'weight_kg' => $this->metadata('weight_kg', '重量', CompareMode::LOWER_BETTER),
        ]);
        $row = [
            'values' => ['2.1kg', '1.8kg'],
            'compare_mode' => CompareMode::LOWER_BETTER,
        ];

        self::assertSame('storefront-compare__cell--best-price', trim($matrix->specCellHighlightClass(1, $row)));
    }

    public function testNoneCompareModeSkipsHighlight(): void
    {
        $matrix = $this->matrixWithCatalog([
            'brand' => $this->metadata('brand', '品牌', CompareMode::NONE),
        ]);
        $row = [
            'values' => ['A', 'B'],
            'compare_mode' => CompareMode::NONE,
        ];

        self::assertSame('', trim($matrix->specLabelHighlightClass($row)));
        self::assertSame('', trim($matrix->specCellHighlightClass(0, $row)));
        self::assertSame('', trim($matrix->specCellHighlightClass(1, $row)));
    }

    public function testNumericCompareModeFallsBackToDiffWhenUnparseable(): void
    {
        $matrix = $this->matrixWithCatalog([
            'material' => $this->metadata('material', '材质', CompareMode::HIGHER_BETTER),
        ]);
        $row = [
            'values' => ['羊毛', '棉'],
            'compare_mode' => CompareMode::HIGHER_BETTER,
        ];

        self::assertSame('storefront-compare__label--hit', trim($matrix->specLabelHighlightClass($row)));
        self::assertSame('storefront-compare__cell--hit', trim($matrix->specCellHighlightClass(0, $row)));
    }

    public function testRowDiffersTreatsMatchingValuesAsSame(): void
    {
        $matrix = $this->matrixWithCatalog();

        self::assertFalse($matrix->rowDiffers(['CNY 99.00', 'CNY 99.00', 'CNY 99.00']));
        self::assertTrue($matrix->rowDiffers(['CNY 99.00', 'CNY 129.00']));
        self::assertFalse($matrix->rowDiffers(['—', '', '—']));
    }

    public function testCellHighlightMarksOnlyFilledValuesInDiffRow(): void
    {
        $matrix = $this->matrixWithCatalog();
        $values = ['', '', '', '4G'];

        self::assertTrue($matrix->labelIsHighlight($values));
        self::assertFalse($matrix->cellIsHighlight('', $values));
        self::assertTrue($matrix->cellIsHighlight('4G', $values));
    }

    public function testCellHighlightSkipsRowsWithoutDiff(): void
    {
        $matrix = $this->matrixWithCatalog();
        $values = ['simple', 'simple', 'simple'];

        self::assertFalse($matrix->labelIsHighlight($values));
        self::assertFalse($matrix->cellIsHighlight('simple', $values));
    }

    public function testPriceHighlightMarksOnlyLowestComparablePrice(): void
    {
        $matrix = $this->matrixWithCatalog();
        $items = [
            ['product_id' => 1, 'price' => 1299.0, 'formatted_price' => 'CNY 1,299.00'],
            ['product_id' => 2, 'price' => 699.0, 'formatted_price' => 'CNY 699.00'],
            ['product_id' => 3, 'price' => 899.0, 'formatted_price' => 'CNY 899.00'],
        ];

        self::assertSame([1], $matrix->lowestPriceIndices($items));
        self::assertFalse($matrix->priceCellIsHighlight(0, $items));
        self::assertTrue($matrix->priceCellIsHighlight(1, $items));
        self::assertFalse($matrix->priceCellIsHighlight(2, $items));
    }

    public function testPriceHighlightSkipsEqualPrices(): void
    {
        $matrix = $this->matrixWithCatalog();
        $items = [
            ['product_id' => 1, 'price' => 99.0],
            ['product_id' => 2, 'price' => 99.0],
        ];

        self::assertSame([], $matrix->lowestPriceIndices($items));
        self::assertFalse($matrix->priceCellIsHighlight(0, $items));
        self::assertFalse($matrix->priceCellIsHighlight(1, $items));
    }

    public function testPriceHighlightSupportsTiedLowestPrices(): void
    {
        $matrix = $this->matrixWithCatalog();
        $items = [
            ['product_id' => 1, 'price' => 599.0],
            ['product_id' => 2, 'price' => 399.0],
            ['product_id' => 3, 'price' => 399.0],
        ];

        self::assertSame([1, 2], $matrix->lowestPriceIndices($items));
        self::assertFalse($matrix->priceCellIsHighlight(0, $items));
        self::assertTrue($matrix->priceCellIsHighlight(1, $items));
        self::assertTrue($matrix->priceCellIsHighlight(2, $items));
    }

    public function testBuildRowsSupportsLegacyAttributesMap(): void
    {
        $matrix = $this->matrixWithCatalog();
        $rows = $matrix->buildRows([
            [
                'product_id' => 9,
                'attributes' => [
                    'dimensions' => '120x180cm',
                ],
            ],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('dimensions', $rows[0]['code']);
        self::assertSame(['120x180cm'], $rows[0]['values']);
        self::assertFalse($rows[0]['differs']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowByCode(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (($row['code'] ?? '') === $code) {
                return $row;
            }
        }

        self::fail('Missing row: ' . $code);
    }

    /**
     * @param array<string, AttributeMetadata> $index
     */
    private function matrixWithCatalog(array $index = []): CompareSpecificationMatrix
    {
        $catalog = $this->createMock(AttributeMetadataCatalogInterface::class);
        $catalog->method('attributeIndexByEntityCode')
            ->with(CompareSpecificationMatrix::PRODUCT_ENTITY_CODE)
            ->willReturn($index);

        return new CompareSpecificationMatrix($catalog);
    }

    private function metadata(string $code, string $name, string $compareMode): AttributeMetadata
    {
        return new AttributeMetadata(
            id: 1,
            entityId: 1,
            code: $code,
            name: $name,
            typeCode: 'varchar',
            fieldType: 'varchar',
            element: 'input',
            setId: 1,
            groupId: 1,
            required: false,
            multiple: false,
            enabled: true,
            hasOption: false,
            sortOrder: 0,
            compareMode: $compareMode,
        );
    }
}
