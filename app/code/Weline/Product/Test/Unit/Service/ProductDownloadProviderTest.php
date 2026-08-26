<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Service\Provider\DownloadableProductProvider;

final class ProductDownloadProviderTest extends TestCase
{
    public function testReadyPrivateAssetWithDownloadRolePassesProviderSpecificValidation(): void
    {
        $asset = $this->createMock(FileAsset::class);
        $asset->method('getAssetId')->willReturn('asset-1');
        $asset->method('isDeleted')->willReturn(false);
        $asset->method('isReady')->willReturn(true);
        $asset->method('getVisibility')->willReturn(FileAsset::VISIBILITY_PRIVATE);
        $asset->method('getData')->willReturnCallback(
            static fn(string $field): mixed => $field === FileAsset::schema_fields_METADATA
                ? json_encode([
                    'access_policy' => [
                        'policy_revision' => 3,
                        'allowed_roles' => ['product_download'],
                    ],
                ], JSON_THROW_ON_ERROR)
                : null,
        );
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->method('get')->with('asset-1')->willReturn($asset);

        $result = (new DownloadableProductProvider($assets))->validateForPublish(
            $this->context([['asset_id' => 'asset-1', 'private' => true]]),
        );
        $codes = array_column($result->errors, 'code');

        self::assertNotContains('download_asset_not_ready', $codes);
        self::assertNotContains('download_asset_not_private', $codes);
        self::assertNotContains('download_asset_policy_invalid', $codes);
        self::assertNotContains('download_asset_unavailable', $codes);
    }

    public function testPrivateAssetWithoutDownloadRoleIsRejected(): void
    {
        $asset = $this->createMock(FileAsset::class);
        $asset->method('getAssetId')->willReturn('asset-1');
        $asset->method('isDeleted')->willReturn(false);
        $asset->method('isReady')->willReturn(true);
        $asset->method('getVisibility')->willReturn(FileAsset::VISIBILITY_PRIVATE);
        $asset->method('getData')->willReturn(json_encode([
            'access_policy' => [
                'policy_revision' => 1,
                'allowed_roles' => ['media_manager'],
            ],
        ], JSON_THROW_ON_ERROR));
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->method('get')->willReturn($asset);

        $codes = array_column(
            (new DownloadableProductProvider($assets))
                ->validateForPublish($this->context([['asset_id' => 'asset-1', 'private' => true]]))
                ->errors,
            'code',
        );

        self::assertContains('download_asset_policy_invalid', $codes);
    }

    public function testCartSnapshotKeepsLegacyShapeUntilFulfillmentMetadataExists(): void
    {
        $identity = new OfferIdentity('product', 'offer-1', 7);
        $legacy = (new CartItemSnapshot($identity, 'Book'))->toArray();
        self::assertArrayNotHasKey('fulfillment_metadata', $legacy);

        $digital = ['digital_download' => ['schema_version' => 'product-download.v1']];
        $extended = (new CartItemSnapshot(
            offer: $identity,
            name: 'Book',
            fulfillmentMetadata: $digital,
        ))->toArray();
        self::assertSame($digital, $extended['fulfillment_metadata']);
    }

    /** @param list<array<string,mixed>> $downloadAssets */
    private function context(array $downloadAssets): ProductValidationContext
    {
        return new ProductValidationContext(
            productType: 'downloadable',
            product: ['name' => 'Book'],
            offers: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'offer-1',
                'sku' => 'BOOK-1',
            ]],
            attributes: ['name' => 'Book'],
            prices: [[
                'offer_id' => 1,
                'global_offer_uuid' => 'offer-1',
                'store_id' => 0,
                'currency' => 'CNY',
                'amount_minor' => 500,
            ]],
            typeConfiguration: [
                'download_assets' => $downloadAssets,
                'entitlement_policy' => [
                    'download_limit' => 3,
                    'expires_after_days' => 30,
                ],
            ],
        );
    }
}
