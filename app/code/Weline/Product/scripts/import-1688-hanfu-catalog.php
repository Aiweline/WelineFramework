<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\BrandRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\SupplierRepository;
use Weline\Product\Service\Hanfu1688\AcceptedOfferDetailEnricher;
use Weline\Product\Service\Hanfu1688\CatalogCollector;
use Weline\Product\Service\Hanfu1688\FactoryPageParser;
use Weline\Product\Service\Hanfu1688\HanfuProductClassifier;
use Weline\Product\Service\Hanfu1688\MediaImporter;
use Weline\Product\Service\Hanfu1688\OfferEavMapper;
use Weline\Product\Service\Hanfu1688\OfferDetailParser;
use Weline\Product\Service\Hanfu1688\PublicHttpClient;
use Weline\Product\Service\Hanfu1688\RunArtifactStore;
use Weline\Product\Service\ProductAdminCommandService;
use Weline\Product\Service\ProductAttributeMetadataCatalog;
use Weline\Product\Service\ProductCatalogEavBootstrap;
use Weline\Product\Service\ProductIdentityV2Service;
use Weline\Product\Service\ProductVariantMatrixService;

/**
 * @param list<object> $sourceCandidates
 * @return array{existing: ?object, preserved_legacy_product_ids: list<int>}
 */
function hanfu1688SelectExistingProduct(
    array $sourceCandidates,
    ?object $semanticSkuCandidate,
    ?object $legacySkuCandidate,
): array {
    $existing = null;
    $preservedLegacyProductIds = [];
    foreach ($sourceCandidates as $candidate) {
        if (strtolower(trim((string)$candidate->getData('product_type'))) === 'configurable') {
            $existing ??= $candidate;
            continue;
        }
        $candidateId = (int)$candidate->getId();
        if ($candidateId > 0) {
            $preservedLegacyProductIds[] = $candidateId;
        }
    }
    if ($existing === null && $semanticSkuCandidate !== null) {
        $existing = $semanticSkuCandidate;
    }
    if ($legacySkuCandidate !== null) {
        if (strtolower(trim((string)$legacySkuCandidate->getData('product_type'))) === 'configurable') {
            $existing ??= $legacySkuCandidate;
        } else {
            $legacyProductId = (int)$legacySkuCandidate->getId();
            if ($legacyProductId > 0) {
                $preservedLegacyProductIds[] = $legacyProductId;
            }
        }
    }
    $preservedLegacyProductIds = array_values(array_unique($preservedLegacyProductIds));
    sort($preservedLegacyProductIds, SORT_NUMERIC);

    return [
        'existing' => $existing,
        'preserved_legacy_product_ids' => $preservedLegacyProductIds,
    ];
}

/** @return array{code:string,name:string,source:bool} */
function hanfu1688OfferBrandIdentity(array $offer, string $fallbackCode, string $fallbackName): array
{
    $sourceBrandName = trim((string)($offer['source_brand_name'] ?? ''));
    if ($sourceBrandName === '') {
        $specificationBrands = $offer['specifications']['品牌'] ?? [];
        $specificationBrands = is_array($specificationBrands) ? $specificationBrands : [$specificationBrands];
        $sourceBrandName = trim((string)($specificationBrands[0] ?? ''));
    }
    if ($sourceBrandName === '') {
        return ['code' => $fallbackCode, 'name' => $fallbackName, 'source' => false];
    }
    $sourceBrandCode = str_replace('-', '', hanfu1688SemanticSlug($sourceBrandName, 48));
    return ['code' => $sourceBrandCode, 'name' => $sourceBrandName, 'source' => true];
}

if (defined('WELINE_HANFU_IMPORT_HELPERS_ONLY') && WELINE_HANFU_IMPORT_HELPERS_ONLY === true) {
    return;
}

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', [
    'stage:',
    'run-id:',
    'website::',
    'source-code:',
    'factory-url:',
    'brand-code:',
    'supplier-code:',
    'limit::',
    'reuse-snapshot',
    'publish-first',
    'publish-all',
]);
$stage = strtolower(trim((string)($options['stage'] ?? '')));
if ($stage !== 'apply-source') {
    throw new InvalidArgumentException('hanfu_1688_cli_stage_invalid');
}
$runId = strtolower(trim((string)($options['run-id'] ?? '')));
$websiteId = max(0, (int)($options['website'] ?? 0));
$sourceCode = strtolower(trim((string)($options['source-code'] ?? '')));
$factoryUrl = trim((string)($options['factory-url'] ?? ''));
$brandCode = strtolower(trim((string)($options['brand-code'] ?? '')));
$supplierCode = strtolower(trim((string)($options['supplier-code'] ?? '')));
$limit = max(0, (int)($options['limit'] ?? 0));
$publishFirst = array_key_exists('publish-first', $options);
$publishAll = array_key_exists('publish-all', $options);
if ($runId === '' || $sourceCode === '' || $factoryUrl === '' || $brandCode === '' || $supplierCode === '') {
    throw new InvalidArgumentException('hanfu_1688_cli_source_arguments_required');
}

