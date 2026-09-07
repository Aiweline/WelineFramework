<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;

final class Import1688HanfuCatalogContractTest extends TestCase
{
    public function testImporterPersistsLocalizedPublicDetailWithoutSyntheticDescription(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php',
        );

        self::assertStringContainsString('->enrichDescriptions($collected, $classifier)', $script);
        self::assertStringContainsString('accepted_brand_enrichment', $script);
        self::assertStringContainsString("'mobile' => 'https://m.1688.com/offer/'", $script);
        self::assertStringContainsString("'desktop' => 'https://detail.1688.com/offer/'", $script);
        self::assertStringContainsString('parseBrandName($brandHtml)', $script);
        self::assertStringContainsString('source_brand_surface', $script);
        self::assertStringContainsString('source_brand_checked', $script);
        self::assertStringContainsString('detail_image_urls', $script);
        self::assertStringContainsString('$descriptionParser->localizeDescription(', $script);
        self::assertStringContainsString("'description' => \$descriptionHtml", $script);
        self::assertStringContainsString('accepted_description_enrichment', $script);
        self::assertStringNotContainsString('$descriptionLines', $script);
    }

    public function testImporterCanPublishEveryAcceptedHanfuProduct(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php');
        self::assertIsString($script);

        self::assertStringContainsString("'publish-all'", $script);
        self::assertStringContainsString('foreach ($publishTargets as $publishTarget)', $script);
        self::assertStringContainsString("'product_publications'", $script);
    }

    public function testImporterUsesFormalEavVariantsAndSemanticPublicIdentity(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php',
        );
        $command = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/ProductAdminCommandService.php',
        );
        $read = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/ProductAdminReadService.php',
        );
        $validation = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Provider/BuiltInProductValidation.php',
        );
        $seed = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/ProductConfigurableMatrixSeedService.php',
        );
        $bootstrap = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/ProductCatalogEavBootstrap.php',
        );

        self::assertStringContainsString("'product_type' => 'configurable'", $script);
        self::assertStringContainsString('$eavMapper->catalog($offer, $sku)', $script);
        self::assertStringContainsString('ensureHanfuAttributeOptions', $script);
        self::assertStringContainsString('hanfu1688NormalizeDetailUrl', $script);
        self::assertStringContainsString("['accepted'] ?? false", $script);
        self::assertStringContainsString('hanfu1688CanonicalVariantCatalog', $script);
        self::assertStringContainsString('$attributeMetadata->canonicalizeVariantAxes', $script);
        self::assertStringContainsString('$attributeMetadata->canonicalizeVariantCombination', $script);
        self::assertStringContainsString("'matrix_rows'", $script);
        $canonicalizePosition = strpos($script, '$catalog = hanfu1688CanonicalVariantCatalog(');
        $existingLookupPosition = strpos($script, '$existingByKey = []');
        self::assertNotFalse($canonicalizePosition);
        self::assertNotFalse($existingLookupPosition);
        self::assertLessThan($existingLookupPosition, $canonicalizePosition);
        self::assertStringContainsString('normalizeOptionLabel', $bootstrap);
        self::assertStringContainsString('$existingOptions = $option->clearData()', $bootstrap);
        self::assertStringContainsString(
            "->order('main_table.' . Option::schema_fields_option_id)",
            $bootstrap,
        );
        self::assertStringContainsString('findEntityIdsByAttributeValue', $script);
        self::assertStringContainsString("'meta_name' => \$identityData['meta_name']", $script);
        self::assertStringContainsString("'meta_description' => \$identityData['meta_description']", $script);
        self::assertStringContainsString("'meta_keywords' => \$identityData['meta_keywords']", $script);
        self::assertStringContainsString(
            "\$skuPrefix = \$brandSku . '-' . strtoupper(\$semanticHash)",
            $script,
        );
        self::assertStringContainsString('compactSkuToken', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Sample/Hanfu1688/OfferEavMapper.php',
        ));
        self::assertStringContainsString("foreach (['', 'zh_Hans_CN'] as \$locale)", $script);
        self::assertStringContainsString("'brand' => trim(\$brandName)", $script);
        self::assertStringContainsString("'brand_code' => trim(\$brandCode)", $script);
        self::assertStringContainsString("'attributes' => array_merge(", $script);
        self::assertStringNotContainsString("'product_type' => 'simple'", $script);
        self::assertStringNotContainsString("'slug' => 'hanfu-1688-'", $script);
        self::assertStringNotContainsString('new PublicInventoryResolver', $script);
        self::assertStringContainsString("'role' => 'variant'", (string)file_get_contents(
            dirname(__DIR__, 4) . '/Sample/Hanfu1688/MediaImporter.php',
        ));
        self::assertStringContainsString('schema_fields_COMBINATION_KEY', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Model/Shard/Media.php',
        ));
        self::assertStringNotContainsString('variant_media_json', $script);
        self::assertStringNotContainsString('variant_images_json', $script);

        self::assertStringContainsString('writeProductAxisValues', $command);
        self::assertStringContainsString('writeOfferAxisValues', $command);
        self::assertStringContainsString('$this->attributeMetadata->canonicalizeVariantAxes', $command);
        self::assertStringContainsString('$this->attributeMetadata->canonicalizeVariantCombination', $command);
        self::assertStringContainsString('$this->attributeMetadata->canonicalizeVariantAxes', $seed);
        self::assertStringContainsString('$this->attributeMetadata->canonicalizeVariantCombination', $seed);
        self::assertStringContainsString('variant_configuration_must_use_eav', $command);
        self::assertStringContainsString("['disabled', 'archived']", $command);
        self::assertStringContainsString('continue;', substr(
            $command,
            (int)strpos($command, 'private function publish('),
            (int)strpos($command, 'private function transition(')
                - (int)strpos($command, 'private function publish('),
        ));
        self::assertStringNotContainsString(
            "'type_config_json' => \$this->json(['combination'",
            $command,
        );
        self::assertStringNotContainsString(
            "'type_config_json' => json_encode(['combination'",
            $seed,
        );
        self::assertStringContainsString("explode('|', \$key)", $read);
        self::assertStringContainsString("explode('|', \$key)", $validation);
        self::assertStringContainsString("'xxl', 'label' => 'XXL'", $bootstrap);
        self::assertStringContainsString("'xxxl', 'label' => 'XXXL'", $bootstrap);
        self::assertStringNotContainsString("'code' => 'available_colors'", $bootstrap);
        self::assertStringNotContainsString("'code' => 'available_sizes'", $bootstrap);
        self::assertStringNotContainsString("'code' => 'source_public_specs'", $bootstrap);
        self::assertStringNotContainsString("'code' => 'source_variant_combinations'", $bootstrap);
    }

    public function testMediaImporterCapacityCoversCompleteSellerDetailSet(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Sample/Hanfu1688/MediaImporter.php',
        );

        self::assertStringContainsString('private const MAX_IMAGES = 128;', $source);
        self::assertStringNotContainsString('private const MAX_IMAGES = 64;', $source);
    }

    public function testImporterAuditsExplicitlySkippedSellerDetailMedia(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php');
        self::assertStringContainsString("\$skippedDetailMedia = is_array(\$media['skipped_detail_media'] ?? null)", $script);
        self::assertStringContainsString("'skipped_detail_media_count' => count(\$skippedDetailMedia)", $script);
        self::assertStringContainsString('$skippedDetailUrls,', $script);
    }

    public function testProtectedLegacyProductDoesNotBlockConfigurableSiblingSelection(): void
    {
        if (!defined('WELINE_HANFU_IMPORT_HELPERS_ONLY')) {
            define('WELINE_HANFU_IMPORT_HELPERS_ONLY', true);
        }
        require_once dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php';

        $legacy = new class {
            public function getData(string $key): mixed
            {
                return $key === 'product_type' ? 'simple' : null;
            }

            public function getId(): int
            {
                return 47;
            }
        };
        $configurable = new class {
            public function getData(string $key): mixed
            {
                return $key === 'product_type' ? 'configurable' : null;
            }

            public function getId(): int
            {
                return 85;
            }
        };

        $firstImport = hanfu1688SelectExistingProduct([$legacy], null, $legacy);
        self::assertNull($firstImport['existing']);
        self::assertSame([47], $firstImport['preserved_legacy_product_ids']);

        $repeatImport = hanfu1688SelectExistingProduct([$legacy, $configurable], null, $legacy);
        self::assertSame($configurable, $repeatImport['existing']);
        self::assertSame([47], $repeatImport['preserved_legacy_product_ids']);
    }

    public function testOfferBrandIdentityPrefersVerifiedSourceBrandAndKeepsFallback(): void
    {
        if (!defined('WELINE_HANFU_IMPORT_HELPERS_ONLY')) {
            define('WELINE_HANFU_IMPORT_HELPERS_ONLY', true);
        }
        require_once dirname(__DIR__, 4) . '/scripts/import-1688-hanfu-catalog.php';
        self::assertSame(
            ['code' => 'menghuihantang', 'name' => '梦绘汉唐', 'source' => true],
            hanfu1688OfferBrandIdentity(
                ['source_brand_name' => '梦绘汉唐', 'specifications' => []],
                'zhizaosi',
                '织造司',
            ),
        );
        self::assertSame(
            ['code' => 'zhizaosi', 'name' => '织造司', 'source' => false],
            hanfu1688OfferBrandIdentity(['specifications' => []], 'zhizaosi', '织造司'),
        );
    }

    public function testMapperCreatesRealEavAxesAndExtendedSizeOptions(): void
    {
        $mapper = new \Weline\Product\Sample\Hanfu1688\OfferEavMapper();
        $offer = [
            'offer_id' => '604560496347',
            'source_url' => 'https://detail.1688.com/offer/604560496347.html',
            'specifications' => [
                '颜色分类' => ['白色上衣', '红色上衣'],
                '尺码' => ['S', 'XXL', 'XXXL'],
            ],
            'variants' => [
                [
                    'specification' => '白色上衣;S',
                    'image_url' => 'https://cbu01.alicdn.com/white.jpg',
                    'price' => '99.00',
                    'public_available_quantity' => 3,
                ],
                [
                    'specification' => '红色上衣;XXXL',
                    'image_url' => 'https://cbu01.alicdn.com/red.jpg',
                    'price' => '109.00',
                    'public_available_quantity' => 2,
                ],
            ],
        ];

        $catalog = $mapper->catalog($offer, 'ZHIZAOSI-HANFU');
        self::assertSame(['color', 'size'], array_column($catalog['axes'], 'code'));
        $sizeAxis = $catalog['axes'][1];
        self::assertSame(
            ['s', 'xxl', 'xxxl'],
            array_column($sizeAxis['options'], 'value'),
        );
        self::assertNotEmpty($catalog['sku_overrides']);
        foreach ($catalog['sku_overrides'] as $sku) {
            self::assertIsString($sku);
            self::assertLessThanOrEqual(36, strlen($sku), $sku);
            self::assertDoesNotMatchRegularExpression('/MIN-GUO-FENG|ZHONG-GUO-FENG/', $sku);
        }
        self::assertCount(2, $catalog['prices']);
        self::assertCount(2, $catalog['inventory']);
        self::assertCount(2, $catalog['variant_media']);
        self::assertSame(
            'https://cbu01.alicdn.com/white.jpg',
            $catalog['variant_media'][0]['image_url'],
        );
        self::assertSame(
            ['color' => 'bai-se-shang', 'size' => 's'],
            $catalog['variant_media'][0]['combination'],
        );

        $rows = $mapper->map($offer, [
            'shop_url' => 'https://shop.example.1688.com',
            'factory_url' => 'https://www.1688.com/factory/example.html',
            'company_name' => '汉服供应商',
        ], 'snapshot-digest', $catalog);
        $codes = array_column($rows, 'attribute_code');
        self::assertContains('color', $codes);
        self::assertContains('size', $codes);
        self::assertNotContains('available_colors', $codes);
        self::assertNotContains('available_sizes', $codes);
        self::assertNotContains('source_public_specs', $codes);
        self::assertNotContains('source_variant_combinations', $codes);
        $colorRow = $rows[array_search('color', $codes, true)];
        self::assertSame('multiselect', $colorRow['value_type']);
        self::assertIsArray($colorRow['value']);
    }

    public function testMapperKeepsNumericEavOptionLabelsAsStrings(): void
    {
        $mapper = new \Weline\Product\Sample\Hanfu1688\OfferEavMapper();

        try {
            $catalog = $mapper->catalog([
                'offer_id' => '824136170000',
                'specifications' => ['货号' => ['5']],
                'variants' => [],
            ], 'HUAZHAOJI-TEST');
        } catch (\TypeError $exception) {
            self::fail('Numeric EAV option labels must remain strings: ' . $exception->getMessage());
        }

        $definition = current(array_filter(
            $catalog['definitions'],
            static fn(array $row): bool => ($row['name'] ?? '') === '货号',
        ));
        self::assertIsArray($definition);
        self::assertSame([['code' => '5', 'label' => '5']], $definition['options']);
    }
}
