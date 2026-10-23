<?php

declare(strict_types=1);

namespace Weline\Product\Service\CatalogFeed;

use Weline\Framework\App\Exception as FrameworkException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Inventory\Api\InventoryCapabilityInterface;
use Weline\Product\Api\CatalogFeedOfferCandidateSourceInterface;
use Weline\Product\Api\Data\CatalogFeedOfferCandidate;
use Weline\Product\Api\Data\CatalogFeedReadQuery;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Loads published catalog offers through the product repositories.
 *
 * GTIN is taken from gtin or barcode attributes, never from SKU.
 */
final class OrmCatalogFeedOfferCandidateSource implements CatalogFeedOfferCandidateSourceInterface
{
    private const ATTRIBUTE_CODES = [
        'name',
        'description',
        'short_description',
        'brand',
        'barcode',
        'gtin',
        'mpn',
        'quote_only',
        'slug',
        'source_slug',
    ];

    public function __construct(
        private readonly OfferRepository $offers,
        private readonly ProductRepository $products,
        private readonly PriceRepository $prices,
        private readonly AttributeValueRepository $attributes,
        private readonly MediaRepository $media,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly WebsiteCatalogInterface $websites,
        private readonly StorefrontProductMediaUrlResolver $mediaUrls,
    ) {
    }

    public function collect(CatalogFeedReadQuery $query): iterable
    {
        $websiteId = max(0, $query->websiteId);
        $storeId = max(0, $query->storeId);
        $locale = trim($query->locale);
        $currency = strtoupper(trim($query->currency)) ?: 'CNY';
        $limit = max(1, min(500, $query->limit));
        $rows = $this->offers->listPublishedPage($websiteId, $limit, max(0, $query->afterOfferId));
        if ($rows === []) {
            return;
        }

        $productIds = [];
        $offerIds = [];
        foreach ($rows as $row) {
            $productId = (int)($row[Offer::schema_fields_PRODUCT_ID] ?? 0);
            $offerId = (int)($row[Offer::schema_fields_ID] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
            if ($offerId > 0) {
                $offerIds[$offerId] = $offerId;
            }
        }
        $productIds = array_values($productIds);
        $offerIds = array_values($offerIds);

        $publishedProducts = [];
        foreach ($this->products->listByIds($websiteId, $productIds) as $product) {
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId > 0 && (string)($product[Product::schema_fields_STATUS] ?? '') === Product::STATUS_PUBLISHED) {
                $publishedProducts[$productId] = true;
            }
        }

        $publishedOfferCount = [];
        foreach ($this->offers->listPublishedByProductIds($websiteId, $productIds) as $offer) {
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId > 0 && (string)($offer[Offer::schema_fields_STATUS] ?? '') === Offer::STATUS_PUBLISHED) {
                $publishedOfferCount[$productId] = ($publishedOfferCount[$productId] ?? 0) + 1;
            }
        }

        $storeIds = $storeId > 0 ? [$storeId, 0] : [0];
        $texts = $this->attributes->listLocalizedTextMap(
            $websiteId,
            'product',
            $productIds,
            $storeIds,
            self::ATTRIBUTE_CODES,
            $locale === '',
            $locale === '' ? null : [$locale, ''],
        );
        $images = $this->mainImages($websiteId, $productIds);
        $origin = $this->websiteOrigin($websiteId);
        $productSelected = $storeId > 0
            ? $this->storeProducts->selectionMap($websiteId, $storeId, $productIds)
            : [];
        $offerSelected = $storeId > 0
            ? $this->storeOffers->selectionMap($websiteId, $storeId, $offerIds)
            : [];