$manager = ObjectManager::getInstance();
/** @var BrandRepository $brands */
$brands = $manager->get(BrandRepository::class);
/** @var SupplierRepository $suppliers */
$suppliers = $manager->get(SupplierRepository::class);
/** @var ProductRepository $products */
$products = $manager->get(ProductRepository::class);
/** @var OfferRepository $offerRepository */
$offerRepository = $manager->get(OfferRepository::class);
/** @var CategoryRepository $categories */
$categories = $manager->get(CategoryRepository::class);
/** @var ProductAdminCommandService $commands */
$commands = $manager->get(ProductAdminCommandService::class);
/** @var ProductVariantMatrixService $variantMatrix */
$variantMatrix = $manager->get(ProductVariantMatrixService::class);
/** @var ProductAttributeMetadataCatalog $attributeMetadata */
$attributeMetadata = $manager->get(ProductAttributeMetadataCatalog::class);
/** @var AttributeValueRepository $attributes */
$attributes = $manager->get(AttributeValueRepository::class);
/** @var ProductIdentityV2Service $identities */
$identities = $manager->get(ProductIdentityV2Service::class);
/** @var FileAssetLibraryInterface $fileAssets */
$fileAssets = $manager->get(FileAssetLibraryInterface::class);
/** @var ProductCatalogEavBootstrap $eavBootstrap */
$eavBootstrap = $manager->get(ProductCatalogEavBootstrap::class);
$eavBootstrap->ensureHanfuSchema();
$http = new PublicHttpClient();
$classifier = new HanfuProductClassifier();
$eavMapper = new OfferEavMapper();
$descriptionParser = new OfferDetailParser();
$mediaImporter = new MediaImporter($http, $fileAssets);

$brand = $brands->findByCode($websiteId, $brandCode)
    ?? throw new RuntimeException('hanfu_1688_brand_not_found:' . $brandCode);
$supplier = $suppliers->findByCode($websiteId, $supplierCode)
    ?? throw new RuntimeException('hanfu_1688_supplier_not_found:' . $supplierCode);
$brandId = (int)$brand->getId();
$brandName = trim((string)$brand->getData('name')) ?: $brandCode;
$supplierId = (int)$supplier->getId();

