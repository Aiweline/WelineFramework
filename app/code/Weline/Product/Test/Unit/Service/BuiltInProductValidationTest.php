<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Service\DefaultProductProvider;
use Weline\Product\Service\Provider\ConfigurableProductProvider;
use Weline\Product\Service\Provider\DownloadableProductProvider;

final class BuiltInProductValidationTest extends TestCase
{
    public function testExplicitZeroPriceIsValidAndZeroStockIsOnlyWarning(): void
    {
        $context = new ProductValidationContext(
            productType: 'simple',
            product: ['name' => 'Zero'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'offer-1',
                'sku' => 'ZERO-1',
                'quantity' => 0,
                'requires_shipping' => true,
            ]],
            attributes: ['name' => 'Zero'],
            prices: [['offer_id' => 1, 'store_id' => 0, 'currency' => 'CNY', 'amount_minor' => 0]],
            storeIds: [1],
        );

        $result = (new DefaultProductProvider())->validateForPublish($context);
        self::assertTrue($result->isValid(), json_encode($result->errors));
        self::assertSame('offer_zero_stock', $result->warnings[0]['code']);
    }

    public function testConfigurableRejectsDuplicateCombination(): void
    {
        $context = new ProductValidationContext(
            productType: 'configurable',
            product: ['name' => 'Tee'],
            offers: [
                ['offer_id' => 1, 'global_offer_uuid' => 'a', 'sku' => 'TEE-S', 'combination' => ['size' => 'S']],
                ['offer_id' => 2, 'global_offer_uuid' => 'b', 'sku' => 'TEE-M', 'combination' => ['size' => 'S']],
            ],
            attributes: ['name' => 'Tee'],
            prices: [
                ['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 100],
                ['offer_id' => 2, 'store_id' => 0, 'amount_minor' => 100],
            ],
            typeConfiguration: ['axes' => ['size']],
        );

        $codes = array_column(
            (new ConfigurableProductProvider())->validateForPublish($context)->errors,
            'code',
        );
        self::assertContains('variant_combination_duplicate', $codes);
    }

    public function testDownloadableRequiresPrivateAsset(): void
    {
        $context = new ProductValidationContext(
            productType: 'downloadable',
            product: ['name' => 'Book'],
            offers: [['offer_id' => 1, 'global_offer_uuid' => 'book', 'sku' => 'BOOK-1']],
            attributes: ['name' => 'Book'],
            prices: [['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 500]],
            typeConfiguration: ['download_assets' => [['asset_id' => 'public-image', 'private' => false]]],
        );

        $codes = array_column(
            (new DownloadableProductProvider())->validateForPublish($context)->errors,
            'code',
        );
        self::assertContains('download_private_asset_required', $codes);
    }


    public function testConfigurableRequiresEveryOfferToMatchTheSelectedAxes(): void
    {
        $context = new ProductValidationContext(
            productType: 'configurable',
            product: ['name' => 'Tee'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'variant-1',
                'sku' => 'TEE-RED',
                'combination' => ['color' => 'red'],
            ]],
            prices: [['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 100]],
            typeConfiguration: ['axes' => ['size']],
        );

        $codes = array_column(
            (new ConfigurableProductProvider())->validateForPublish($context)->errors,
            'code',
        );

        self::assertContains('variant_combination_axes_mismatch', $codes);
        self::assertContains('variant_combination_value_required', $codes);
    }

    public function testVirtualRequiresValidUniqueServicePlans(): void
    {
        $base = [
            'productType' => 'virtual',
            'product' => ['name' => 'Consulting'],
            'offers' => [[
                'offer_id' => 1,
                'global_offer_uuid' => 'service-1',
                'sku' => 'SERVICE-1',
            ]],
            'prices' => [['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 100]],
        ];
        $emptyContext = new ProductValidationContext(
            ...$base,
            typeConfiguration: ['service_plans' => []],
        );
        $emptyCodes = array_column(
            (new \Weline\Product\Service\Provider\VirtualProductProvider())
                ->validateForPublish($emptyContext)->errors,
            'code',
        );
        self::assertContains('virtual_service_plan_required', $emptyCodes);

        $invalidContext = new ProductValidationContext(
            ...$base,
            typeConfiguration: ['service_plans' => [
                ['code' => 'basic', 'name' => 'Basic'],
                ['code' => 'BASIC', 'name' => 'Duplicate'],
                ['code' => '', 'name' => 'Missing code'],
            ]],
        );
        $invalidCodes = array_column(
            (new \Weline\Product\Service\Provider\VirtualProductProvider())
                ->validateForPublish($invalidContext)->errors,
            'code',
        );
        self::assertContains('virtual_service_plan_duplicate', $invalidCodes);
        self::assertContains('virtual_service_plan_invalid', $invalidCodes);
    }

    public function testDownloadableValidatesAssetsAndEntitlementPolicy(): void
    {
        $context = new ProductValidationContext(
            productType: 'downloadable',
            product: ['name' => 'Book'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'book-1',
                'sku' => 'BOOK-1',
            ]],
            prices: [['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 500]],
            typeConfiguration: [
                'download_assets' => [
                    ['asset_id' => 'asset-1', 'private' => true],
                    ['asset_id' => 'asset-1', 'private' => true],
                ],
                'entitlement_policy' => [
                    'download_limit' => 0,
                    'expires_after_days' => -1,
                ],
            ],
        );

        $codes = array_column(
            (new DownloadableProductProvider())->validateForPublish($context)->errors,
            'code',
        );

        self::assertContains('download_asset_duplicate', $codes);
        self::assertContains('download_limit_invalid', $codes);
        self::assertContains('download_expiry_invalid', $codes);
    }

    public function testBundleRejectsSelfCycleInvalidSelectionAndQuantity(): void
    {
        $context = new ProductValidationContext(
            productType: 'bundle',
            product: ['name' => 'Kit', 'global_product_uuid' => 'product-1'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'bundle-1',
                'sku' => 'KIT-1',
            ]],
            prices: [['offer_id' => 1, 'store_id' => 0, 'amount_minor' => 1000]],
            typeConfiguration: [
                'component_groups' => [[
                    'code' => 'main',
                    'min_selections' => 2,
                    'max_selections' => 1,
                    'components' => [[
                        'global_offer_uuid' => 'child-1',
                        'global_product_uuid' => 'product-1',
                        'published' => true,
                        'quantity' => 0,
                    ]],
                ]],
                'price_mode' => 'fixed',
            ],
        );

        $codes = array_column(
            (new \Weline\Product\Service\Provider\BundleProductProvider())
                ->validateForPublish($context)->errors,
            'code',
        );

        self::assertContains('bundle_cycle_detected', $codes);
        self::assertContains('bundle_group_selection_invalid', $codes);
        self::assertContains('bundle_component_quantity_invalid', $codes);
    }

    public function testDiagnosticsUseRequestedScopesAndActualInventory(): void
    {
        $context = new ProductValidationContext(
            productType: 'simple',
            product: ['name' => 'Scoped product'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'offer-scoped',
                'sku' => 'SCOPED-1',
                'requires_shipping' => true,
            ]],
            attributes: ['name' => 'Scoped product'],
            prices: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'offer-scoped',
                'store_id' => 0,
                'currency' => 'USD',
                'amount_minor' => 100,
            ]],
            storeIds: [11, 12],
            locale: 'zh_Hans_CN',
            currency: 'CNY',
            inventory: [
                'enabled' => true,
                'capability_available' => true,
                'rows' => [
                    [
                        'store_id' => 11,
                        'offer_id' => 1,
                        'global_offer_uuid' => 'offer-scoped',
                        'available_minor' => 0,
                        'sellable' => false,
                    ],
                    [
                        'store_id' => 12,
                        'offer_id' => 1,
                        'global_offer_uuid' => 'offer-scoped',
                        'available_minor' => 3,
                        'sellable' => true,
                    ],
                ],
            ],
            stores: [
                ['store_id' => 11, 'name' => 'Store East'],
                ['store_id' => 12, 'name' => 'Store West'],
            ],
        );

        $result = (new DefaultProductProvider())->validateForPublish($context);
        $diagnostics = $result->toArray($context);
        $priceErrors = array_values(array_filter(
            $diagnostics['errors'],
            static fn(array $issue): bool => $issue['code'] === 'offer_price_required',
        ));
        $stockWarnings = array_values(array_filter(
            $diagnostics['warnings'],
            static fn(array $issue): bool => $issue['code'] === 'offer_zero_stock',
        ));

        self::assertFalse($diagnostics['valid']);
        self::assertSame([11, 12], array_column($priceErrors, 'store_id'));
        self::assertCount(1, $stockWarnings);
        self::assertSame(11, $stockWarnings[0]['store_id']);
        self::assertSame('warning', $stockWarnings[0]['severity']);
        self::assertSame(
            ['error_count' => 2, 'warning_count' => 1, 'group_count' => 2],
            $diagnostics['summary'],
        );

        $groups = array_column($diagnostics['groups'], null, 'store_id');
        self::assertSame('zh_Hans_CN', $groups[11]['locale']);
        self::assertSame('CNY', $groups[11]['currency']);
        self::assertSame('offer-scoped', $groups[11]['offer_uuid']);
        self::assertSame('SCOPED-1', $groups[11]['offer_label']);
        self::assertSame('Store East', $groups[11]['store_label']);
        self::assertFalse($groups[11]['valid']);
        self::assertFalse($groups[12]['valid']);
    }

    public function testClearedStoreLocaleNameDoesNotFallBackToWebsite(): void
    {
        $context = new ProductValidationContext(
            productType: 'simple',
            product: [],
            offers: [[
                'offer_id' => 7,
                'global_offer_uuid' => 'offer-cleared',
                'sku' => 'CLEARED-1',
                'requires_shipping' => true,
            ]],
            attributes: [
                [
                    'entity_type' => 'product',
                    'attribute_code' => 'name',
                    'store_id' => 0,
                    'locale' => 'zh_Hans_CN',
                    'value' => 'Website name',
                    'scope_state' => 'explicit',
                ],
                [
                    'entity_type' => 'product',
                    'attribute_code' => 'name',
                    'store_id' => 5,
                    'locale' => 'zh_Hans_CN',
                    'value' => null,
                    'scope_state' => 'cleared',
                ],
            ],
            prices: [[
                'offer_id' => 7,
                'global_offer_uuid' => 'offer-cleared',
                'store_id' => 0,
                'currency' => 'CNY',
                'amount_minor' => 0,
            ]],
            storeIds: [5],
            locale: 'zh_Hans_CN',
            currency: 'CNY',
        );

        $errors = (new DefaultProductProvider())->validateForPublish($context)->errors;
        $nameErrors = array_values(array_filter(
            $errors,
            static fn(array $issue): bool => $issue['code'] === 'product_name_required',
        ));

        self::assertCount(1, $nameErrors);
        self::assertSame(5, $nameErrors[0]['store_id']);
        self::assertSame('zh_Hans_CN', $nameErrors[0]['locale']);
    }
}
