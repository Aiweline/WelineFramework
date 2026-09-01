<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;

/**
 * Expands a configurable product into one published Offer per variant combination.
 *
 * Used by catalog seed scripts; admin matrix reconciliation remains in ProductAdminCommandService.
 */
final class ProductConfigurableMatrixSeedService
{
    public function __construct(
        private readonly ProductVariantMatrixService $matrix,
        private readonly ProductIdentityV2Service $identities,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly PriceRepository $prices,
        private readonly AttributeValueRepository $attributes,
        private readonly StoreOfferRepository $storeOffers,
    ) {
    }

    /**
     * @param list<array{code:string,label?:string,options:list<mixed>}> $axes
     * @param array<string, string> $defaults
     * @param list<int> $storeIds
     * @return array{created:int,updated:int,total:int}
     */
    public function expand(
        int $websiteId,
        int $productId,
        int $primaryOfferId,
        string $primarySku,
        array $axes,
        array $defaults,
        int $priceMinor,
        array $storeIds,
    ): array {
        if ($productId <= 0 || $primaryOfferId <= 0 || $axes === []) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $product = $this->products->findById($websiteId, $productId);
        if ($product === null) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $globalProductUuid = trim((string)$product->getData(Product::schema_fields_GLOBAL_PRODUCT_UUID));
        if ($globalProductUuid === '') {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $rows = $this->matrix->generate($axes, $primarySku);
        if ($rows === []) {
            return ['created' => 0, 'updated' => 0, 'total' => 0];
        }

        $defaultCombination = $this->normalizeCombination($defaults);
        $defaultKey = $defaultCombination !== []
            ? $this->matrix->combinationKey($defaultCombination)
            : $rows[0]['combination_key'];
        $primaryKey = $defaultKey;
        $hasDefaultRow = false;
        foreach ($rows as $row) {
            if ($row['combination_key'] === $defaultKey) {
                $hasDefaultRow = true;
                break;
            }
        }
        if (!$hasDefaultRow) {
            $primaryKey = $rows[0]['combination_key'];
        }

        $primaryOffer = $this->offers->findById($websiteId, $primaryOfferId);
        if ($primaryOffer === null) {
            return ['created' => 0, 'updated' => 0, 'disabled' => 0, 'total' => 0];
        }

        $created = 0;
        $updated = 0;
        $desiredKeys = array_fill_keys(array_column($rows, 'combination_key'), true);

        foreach ($rows as $row) {
            $combination = $row['combination'];
            $combinationKey = $row['combination_key'];
            $variantSku = $row['sku'];
            $isDefault = $combinationKey === $defaultKey;

            if ($combinationKey === $primaryKey) {
                $this->applyOfferVariant(
                    $websiteId,
                    $primaryOffer,
                    $combination,
                    $combinationKey,
                    $primarySku,
                    $isDefault,
                    $priceMinor,
                    $storeIds,
                );
                ++$updated;
                continue;
            }

            $existing = null;
            foreach ($this->offers->listByProductIds($websiteId, [$productId]) as $offerRow) {
                if (trim((string)($offerRow[Offer::schema_fields_COMBINATION_KEY] ?? '')) === $combinationKey) {
                    $existing = $this->offers->findById(
                        $websiteId,
                        (int)($offerRow[Offer::schema_fields_ID] ?? 0),
                    );
                    break;
                }
            }

            if ($existing !== null) {
                $this->applyOfferVariant(
                    $websiteId,
                    $existing,
                    $combination,
                    $combinationKey,
                    trim((string)$existing->getData('sku')),
                    $isDefault,
                    $priceMinor,
                    $storeIds,
                );
                ++$updated;
                continue;
            }

            $identity = $this->identities->createOffer(
                $globalProductUuid,
                $variantSku,
                hash('sha256', 'configurable-matrix-seed:' . $websiteId . ':' . $combinationKey),
            );
            $local = $this->offers->create($websiteId, [
                Offer::schema_fields_PRODUCT_ID => $productId,
                Offer::schema_fields_GLOBAL_OFFER_UUID => $identity->globalOfferUuid,
                'sku' => $identity->sku,
                'identity_version' => $identity->version,
                'combination_key' => $combinationKey,
                'is_default' => $isDefault ? 1 : 0,
                'requires_shipping' => 1,
                'type_config_json' => json_encode(['combination' => $combination], JSON_UNESCAPED_UNICODE),
            ]);
            $this->writeOfferAxisValues($websiteId, (int)$local->getId(), $combination);
            $this->prices->writeExplicit($websiteId, 0, (int)$local->getId(), 'CNY', $priceMinor);
            foreach ($storeIds as $storeId) {
                $this->storeOffers->select($websiteId, $storeId, (int)$local->getId(), true);
            }
            $published = $this->offers->publish(
                $websiteId,
                (int)$local->getId(),
                (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
            );
            if ($published !== null) {
                ++$created;
            }
        }

        $disabled = 0;
        foreach ($this->offers->listByProductIds($websiteId, [$productId]) as $offerRow) {
            $combinationKey = trim((string)($offerRow[Offer::schema_fields_COMBINATION_KEY] ?? ''));
            if ($combinationKey === '' || isset($desiredKeys[$combinationKey])) {
                continue;
            }
            $status = strtolower(trim((string)($offerRow[Offer::schema_fields_STATUS] ?? '')));
            if (in_array($status, ['disabled', 'archived'], true)) {
                continue;
            }
            $offerId = (int)($offerRow[Offer::schema_fields_ID] ?? 0);
            if ($offerId <= 0) {
                continue;
            }
            $local = $this->offers->findById($websiteId, $offerId);
            if ($local === null) {
                continue;
            }
            $this->offers->transition(
                $websiteId,
                $offerId,
                (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
                Offer::STATUS_DISABLED,
            );
            foreach ($storeIds as $storeId) {
                $this->storeOffers->select($websiteId, $storeId, $offerId, false);
            }
            ++$disabled;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'disabled' => $disabled,
            'total' => count($rows),
        ];
    }

    /**
     * @param array<string, string> $combination
     * @param list<int> $storeIds
     */
    private function applyOfferVariant(
        int $websiteId,
        Offer $offer,
        array $combination,
        string $combinationKey,
        string $sku,
        bool $isDefault,
        int $priceMinor,
        array $storeIds,
    ): void {
        $offerId = (int)$offer->getId();
        $this->offers->updateVersioned(
            $websiteId,
            $offerId,
            (int)$offer->getData(Offer::schema_fields_PUBLISH_VERSION),
            [
                'sku' => $sku,
                'combination_key' => $combinationKey,
                'is_default' => $isDefault ? 1 : 0,
                'type_config_json' => json_encode(['combination' => $combination], JSON_UNESCAPED_UNICODE),
            ],
        );
        $this->writeOfferAxisValues($websiteId, $offerId, $combination);
        $this->prices->writeExplicit($websiteId, 0, $offerId, 'CNY', $priceMinor);
        foreach ($storeIds as $storeId) {
            $this->storeOffers->select($websiteId, $storeId, $offerId, true);
        }

        $fresh = $this->offers->findById($websiteId, $offerId);
        if ($fresh !== null && strtolower(trim((string)$fresh->getData('status'))) !== 'published') {
            $this->offers->publish(
                $websiteId,
                $offerId,
                (int)$fresh->getData(Offer::schema_fields_PUBLISH_VERSION),
            );
        }
    }

    /** @param array<string, string> $combination */
    private function writeOfferAxisValues(int $websiteId, int $offerId, array $combination): void
    {
        foreach ($combination as $code => $value) {
            $this->attributes->writeTyped(
                $websiteId,
                0,
                'offer',
                $offerId,
                (string)$code,
                '',
                'select',
                $value,
                false,
            );
        }
    }

    /** @param array<string, mixed> $defaults @return array<string, string> */
    private function normalizeCombination(array $defaults): array
    {
        $normalized = [];
        foreach ($defaults as $axis => $value) {
            $axis = strtolower(trim((string)$axis));
            $value = trim((string)$value);
            if ($axis !== '' && $value !== '') {
                $normalized[$axis] = $value;
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}