$source = [
    'source_code' => $sourceCode,
    'brand_code' => $brandCode,
    'supplier_code' => $supplierCode,
    'factory_url' => $factoryUrl,
    'shop_url' => '',
];
$artifacts = new RunArtifactStore();
if (array_key_exists('reuse-snapshot', $options)) {
    $snapshot = $artifacts->readJson($runId, 'crawl-snapshot.json');
    $collected = $snapshot['sources'][0] ?? null;
    if (!is_array($collected) || ($snapshot['contract'] ?? null) !== 'hanfu.1688.snapshot.v1') {
        throw new RuntimeException('hanfu_1688_reused_snapshot_invalid');
    }
} else {
    $collector = new CatalogCollector(
        $http,
        new FactoryPageParser(),
        new OfferDetailParser(),
    );
    $collected = $collector->collect(
        $source,
        500,
        static fn(array $listing): bool => false,
    );
    $collected = (new AcceptedOfferDetailEnricher(new OfferDetailParser()))
        ->enrich($collected, $classifier);
    $sourceDigest = hash('sha256', json_encode(
        $source,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
    $snapshot = [
        'contract' => 'hanfu.1688.snapshot.v1',
        'source_digest' => $sourceDigest,
        'sources' => [$collected],
        'offers' => $collected['offers'],
        'dedupe_conflicts' => [],
        'errors' => [],
        'complete' => true,
    ];
    $snapshot['snapshot_digest'] = hash('sha256', json_encode(
        $snapshot,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
    $artifacts->writeJson($runId, 'crawl-snapshot.json', $snapshot);
}

$collected = (new AcceptedOfferDetailEnricher(
    $descriptionParser,
    static fn(string $url): string => $http->get($url),
))->enrichDescriptions($collected, $classifier);

$brandEnrichment = ['accepted' => 0, 'attempted' => 0, 'enriched' => 0, 'cached' => 0, 'errors' => []];
foreach ($collected['offers'] as $index => $offer) {
    if (!is_array($offer) || !$classifier->classify((string)($offer['title'] ?? ''))['accepted']) {
        continue;
    }
    ++$brandEnrichment['accepted'];
    $sourceBrandName = trim((string)($offer['source_brand_name'] ?? ''));
    if (($offer['source_brand_checked'] ?? false) === true || $sourceBrandName !== '') {
        $collected['offers'][$index]['source_brand_checked'] = true;
        ++$brandEnrichment['cached'];
        continue;
    }
    ++$brandEnrichment['attempted'];
    $offerId = trim((string)($offer['offer_id'] ?? ''));
    if (preg_match('/^[1-9][0-9]{0,20}$/D', $offerId) !== 1) {
        $brandEnrichment['errors'][] = ['offer_id' => $offerId, 'error' => 'hanfu_1688_offer_id_invalid'];
        continue;
    }
    $lastError = 'hanfu_1688_offer_brand_unknown';
    $verifiedNoBrand = false;
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        usleep(2_000_000);
        foreach ([
            'mobile' => 'https://m.1688.com/offer/' . rawurlencode($offerId) . '.html',
            'desktop' => 'https://detail.1688.com/offer/' . rawurlencode($offerId) . '.html',
        ] as $brandSurface => $brandUrl) {
            try {
                $brandHtml = $http->get($brandUrl);
                $sourceBrandName = $descriptionParser->parseBrandName($brandHtml);
                if ($sourceBrandName === '') {
                    $verifiedNoBrand = true;
                    continue;
                }
                $collected['offers'][$index]['source_brand_checked'] = true;
                $collected['offers'][$index]['source_brand_name'] = $sourceBrandName;
                $collected['offers'][$index]['source_brand_surface'] = $brandSurface;
                ++$brandEnrichment['enriched'];
                continue 3;
            } catch (\Throwable $exception) {
                $lastError = $brandSurface . ':' . $exception->getMessage();
            }
        }
    }
    if ($verifiedNoBrand) {
        $collected['offers'][$index]['source_brand_checked'] = true;
        $collected['offers'][$index]['source_brand_name'] = '';
        $collected['offers'][$index]['source_brand_surface'] = 'verified_no_brand';
        ++$brandEnrichment['enriched'];
        continue;
    }
    $brandEnrichment['errors'][] = ['offer_id' => $offerId, 'error' => $lastError, 'attempts' => 3];
}
$collected['accepted_brand_enrichment'] = $brandEnrichment;
$snapshot['sources'][0] = $collected;
$snapshot['offers'] = $collected['offers'];
unset($snapshot['snapshot_digest']);
$snapshot['snapshot_digest'] = hash('sha256', json_encode(
    $snapshot,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));
$artifacts->writeJson($runId, 'crawl-snapshot.json', $snapshot);

$canonicalShopUrl = rtrim(trim((string)($collected['shop_url'] ?? '')), '/');
if ($canonicalShopUrl === '') {
    throw new RuntimeException('hanfu_1688_supplier_shop_url_missing');
}
$supplierStoreUrlBefore = rtrim(trim((string)$supplier->getData('store_url')), '/');
if ($supplierStoreUrlBefore !== $canonicalShopUrl) {
    $suppliers->updateFields($websiteId, $supplierId, ['store_url' => $canonicalShopUrl]);
}
$supplierReadback = $suppliers->findById($websiteId, $supplierId)
    ?? throw new RuntimeException('hanfu_1688_supplier_readback_missing');
if (!hash_equals($canonicalShopUrl, rtrim(trim((string)$supplierReadback->getData('store_url')), '/'))) {
    throw new RuntimeException('hanfu_1688_supplier_shop_url_readback_failed');
}

$categoryByPath = [];
foreach ($categories->listAll($websiteId) as $row) {
    $categoryByPath[(string)($row['path'] ?? '')] = (int)($row['category_id'] ?? 0);
}
$fallbackCategoryId = $categoryByPath['/sets/daily'] ?? 0;
if ($fallbackCategoryId <= 0) {
    throw new RuntimeException('hanfu_1688_default_category_missing');
}

$created = [];
$updated = [];
$rejected = [];
$errors = [];
$missingPrices = [];
$processed = 0;
$successfulProducts = [];
foreach ($collected['offers'] as $offer) {
    $title = trim((string)$offer['title']);
    $classification = $classifier->classify($title);
    if (!$classification['accepted']) {
        $rejected[] = [
            'offer_id' => (string)$offer['offer_id'],
            'title' => $title,
            'reason' => $classification['reason'],
        ];
        continue;
    }
    if (($offer['detail_status'] ?? null) === 'public_listing_fallback') {
        $errors[] = [
            'offer_id' => (string)$offer['offer_id'],
            'error_code' => 'hanfu_1688_accepted_detail_incomplete',
            'message' => 'accepted Hanfu offer still has listing-only data',
        ];
        continue;
    }
    $detailHtml = trim((string)($offer['detail_html'] ?? ''));
    $detailImageUrls = is_array($offer['detail_image_urls'] ?? null)
        ? array_values($offer['detail_image_urls'])
        : [];
    if ($detailHtml === '' || $detailImageUrls === []) {
        $errors[] = [
            'offer_id' => (string)$offer['offer_id'],
            'error_code' => 'hanfu_1688_accepted_description_incomplete',
            'message' => 'accepted Hanfu offer has no complete public detail body and images',
        ];
        continue;
    }
    if ($limit > 0 && $processed >= $limit) {
        break;
    }
    ++$processed;
    $offerId = (string)$offer['offer_id'];
    if (($offer['source_brand_checked'] ?? false) !== true) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => 'hanfu_1688_source_brand_incomplete',
            'message' => 'accepted Hanfu offer source brand metadata was not verified',
        ];
        continue;
    }
    $offerBrandIdentity = hanfu1688OfferBrandIdentity($offer, $brandCode, $brandName);
    $offerBrandCode = (string)$offerBrandIdentity['code'];
    $offerBrandName = (string)$offerBrandIdentity['name'];
    $offerBrand = $brands->findByCode($websiteId, $offerBrandCode);
    if ($offerBrand === null) {
        foreach ($brands->listAll($websiteId) as $brandRow) {
            if (trim((string)($brandRow['name'] ?? '')) !== $offerBrandName) {
                continue;
            }
            $offerBrandCode = trim((string)($brandRow['code'] ?? $offerBrandCode));
            $offerBrand = $brands->findByCode($websiteId, $offerBrandCode);
            break;
        }
    }
    if ($offerBrand === null) {
        $offerBrand = $brands->create($websiteId, [
            'code' => $offerBrandCode,
            'name' => $offerBrandName,
            'description' => '1688 商品公开属性同步品牌',
        ]);
    }
    $offerBrandCode = trim((string)$offerBrand->getData('code')) ?: $offerBrandCode;
    $offerBrandName = trim((string)$offerBrand->getData('name')) ?: $offerBrandName;
    $offerBrandId = (int)$offerBrand->getId();
    $identityData = hanfu1688Identity($offerBrandCode, $offerBrandName, $title);
    $sku = (string)$identityData['sku_prefix'];
    $sourceCandidates = [];
    foreach ($attributes->findEntityIdsByAttributeValue(
        $websiteId,
        'product',
        'source_offer_id',
        $offerId,
        0,
    ) as $existingProductId) {
        $candidate = $products->findById($websiteId, $existingProductId);
        if ($candidate !== null) {
            $sourceCandidates[] = $candidate;
        }
    }
    $selection = hanfu1688SelectExistingProduct(
        $sourceCandidates,
        $products->findBySku($websiteId, $sku),
        $products->findBySku($websiteId, '1688-' . $offerId),
    );
    $existing = $selection['existing'];
    $preservedLegacyProductIds = $selection['preserved_legacy_product_ids'];

    $categoryId = hanfu1688CategoryId($title, $categoryByPath, $fallbackCategoryId);
    $catalog = $eavMapper->catalog($offer, $sku);
    if (!is_array($catalog['axes'] ?? null) || $catalog['axes'] === []) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => 'hanfu_1688_variant_axes_missing',
            'message' => 'accepted Hanfu offer has no complete EAV variant axes',
        ];
        continue;
    }
    $eavBootstrap->ensureHanfuAttributeOptions((array)$catalog['definitions']);
    try {
        $catalog = hanfu1688CanonicalVariantCatalog(
            $attributeMetadata,
            $variantMatrix,
            $catalog,
            $sku,
        );
    } catch (Throwable $exception) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => 'hanfu_1688_variant_canonicalization_failed',
            'message' => $exception->getMessage(),
        ];
        continue;
    }

    try {
        $media = $mediaImporter->import(
            $sourceCode,
            $offerId,
            $title,
            is_array($offer['image_urls'] ?? null) ? $offer['image_urls'] : [],
            is_array($catalog['variant_media'] ?? null) ? $catalog['variant_media'] : [],
            $detailImageUrls,
        );
    } catch (Throwable $exception) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => 'hanfu_1688_media_import_failed',
            'message' => $exception->getMessage(),
        ];
        continue;
    }
    $skippedDetailMedia = is_array($media['skipped_detail_media'] ?? null)
        ? array_values($media['skipped_detail_media'])
        : [];
    $skippedDetailUrls = [];
    foreach ($skippedDetailMedia as $skippedDetail) {
        $skippedUrl = trim((string)($skippedDetail['url'] ?? ''));
        if ($skippedUrl !== '') {
            $skippedDetailUrls[] = $skippedUrl;
        }
    }
    try {
        $descriptionHtml = $descriptionParser->localizeDescription(
            $detailHtml,
            is_array($media['asset_ids_by_url'] ?? null) ? $media['asset_ids_by_url'] : [],
            $skippedDetailUrls,
        );
    } catch (Throwable $exception) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => 'hanfu_1688_description_localization_failed',
            'message' => $exception->getMessage(),
        ];
        continue;
    }
    $eavRows = $eavMapper->map(
        $offer,
        $collected,
        (string)$snapshot['snapshot_digest'],
        $catalog,
    );
    $payload = [
        'name' => $title,
        'locale' => 'zh_Hans_CN',
        'sku' => $sku,
        'product_type' => 'configurable',
        'axes' => $catalog['axes'],
        'sku_prefix' => $sku,
        'sku_overrides' => $catalog['sku_overrides'],
        'attribute_set' => 'hanfu',
        'attribute_set_label' => '汉服',
        'short_description' => trim((string)($offer['short_title'] ?? '')) ?: $title,
        'description' => $descriptionHtml,
        'slug' => $identityData['slug'],
        'spu' => $identityData['spu'],
        'meta_name' => $identityData['meta_name'],
        'meta_description' => $identityData['meta_description'],
        'meta_keywords' => $identityData['meta_keywords'],
        'visibility' => 'catalog_search',
        'store_ids' => [0],
        'brand_id' => $offerBrandId,
        'supplier_id' => $supplierId,
        'supplier_product_url' => (string)$offer['source_url'],
        'supplier_sku' => $offerId,
        'supplier_product_name' => $title,
        'supplier_currency' => 'CNY',
        'supplier_moq' => $offer['minimum_order_quantity'] ?? null,
        'category_assignments' => [['category_id' => $categoryId, 'position' => 0]],
        'attributes' => array_merge(
            $eavRows,
            hanfu1688BasicRows(
                $identityData,
                trim((string)($offer['short_title'] ?? '')) ?: $title,
                $descriptionHtml,
                $offerBrandName,
                $offerBrandCode,
            ),
        ),
        'media_assignments' => $media['assignments'],
        'prices' => $catalog['prices'],
        'inventory' => $catalog['inventory'],
    ];
    $priceMinor = hanfu1688YuanToFen($offer['price'] ?? null);
    if ($priceMinor !== null) {
        $payload['price_minor'] = $priceMinor;
        $payload['currency'] = 'CNY';
        $payload['supplier_unit_price_minor'] = $priceMinor;
    } else {
        $missingPrices[] = $offerId;
        $payload['quote_only'] = true;
    }
    if (($offer['weight_kg'] ?? null) !== null) {
        $payload['weight'] = (float)$offer['weight_kg'];
    }

    if ($existing === null) {
        $result = $commands->execute(ProductAdminCommand::fromArray([
            'action' => ProductAdminCommand::ACTION_CREATE,
            'website_id' => $websiteId,
            'request_hash' => hash('sha256', 'hanfu-1688:create:eav:' . $sourceCode . ':' . $offerId),
            'actor_id' => 0,
            'payload' => $payload,
        ]));
    } else {
        if (strtolower(trim((string)$existing->getData('product_type'))) !== 'configurable') {
            $errors[] = [
                'offer_id' => $offerId,
                'error_code' => 'hanfu_1688_legacy_product_requires_reimport',
                'message' => 'legacy simple product must be cleaned and recreated through the EAV importer',
            ];
            continue;
        }
        $globalProductUuid = trim((string)$existing->getData(Product::schema_fields_GLOBAL_PRODUCT_UUID));
        $identity = $identities->resolveProductByUuid($globalProductUuid);
        if ($identity === null) {
            $errors[] = [
                'offer_id' => $offerId,
                'error_code' => 'hanfu_1688_product_identity_missing',
                'message' => $globalProductUuid,
            ];
            continue;
        }

        $existingByKey = [];
        foreach ($offerRepository->listByProductIds($websiteId, [(int)$existing->getId()]) as $existingOffer) {
            $key = trim((string)($existingOffer['combination_key'] ?? ''));
            if ($key !== '') {
                $existingByKey[$key] = $existingOffer;
            }
        }
        $priceBySku = [];
        foreach ((array)$catalog['prices'] as $priceRow) {
            if (is_array($priceRow) && trim((string)($priceRow['sku'] ?? '')) !== '') {
                $priceBySku[(string)$priceRow['sku']] = $priceRow;
            }
        }
        $matrixRows = [];
        foreach ((array)$catalog['matrix_rows'] as $matrixRow) {
            $existingOffer = $existingByKey[(string)$matrixRow['combination_key']] ?? null;
            if (is_array($existingOffer)) {
                $matrixRow['global_offer_uuid'] = (string)($existingOffer['global_offer_uuid'] ?? '');
                $matrixRow['offer_version'] = (int)($existingOffer['publish_version'] ?? 0);
                $matrixRow['identity_version'] = (int)($existingOffer['identity_version'] ?? 0);
            }
            $variantPrice = $priceBySku[(string)$matrixRow['sku']] ?? null;
            if (is_array($variantPrice)) {
                $matrixRow['scope_state'] = 'explicit';
                $matrixRow['currency'] = 'CNY';
                $matrixRow['amount_minor'] = (int)$variantPrice['amount_minor'];
            }
            $matrixRows[] = $matrixRow;
        }
        $payload['offer_matrix'] = [
            'axes' => $catalog['axes'],
            'sku_prefix' => $sku,
            'currency' => 'CNY',
            'rows' => $matrixRows,
        ];
        $payload['local_version'] = (int)$existing->getData(Product::schema_fields_PUBLISH_VERSION);
        $result = $commands->execute(ProductAdminCommand::fromArray([
            'action' => ProductAdminCommand::ACTION_SAVE,
            'website_id' => $websiteId,
            'global_product_uuid' => $globalProductUuid,
            'expected_version' => $identity->version,
            'request_hash' => hash('sha256', 'hanfu-1688:save:eav:' . $runId . ':' . $offerId),
            'actor_id' => 0,
            'payload' => $payload,
        ]));
    }
    if (!$result->success) {
        $errors[] = [
            'offer_id' => $offerId,
            'error_code' => $result->errorCode,
            'message' => $result->message,
            'details' => $result->data,
        ];
        continue;
    }
    $productId = (int)($result->data['product_id'] ?? ($existing?->getId() ?? 0));
    foreach ([
        'type_configuration',
        'available_colors',
        'available_sizes',
        'source_public_specs',
        'source_variant_combinations',
    ] as $legacyAttributeCode) {
        $attributes->deleteOverlay(
            $websiteId,
            0,
            'product',
            $productId,
            $legacyAttributeCode,
            '',
        );
    }
    $row = [
        'offer_id' => $offerId,
        'product_id' => $productId,
        'sku' => $sku,
        'slug' => $identityData['slug'],
        'title' => $title,
        'brand_code' => $offerBrandCode,
        'brand_name' => $offerBrandName,
        'brand_source' => $offerBrandIdentity['source'] ? 'offer' : 'fallback',
        'matched_hanfu_term' => $classification['matched_term'],
        'media_count' => count($media['assignments']),
        'variant_media_count' => count(array_filter(
            $media['assignments'],
            static fn(array $assignment): bool => ($assignment['role'] ?? '') === 'variant',
        )),
        'detail_media_count' => count(array_filter(
            $detailImageUrls,
            static fn(mixed $url): bool => isset($media['asset_ids_by_url'][(string)$url]),
        )),
        'skipped_detail_media_count' => count($skippedDetailMedia),
        'skipped_detail_media' => $skippedDetailMedia,
        'preserved_legacy_product_ids' => $preservedLegacyProductIds,
    ];
    if ($existing === null) {
        $created[] = $row;
    } else {
        $updated[] = $row;
    }
    $successfulProducts[] = $row;
}