        foreach ($rows as $row) {
            $offerId = (int)($row[Offer::schema_fields_ID] ?? 0);
            $productId = (int)($row[Offer::schema_fields_PRODUCT_ID] ?? 0);
            $offerUuid = trim((string)($row[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            if ($offerId <= 0 || $productId <= 0 || $offerUuid === '') {
                continue;
            }
            if ((string)($row[Offer::schema_fields_STATUS] ?? '') !== Offer::STATUS_PUBLISHED) {
                continue;
            }
            if (!isset($publishedProducts[$productId])) {
                continue;
            }
            if ($storeId > 0 && (empty($productSelected[$productId]) || empty($offerSelected[$offerId]))) {
                continue;
            }

            $text = is_array($texts[$productId] ?? null) ? $texts[$productId] : [];
            $sku = trim((string)($row[Offer::schema_fields_SKU] ?? ''));
            $gtin = $this->localized($text, 'gtin', $locale);
            if ($gtin === '') {
                $gtin = $this->localized($text, 'barcode', $locale);
            }
            if ($gtin !== '' && $sku !== '' && strcasecmp($gtin, $sku) === 0) {
                $gtin = '';
            }
            $slug = $this->publicSlug($text, $locale, $productId);
            $image = $this->absoluteImage(
                (string)($images[$productId] ?? ''),
                $origin['url'],
                $websiteId,
                $origin['code'],
                $locale,
            );
            $price = $this->prices->read($websiteId, $storeId, $offerId, $currency);
            $catalogMinor = 0;
            if ($price->isExplicit() && is_numeric($price->value)) {
                $catalogMinor = max(0, (int)$price->value);
            }
            $stock = $this->stockFor($websiteId, $storeId, $offerId);

            yield new CatalogFeedOfferCandidate(
                offerId: $offerId,
                productId: $productId,
                globalOfferUuid: $offerUuid,
                sku: $sku,
                title: $this->localized($text, 'name', $locale),
                description: $this->description($text, $locale),
                brand: $this->localized($text, 'brand', $locale),
                gtin: $gtin === '' ? null : $gtin,
                mpn: $this->blankToNull($this->localized($text, 'mpn', $locale)),
                linkBase: $origin['url'] === '' ? '' : $origin['url'] . '/product/' . $slug,
                imageLink: $image,
                catalogPriceMinor: $catalogMinor,
                published: true,
                quoteOnly: $this->isQuoteOnly($text, $locale),
                variant: ($publishedOfferCount[$productId] ?? 0) > 1,
                stock: $stock['stock'],
                sellable: $stock['sellable'],
            );
        }
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function mainImages(int $websiteId, array $productIds): array
    {
        $picked = [];
        foreach ($this->media->listByProductIds($websiteId, $productIds, [0]) as $row) {
            if ((int)($row[Media::schema_fields_HIDDEN] ?? 0) === 1) {
                continue;
            }
            $productId = (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0);
            $path = trim((string)($row[Media::schema_fields_PATH] ?? ''));
            if ($productId <= 0 || $path === '' || str_starts_with($path, 'video://')) {
                continue;
            }
            $role = strtolower(trim((string)($row[Media::schema_fields_ROLE] ?? '')));
            if (!in_array($role, ['main', 'gallery', 'variant', ''], true)) {
                continue;
            }
            if ($role === 'main' && !isset($picked[$productId]['main'])) {
                $picked[$productId]['main'] = $path;
            }
            if (!isset($picked[$productId]['any'])) {
                $picked[$productId]['any'] = $path;
            }
        }
        $out = [];
        foreach ($picked as $productId => $paths) {
            $out[(int)$productId] = (string)($paths['main'] ?? $paths['any'] ?? '');
        }

        return $out;
    }

    /**
     * @return array{url:string,code:string}
     */
    private function websiteOrigin(int $websiteId): array
    {
        foreach ($this->websites->all() as $website) {
            if ($website->id !== $websiteId) {
                continue;
            }
            $url = rtrim(trim($website->url), '/');
            $parts = parse_url($url);
            $absolute = is_array($parts)
                && isset($parts['scheme'], $parts['host'])
                && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true);
            return [
                'url' => $absolute ? $url : '',
                'code' => trim($website->code),
            ];
        }

        return ['url' => '', 'code' => ''];
    }

    private function absoluteImage(
        string $path,
        string $origin,
        int $websiteId,
        string $websiteCode,
        string $locale,
    ): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        if (str_starts_with(strtolower($path), 'asset://')) {
            $path = $this->resolveAsset($path, $websiteId, $websiteCode, $locale);
            if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
                return $path;
            }
        }
        if (str_starts_with($path, '/') && $origin !== '') {
            return $origin . $path;
        }

        return '';
    }

    private function resolveAsset(string $path, int $websiteId, string $websiteCode, string $locale): string
    {
        $websiteCode = trim($websiteCode);
        if ($websiteCode === '') {
            return '';
        }
        try {
            $scope = ScopeIdentity::store($websiteId, $websiteCode, 'main', ScopeIdentity::MODE_NORMAL);
        } catch (\InvalidArgumentException) {
            return '';
        }

        return trim($this->mediaUrls->resolveReference($path, $scope, $locale));
    }

    /**
     * @param array<string, array<string, string>> $text
     */
    private function description(array $text, string $locale): string
    {
        $description = $this->localized($text, 'description', $locale);
        if ($description !== '') {
            return $description;
        }

        return $this->localized($text, 'short_description', $locale);
    }

    /**
     * @param array<string, array<string, string>> $text
     */
    private function publicSlug(array $text, string $locale, int $productId): string
    {
        foreach (['source_slug', 'slug'] as $code) {
            $slug = strtolower($this->localized($text, $code, $locale));
            if (preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) === 1) {
                return $slug;
            }
        }

        return (string)$productId;
    }

    /**
     * @param array<string, array<string, string>> $text
     */
    private function isQuoteOnly(array $text, string $locale): bool
    {
        $value = strtolower($this->localized($text, 'quote_only', $locale));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    /**
     * @param array<string, array<string, string>> $text
     */
    private function localized(array $text, string $code, string $locale): string
    {
        $byLocale = $text[$code] ?? null;
        if (!is_array($byLocale)) {
            return '';
        }
        $locale = trim($locale);
        if ($locale !== '' && isset($byLocale[$locale])) {
            $preferred = trim((string)$byLocale[$locale]);
            if ($preferred !== '') {
                return $preferred;
            }
        }
        if (isset($byLocale[''])) {
            $fallback = trim((string)$byLocale['']);
            if ($fallback !== '') {
                return $fallback;
            }
        }
        foreach ($byLocale as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{stock:?int,sellable:?bool}
     */
    private function stockFor(int $websiteId, int $storeId, int $offerId): array
    {
        $unknown = ['stock' => null, 'sellable' => null];
        if (!interface_exists(InventoryCapabilityInterface::class)) {
            return $unknown;
        }
        try {
            $inventory = ObjectManager::getInstance(InventoryCapabilityInterface::class);
        } catch (FrameworkException $e) {
            if (!str_contains($e->getMessage(), '未在 etc/module.php provides')) {
                throw $e;
            }

            return $unknown;
        }
        if (!$inventory instanceof InventoryCapabilityInterface) {
            return $unknown;
        }
        $result = $inventory->getAvailability($websiteId, $storeId, $offerId);

        return [
            'stock' => $result->availableMinor,
            'sellable' => $result->sellable,
        ];
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