$publishTargets = $publishAll
    ? $successfulProducts
    : ($publishFirst ? array_slice($successfulProducts, 0, 1) : []);
$publications = [];
foreach ($publishTargets as $publishTarget) {
    $product = $products->findById($websiteId, (int)$publishTarget['product_id'])
        ?? throw new RuntimeException('hanfu_1688_publish_product_readback_missing');
    $identity = $identities->resolveProductByUuid((string)$product->getData(Product::schema_fields_GLOBAL_PRODUCT_UUID))
        ?? throw new RuntimeException('hanfu_1688_publish_identity_readback_missing');
    $validate = $commands->execute(ProductAdminCommand::fromArray([
        'action' => ProductAdminCommand::ACTION_VALIDATE,
        'website_id' => $websiteId,
        'global_product_uuid' => $identity->globalProductUuid,
        'expected_version' => $identity->version,
        'request_hash' => hash('sha256', 'hanfu-1688:validate:v2:' . $runId . ':' . $publishTarget['offer_id']),
        'actor_id' => 0,
        'payload' => ['locale' => 'zh_Hans_CN', 'currency' => 'CNY'],
    ]));
    $publication = [
        'offer_id' => (string)$publishTarget['offer_id'],
        'product_id' => (int)$publishTarget['product_id'],
        'validation' => $validate->toArray(),
    ];
    if ($validate->success && !empty($validate->data['diagnostics']['valid'])) {
        $product = $products->findById($websiteId, (int)$publishTarget['product_id'])
            ?? throw new RuntimeException('hanfu_1688_publish_product_readback_missing');
        $identity = $identities->resolveProductByUuid($identity->globalProductUuid)
            ?? throw new RuntimeException('hanfu_1688_publish_identity_readback_missing');
        $published = $commands->execute(ProductAdminCommand::fromArray([
            'action' => ProductAdminCommand::ACTION_PUBLISH,
            'website_id' => $websiteId,
            'global_product_uuid' => $identity->globalProductUuid,
            'expected_version' => $identity->version,
            'request_hash' => hash('sha256', 'hanfu-1688:publish:v2:' . $runId . ':' . $publishTarget['offer_id']),
            'actor_id' => 0,
            'payload' => [
                'local_version' => (int)$product->getData(Product::schema_fields_PUBLISH_VERSION),
                'locale' => 'zh_Hans_CN',
                'currency' => 'CNY',
            ],
        ]));
        $publication['publish'] = $published->toArray();
        if (!$published->success) {
            $errors[] = [
                'offer_id' => (string)$publishTarget['offer_id'],
                'error_code' => $published->errorCode ?? 'hanfu_1688_publish_failed',
                'message' => $published->message,
                'details' => $published->data,
            ];
        }
    } else {
        $errors[] = [
            'offer_id' => (string)$publishTarget['offer_id'],
            'error_code' => $validate->errorCode ?? 'hanfu_1688_validation_failed',
            'message' => $validate->message,
            'details' => $validate->data,
        ];
    }
    $publications[] = $publication;
}

$report = [
    'contract' => 'hanfu.1688.import.v1',
    'website_id' => $websiteId,
    'run_id' => $runId,
    'snapshot_digest' => (string)$snapshot['snapshot_digest'],
    'source_code' => $sourceCode,
    'supplier_store_url' => [
        'before' => $supplierStoreUrlBefore,
        'after' => $canonicalShopUrl,
        'updated' => $supplierStoreUrlBefore !== $canonicalShopUrl,
    ],
    'source_total' => (int)$collected['terminal_proof']['expected_total'],
    'processed' => $processed,
    'created' => count($created),
    'updated' => count($updated),
    'rejected_non_hanfu' => count($rejected),
    'errors' => $errors,
    'created_products' => $created,
    'updated_products' => $updated,
    'rejected_products' => $rejected,
    'missing_price_offer_ids' => $missingPrices,
    'accepted_detail_enrichment' => $collected['accepted_detail_enrichment'] ?? null,
    'accepted_description_enrichment' => $collected['accepted_description_enrichment'] ?? null,
    'first_product_publish' => $publications[0] ?? null,
    'product_publications' => $publications,
    'terminal_status' => $errors === [] ? 'applied' : 'applied_with_errors',
];
$artifacts->writeJson($runId, 'import-report.json', $report);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($errors === [] ? 0 : 1);

/** @param array<string,int> $categoryByPath */
function hanfu1688CategoryId(string $title, array $categoryByPath, int $fallback): int
{
    foreach ([
        ['needles' => ['马面'], 'path' => '/women/mamian'],
        ['needles' => ['婚', '嫁衣', '秀禾'], 'path' => '/sets/wedding'],
        ['needles' => ['儿童', '女童', '男童', '孩童'], 'path' => '/kids/girls'],
        ['needles' => ['圆领', '男士', '男款'], 'path' => '/men/yuanling'],
        ['needles' => ['裙', '女款', '女士'], 'path' => '/women/ruqun'],
    ] as $rule) {
        foreach ($rule['needles'] as $needle) {
            if (str_contains($title, $needle)) {
                return $categoryByPath[$rule['path']] ?? $fallback;
            }
        }
    }
    return $fallback;
}

/**
 * Resolve source option codes through formal EAV metadata before Product/Offer/media writes.
 * Exact code or ID wins; when a historical code changed, the earliest same-label option is reused.
 *
 * @param array<string,mixed> $catalog
 * @return array<string,mixed>
 */
function hanfu1688CanonicalVariantCatalog(
    ProductAttributeMetadataCatalog $attributeMetadata,
    ProductVariantMatrixService $variantMatrix,
    array $catalog,
    string $skuPrefix,
): array {
    $identityOptions = [];
    $labelOptions = [];
    foreach ($attributeMetadata->editorCatalog() as $set) {
        foreach (is_array($set['groups'] ?? null) ? $set['groups'] : [] as $group) {
            foreach (is_array($group['attributes'] ?? null) ? $group['attributes'] : [] as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $attributeCode = strtolower(trim((string)($attribute['code'] ?? '')));
                if ($attributeCode === '') {
                    continue;
                }
                foreach (is_array($attribute['options'] ?? null) ? $attribute['options'] : [] as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $canonicalValue = trim((string)($option['value'] ?? $option['id'] ?? ''));
                    if ($canonicalValue === '') {
                        continue;
                    }
                    foreach (['value', 'id', 'code'] as $identityField) {
                        $identity = trim((string)($option[$identityField] ?? ''));
                        if ($identity !== '' && !isset($identityOptions[$attributeCode][$identity])) {
                            $identityOptions[$attributeCode][$identity] = $canonicalValue;
                        }
                    }
                    $label = trim((string)preg_replace(
                        '/\s+/u',
                        ' ',
                        trim((string)($option['label'] ?? '')),
                    ));
                    if ($label !== '' && !isset($labelOptions[$attributeCode][$label])) {
                        $labelOptions[$attributeCode][$label] = $canonicalValue;
                    }
                }
            }
        }
    }

    $sourceAxes = is_array($catalog['axes'] ?? null) ? $catalog['axes'] : [];
    $canonicalAxes = [];
    $sourceValueMap = [];
    foreach ($sourceAxes as $axis) {
        if (!is_array($axis)) {
            throw new InvalidArgumentException('hanfu_1688_variant_axis_invalid');
        }
        $attributeCode = strtolower(trim((string)($axis['code'] ?? '')));
        $canonicalOptions = [];
        foreach (is_array($axis['options'] ?? null) ? $axis['options'] : [] as $option) {
            $option = is_array($option) ? $option : ['value' => $option];
            $sourceValue = trim((string)($option['value'] ?? $option['code'] ?? ''));
            $label = trim((string)preg_replace(
                '/\s+/u',
                ' ',
                trim((string)($option['label'] ?? $option['name'] ?? $sourceValue)),
            ));
            $canonicalValue = $identityOptions[$attributeCode][$sourceValue]
                ?? $labelOptions[$attributeCode][$label]
                ?? '';
            if ($attributeCode === '' || $sourceValue === '' || $canonicalValue === '') {
                throw new InvalidArgumentException(
                    'hanfu_1688_eav_option_unresolved:' . $attributeCode . ':' . $label,
                );
            }
            $sourceValueMap[$attributeCode][$sourceValue] = $canonicalValue;
            $canonicalOptions[$canonicalValue] = [
                'value' => $canonicalValue,
                'label' => $label,
            ];
        }
        if ($canonicalOptions === []) {
            throw new InvalidArgumentException('hanfu_1688_variant_axis_options_missing');
        }
        $canonicalAxes[] = [
            'code' => $attributeCode,
            'label' => trim((string)($axis['label'] ?? $attributeCode)),
            'options' => array_values($canonicalOptions),
        ];
    }
    $canonicalAxes = $attributeMetadata->canonicalizeVariantAxes($canonicalAxes);

    $canonicalizeCombination = static function (array $sourceCombination) use (
        $sourceValueMap,
        $attributeMetadata,
    ): array {
        $canonical = [];
        foreach ($sourceCombination as $attributeCode => $sourceValue) {
            $attributeCode = strtolower(trim((string)$attributeCode));
            $sourceValue = trim((string)$sourceValue);
            $canonicalValue = $sourceValueMap[$attributeCode][$sourceValue] ?? '';
            if ($canonicalValue === '') {
                throw new InvalidArgumentException(
                    'hanfu_1688_variant_value_unresolved:' . $attributeCode . ':' . $sourceValue,
                );
            }
            $canonical[$attributeCode] = $canonicalValue;
        }

        return $attributeMetadata->canonicalizeVariantCombination($canonical);
    };

    $matrixRows = [];
    $canonicalOverrides = [];
    foreach ($variantMatrix->generate(
        $sourceAxes,
        $skuPrefix,
        is_array($catalog['sku_overrides'] ?? null) ? $catalog['sku_overrides'] : [],
    ) as $row) {
        $combination = $canonicalizeCombination((array)$row['combination']);
        $combinationKey = $variantMatrix->combinationKey($combination);
        $row['combination'] = $combination;
        $row['combination_key'] = $combinationKey;
        $matrixRows[] = $row;
        $canonicalOverrides[$combinationKey] = (string)$row['sku'];
    }

    $variantMedia = [];
    foreach (is_array($catalog['variant_media'] ?? null) ? $catalog['variant_media'] : [] as $row) {
        if (!is_array($row) || !is_array($row['combination'] ?? null)) {
            continue;
        }
        $row['combination'] = $canonicalizeCombination($row['combination']);
        $variantMedia[] = $row;
    }

    $productValues = is_array($catalog['product_values'] ?? null)
        ? $catalog['product_values']
        : [];
    foreach ($canonicalAxes as $axis) {
        $productValues[(string)$axis['code']] = array_values(array_map(
            static fn(array $option): string => (string)$option['value'],
            (array)$axis['options'],
        ));
    }

    $catalog['axes'] = $canonicalAxes;
    $catalog['sku_overrides'] = $canonicalOverrides;
    $catalog['matrix_rows'] = $matrixRows;
    $catalog['variant_media'] = $variantMedia;
    $catalog['product_values'] = $productValues;

    return $catalog;
}

function hanfu1688YuanToFen(mixed $amount): ?int
{
    if ($amount === null || trim((string)$amount) === '') {
        return null;
    }
    $amount = trim((string)$amount);
    if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $amount, $match) !== 1) {
        throw new InvalidArgumentException('hanfu_1688_price_invalid');
    }
    return ((int)$match[1] * 100) + (int)str_pad($match[2] ?? '', 2, '0');
}

/**
 * @return array{sku_prefix:string,slug:string,spu:string,meta_name:string,meta_description:string,meta_keywords:string}
 */
function hanfu1688Identity(string $brandCode, string $brandName, string $title): array
{
    $brandSlug = hanfu1688SemanticSlug($brandName !== '' ? $brandName : $brandCode, 24);
    $titleSlug = hanfu1688SemanticSlug($title, 58);
    $semanticHash = substr(hash('sha256', $brandCode . '|' . $title), 0, 8);
    $slug = trim($brandSlug . '-' . $titleSlug . '-' . $semanticHash, '-');
    $brandSku = strtoupper(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $brandCode), '-'));
    if ($brandSku === '') {
        $brandSku = 'HF';
    }
    if (strlen($brandSku) > 16) {
        $brandSku = rtrim(substr($brandSku, 0, 16), '-');
    }
    // Short stable SKU prefix: brand + content fingerprint (no title pinyin).
    $skuPrefix = $brandSku . '-' . strtoupper($semanticHash);
    $metaName = mb_substr($title . ' | ' . $brandName . '汉服', 0, 70);
    $metaDescription = mb_substr(
        $brandName . '正品汉服：' . $title
        . '。提供清晰颜色与尺码选择、实拍商品图片及库存信息。',
        0,
        155,
    );
    $keywords = [$brandName, '汉服', '传统服饰'];
    foreach (['马面裙', '明制', '宋制', '唐制', '齐胸襦裙', '汉元素', '套装'] as $keyword) {
        if (str_contains($title, $keyword)) {
            $keywords[] = $keyword;
        }
    }

    return [
        'sku_prefix' => $skuPrefix,
        'slug' => $slug,
        'spu' => $skuPrefix,
        'meta_name' => $metaName,
        'meta_description' => $metaDescription,
        'meta_keywords' => implode(',', array_values(array_unique(array_filter($keywords)))),
    ];
}

function hanfu1688SemanticSlug(string $value, int $maxLength): string
{
    $source = trim($value);
    if (class_exists(Transliterator::class)) {
        $transliterator = Transliterator::create('Han-Latin; Latin-ASCII; Lower()');
        if ($transliterator !== null) {
            $value = (string)$transliterator->transliterate($source);
        }
    }
    $value = strtolower($value);
    $value = trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    if ($value === '') {
        $value = 'hanfu-' . substr(hash('sha256', $source), 0, 10);
    }
    return rtrim(substr($value, 0, $maxLength), '-');
}

/** @return list<array<string,mixed>> */
function hanfu1688BasicRows(
    array $identity,
    string $shortDescription,
    string $description,
    string $brandName,
    string $brandCode,
): array {
    $values = [
        'short_description' => $shortDescription,
        'description' => $description,
        'slug' => (string)$identity['slug'],
        'spu' => (string)$identity['spu'],
        'meta_name' => (string)$identity['meta_name'],
        'meta_description' => (string)$identity['meta_description'],
        'meta_keywords' => (string)$identity['meta_keywords'],
        'visibility' => 'catalog_search',
        'brand' => trim($brandName),
        'brand_code' => trim($brandCode),
    ];
    $rows = [];
    foreach ($values as $code => $value) {
        if ($value === '') {
            continue;
        }
        foreach (['', 'zh_Hans_CN'] as $locale) {
            $rows[] = [
                'attribute_code' => $code,
                'store_id' => 0,
                'entity_type' => 'product',
                'locale' => $locale,
                'scope_state' => 'explicit',
                'value_type' => 'string',
                'value' => $value,
            ];
        }
    }
    return $rows;
}
