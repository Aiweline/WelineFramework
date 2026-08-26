<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryCatalogCopyCapability;
use Weline\Inventory\Api\InventoryCatalogCopyCapabilityInterface;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\Data\ProductValidationResult;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\PriceRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\StoreOfferRepository;
use Weline\Product\Repository\StoreProductRepository;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Transactional Product-owned admin write boundary.
 *
 * HTTP Controllers and Resources only map requests to this service. Global
 * identity and Website projection versions stay separate and are both checked.
 */
final class ProductAdminCommandService implements ProductAdminCommandInterface
{
    private ?InventoryCatalogCopyCapabilityInterface $resolvedInventory = null;
    private bool $inventoryResolved = false;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly ProductIdentityV2Service $identities,
        private readonly ProductGovernanceService $governance,
        private readonly ProductProviderRegistry $providers,
        private readonly ProductPublishValidator $publishValidator,
        private readonly ProductVariantMatrixService $variantMatrix,
        private readonly ProductAttributeMetadataCatalog $attributeMetadata,
        private readonly ProductAdminReadService $reader,
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly AttributeValueRepository $attributes,
        private readonly PriceRepository $prices,
        private readonly CategoryRepository $categories,
        private readonly CategoryLinkRepository $categoryLinks,
        private readonly MediaRepository $media,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
        private readonly FileAssetManagerInterface $fileAssets,
        private readonly StoreProductRepository $storeProducts,
        private readonly StoreOfferRepository $storeOffers,
        private readonly StoreCatalogInterface $storeCatalog,
        ?InventoryCatalogCopyCapabilityInterface $inventory = null,
    ) {
        $this->resolvedInventory = $inventory;
        $this->inventoryResolved = $inventory !== null;
    }

    public function execute(ProductAdminCommand $command): ProductAdminResult
    {
        try {
            return match ($command->action) {
                ProductAdminCommand::ACTION_CREATE => $this->create($command),
                ProductAdminCommand::ACTION_SAVE => $this->save($command),
                ProductAdminCommand::ACTION_VALIDATE => $this->validate($command),
                ProductAdminCommand::ACTION_PUBLISH => $this->publish($command),
                ProductAdminCommand::ACTION_DISABLE => $this->transition($command, 'disabled'),
                ProductAdminCommand::ACTION_ARCHIVE => $this->transition($command, 'archived'),
                ProductAdminCommand::ACTION_CHANGE_TYPE => $this->changeType($command),
                ProductAdminCommand::ACTION_SHARE => $this->share($command),
                ProductAdminCommand::ACTION_TRANSFER_INITIATE => $this->initiateTransfer($command),
                ProductAdminCommand::ACTION_TRANSFER_CONFIRM => $this->confirmTransfer($command),
                ProductAdminCommand::ACTION_RENAME_SKU => $this->renameSku($command),
                default => throw new \InvalidArgumentException('product_admin_action_invalid'),
            };
        } catch (ProductV2ConflictException $exception) {
            return ProductAdminResult::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        } catch (CatalogConflictException $exception) {
            return ProductAdminResult::fail(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->context(),
            );
        } catch (\InvalidArgumentException $exception) {
            $code = trim($exception->getMessage());
            if (!preg_match('/^[a-z][a-z0-9_.-]{2,127}$/', $code)) {
                $code = 'product_admin_invalid_argument';
            }
            return ProductAdminResult::fail($code, $exception->getMessage());
        } catch (Throwable $exception) {
            return ProductAdminResult::fail(
                'product_admin_internal_error',
                (string)__('商品操作失败，请稍后重试'),
                ['exception' => $exception::class],
            );
        }
    }

    private function create(ProductAdminCommand $command): ProductAdminResult
    {
        $payload = $command->payload;
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('product_name_required');
        }
        $productType = strtolower(trim((string)($payload['product_type'] ?? 'simple')));
        $provider = $this->providers->getByType($productType, true)
            ?? throw new \InvalidArgumentException('product_provider_unavailable');
        $providerV2 = $provider instanceof ProductProviderV2Interface
            ? $provider
            : new ProductProviderV1Adapter($provider);
        $definition = $providerV2->getDefinition();
        $offerSpecs = $this->offerSpecs($productType, $payload);
        $offerCount = count($offerSpecs);
        if ($offerCount < $definition->minimumOffers
            || ($definition->maximumOffers !== null && $offerCount > $definition->maximumOffers)
        ) {
            throw new \InvalidArgumentException('product_offer_cardinality_invalid');
        }
        $selectedStoreIds = $this->selectedStoreIds($command->websiteId, $payload);

        /** @var array{identity:array<string,mixed>,offer_identities:list<array<string,mixed>>,product_id:int} $result */
        $result = $this->transactions->run(
            $this->connectionFactory,
            function () use (
                $command,
                $payload,
                $name,
                $productType,
                $provider,
                $definition,
                $offerSpecs,
                $selectedStoreIds,
            ): array {
                $identity = $this->identities->createProduct(
                    $command->websiteId,
                    $provider->getCode(),
                    $productType,
                    $command->requestHash,
                );
                $existing = $this->products->findByGlobalUuid(
                    $command->websiteId,
                    $identity->globalProductUuid,
                );
                if ($existing !== null) {
                    return [
                        'identity' => $identity->toArray(),
                        'offer_identities' => array_map(
                            static fn($offerIdentity): array => $offerIdentity->toArray(),
                            $this->identities->listOffers($identity->globalProductUuid, false),
                        ),
                        'product_id' => (int)$existing->getId(),
                    ];
                }

                $offerIdentities = [];
                foreach ($offerSpecs as $index => $spec) {
                    $offerIdentities[] = $this->identities->createOffer(
                        $identity->globalProductUuid,
                        (string)$spec['sku'],
                        $this->subRequestHash($command->requestHash, 'offer:' . $index),
                    );
                }

                $product = $this->products->create($command->websiteId, [
                    Product::schema_fields_SKU => $offerIdentities[0]->sku,
                    Product::schema_fields_GLOBAL_PRODUCT_UUID => $identity->globalProductUuid,
                    'product_code' => $identity->productCode,
                    'owner_website_id' => $identity->ownerWebsiteId,
                    'provider_code' => $identity->providerCode,
                    'product_type' => $identity->productType,
                    'identity_version' => $identity->version,
                    'source_website_id' => null,
                    'source_version' => 0,
                ]);
                $productId = (int)$product->getId();

                $localOffers = [];
                foreach ($offerSpecs as $index => $spec) {
                    $offerIdentity = $offerIdentities[$index];
                    $localOffers[] = $this->offers->create($command->websiteId, [
                        Offer::schema_fields_PRODUCT_ID => $productId,
                        Offer::schema_fields_GLOBAL_OFFER_UUID => $offerIdentity->globalOfferUuid,
                        'sku' => $offerIdentity->sku,
                        'identity_version' => $offerIdentity->version,
                        'combination_key' => (string)($spec['combination_key'] ?? ''),
                        'is_default' => $index === 0 ? 1 : 0,
                        'requires_shipping' => $definition->requiresShipping ? 1 : 0,
                        'type_config_json' => $this->json($spec['configuration'] ?? []),
                    ]);
                }

                $this->attributes->writeTyped(
                    $command->websiteId,
                    0,
                    'product',
                    $productId,
                    'name',
                    (string)($payload['locale'] ?? ''),
                    'string',
                    $name,
                    true,
                );
                $typeConfiguration = $payload['type_configuration'] ?? [];
                if (!is_array($typeConfiguration)) {
                    throw new \InvalidArgumentException('product_type_configuration_invalid');
                }
                if ($productType === 'configurable') {
                    if (isset($payload['axes'])) {
                        $typeConfiguration['axes'] = $payload['axes'];
                    }
                    $typeConfiguration['sku_prefix'] = trim((string)(
                        $payload['sku_prefix'] ?? $payload['sku'] ?? ''
                    ));
                }
                $this->attributes->writeTyped(
                    $command->websiteId,
                    0,
                    'product',
                    $productId,
                    'type_configuration',
                    '',
                    'json',
                    $typeConfiguration,
                );

                foreach ($selectedStoreIds as $storeId) {
                    $this->storeProducts->select($command->websiteId, $storeId, $productId, true);
                    foreach ($localOffers as $localOffer) {
                        $this->storeOffers->select(
                            $command->websiteId,
                            $storeId,
                            (int)$localOffer->getId(),
                            true,
                        );
                    }
                }
                $this->writeCreatePrices($command->websiteId, $localOffers, $payload);

                return [
                    'identity' => $identity->toArray(),
                    'offer_identities' => array_map(
                        static fn($offerIdentity): array => $offerIdentity->toArray(),
                        $offerIdentities,
                    ),
                    'product_id' => $productId,
                ];
            },
        );

        return ProductAdminResult::ok($result, (string)__('商品草稿已创建'));
    }

    private function save(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $product = $this->localProduct($command, $identity->globalProductUuid);
        if ((string)$product->getData(Product::schema_fields_STATUS) === Product::STATUS_ARCHIVED) {
            throw new ProductV2ConflictException(
                'product_archived_readonly',
                (string)__('已归档商品只能查看，不能继续编辑'),
            );
        }
        $productId = (int)$product->getId();
        $localVersion = $this->localVersion($command);
        $isOwner = $identity->ownerWebsiteId === $command->websiteId;
        $payload = $command->payload;
        foreach (['sku', 'offers', 'axes', 'offer_matrix', 'product_type', 'provider_code'] as $structuralField) {
            if (!$isOwner && array_key_exists($structuralField, $payload)) {
                throw new ProductV2ConflictException(
                    'product_structure_owner_required',
                    (string)__('复制站不能修改 SKU、类型或规格结构'),
                    ['field' => $structuralField, 'owner_website_id' => $identity->ownerWebsiteId],
                );
            }
        }

        $matrixPayload = null;
        if (array_key_exists('offer_matrix', $payload)) {
            if (!is_array($payload['offer_matrix'])) {
                throw new \InvalidArgumentException('product_offer_matrix_invalid');
            }
            if ($identity->productType !== 'configurable') {
                throw new \InvalidArgumentException('product_offer_matrix_type_unsupported');
            }
            $matrixPayload = $payload['offer_matrix'];
            $axes = $matrixPayload['axes'] ?? null;
            if (!is_array($axes)) {
                throw new \InvalidArgumentException('variant_axes_invalid');
            }
            $typeConfiguration = $payload['type_configuration'] ?? [];
            if (!is_array($typeConfiguration)) {
                throw new \InvalidArgumentException('product_type_configuration_invalid');
            }
            $skuPrefix = trim((string)($matrixPayload['sku_prefix'] ?? ''));
            if ($skuPrefix === '') {
                throw new \InvalidArgumentException('variant_sku_prefix_invalid');
            }
            $typeConfiguration['axes'] = $axes;
            $typeConfiguration['sku_prefix'] = $skuPrefix;
            $payload['type_configuration'] = $typeConfiguration;
        }

        $matrixStoreIds = [];
        if ($matrixPayload !== null) {
            if (array_key_exists('store_ids', $payload)) {
                $matrixStoreIds = $this->selectedStoreIds($command->websiteId, $payload);
            } else {
                foreach ($this->activeStoreIds($command->websiteId) as $storeId) {
                    if ($this->storeProducts->isSelected($command->websiteId, $storeId, $productId)) {
                        $matrixStoreIds[] = $storeId;
                    }
                }
            }
        }

        $inventoryRows = $this->inventoryRows(
            $command->websiteId,
            $identity->productType,
            $productId,
            $payload,
        );
        $inventory = $inventoryRows === [] ? null : $this->inventory();
        if ($inventoryRows !== [] && $inventory === null) {
            throw new \InvalidArgumentException('product_inventory_capability_unavailable');
        }

        /** @var array{product:Product,offer_matrix:?array<string,mixed>,inventory_updated:int} $result */
        $execute = function () use (
            $command,
            $identity,
            $product,
            $productId,
            $localVersion,
            $payload,
            $matrixPayload,
            $matrixStoreIds,
            $inventoryRows,
            $inventory,
        ): array {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use (
                    $command,
                    $identity,
                    $product,
                    $productId,
                    $localVersion,
                    $payload,
                    $matrixPayload,
                    $matrixStoreIds,
                    $inventoryRows,
                    $inventory,
                ): array {
                    $this->writeAttributes($command->websiteId, $productId, $payload);
                    $matrixResult = $matrixPayload === null
                        ? null
                        : $this->reconcileOfferMatrix(
                            $command,
                            $identity->globalProductUuid,
                            $productId,
                            $matrixPayload,
                            $matrixStoreIds,
                        );
                    $this->writePrices($command->websiteId, $productId, $payload);
                    $this->writeTaxonomyAndMedia($command->websiteId, $productId, $payload);
                    if (array_key_exists('store_ids', $payload)) {
                        $selected = $this->selectedStoreIds($command->websiteId, $payload);
                        $selectedLookup = array_fill_keys($selected, true);
                        $offers = $this->offers->listByProductIds($command->websiteId, [$productId]);
                        foreach ($this->activeStoreIds($command->websiteId) as $storeId) {
                            $enabled = isset($selectedLookup[$storeId]);
                            $this->storeProducts->select(
                                $command->websiteId,
                                $storeId,
                                $productId,
                                $enabled,
                            );
                            foreach ($offers as $offer) {
                                $offerEnabled = $enabled
                                    && !in_array((string)($offer['status'] ?? ''), ['disabled', 'archived'], true);
                                $this->storeOffers->select(
                                    $command->websiteId,
                                    $storeId,
                                    (int)($offer['offer_id'] ?? 0),
                                    $offerEnabled,
                                );
                            }
                        }
                    }
                    if ($inventoryRows !== [] && $inventory !== null) {
                        $this->writeInventoryRows($command, $inventoryRows, $inventory);
                    }
                    $fields = [
                        'identity_version' => $identity->version,
                        'owner_website_id' => $identity->ownerWebsiteId,
                    ];
                    if (is_array($matrixResult) && ($matrixResult['primary_sku'] ?? '') !== '') {
                        $fields[Product::schema_fields_SKU] = (string)$matrixResult['primary_sku'];
                    }
                    $updated = $this->products->updateVersioned(
                        $command->websiteId,
                        (int)$product->getId(),
                        $localVersion,
                        $fields,
                    );
                    return [
                        'product' => $updated,
                        'offer_matrix' => $matrixResult,
                        'inventory_updated' => count($inventoryRows),
                    ];
                },
            );
        };
        $result = $inventory !== null
            ? $inventory->transactional($execute)
            : $execute();

        $updated = $result['product'];
        return ProductAdminResult::ok([
            'identity' => $identity->toArray(),
            'product_id' => (int)$updated->getId(),
            'local_version' => (int)$updated->getData(Product::schema_fields_PUBLISH_VERSION),
            'offer_matrix' => $result['offer_matrix'],
            'inventory_updated' => $result['inventory_updated'],
        ], (string)__('商品草稿已保存'));
    }

    /**
     * @param array<string,mixed> $matrix
     * @param list<int> $selectedStoreIds
     * @return array<string,mixed>
     */
    private function reconcileOfferMatrix(
        ProductAdminCommand $command,
        string $globalProductUuid,
        int $productId,
        array $matrix,
        array $selectedStoreIds,
    ): array {
        $axes = $matrix['axes'] ?? [];
        $rows = $matrix['rows'] ?? [];
        $skuPrefix = trim((string)($matrix['sku_prefix'] ?? ''));
        if (!is_array($axes) || !is_array($rows) || $skuPrefix === '') {
            throw new \InvalidArgumentException('product_offer_matrix_invalid');
        }
        $existingOffers = $this->offers->listByProductIds($command->websiteId, [$productId]);
        $plan = $this->variantMatrix->reconcile($axes, $skuPrefix, $rows, $existingOffers);
        $provider = $this->providers->getByType('configurable', true)
            ?? throw new \InvalidArgumentException('product_provider_unavailable');
        $definition = ($provider instanceof ProductProviderV2Interface
            ? $provider
            : new ProductProviderV1Adapter($provider))->getDefinition();
        $primaryKey = (string)($plan['desired'][0]['combination_key'] ?? '');

        foreach ($plan['update'] as $row) {
            $uuid = (string)$row['global_offer_uuid'];
            $identity = $this->identities->resolveOfferByUuid($uuid)
                ?? throw new \InvalidArgumentException('offer_v2_identity_not_found');
            if ($identity->globalProductUuid !== $globalProductUuid) {
                throw new \InvalidArgumentException('offer_product_identity_mismatch');
            }
            if ($identity->version !== (int)$row['identity_version']) {
                throw new ProductV2ConflictException(
                    'offer_version_conflict',
                    (string)__('Offer 身份版本冲突，请刷新后重试'),
                    ['expected' => (int)$row['identity_version'], 'actual' => $identity->version],
                );
            }
            if ($identity->sku !== (string)$row['sku']) {
                $identity = $this->identities->renameSku(
                    $uuid,
                    (string)$row['sku'],
                    $identity->version,
                    $command->websiteId,
                    $this->subRequestHash($command->requestHash, 'matrix:rename:' . $row['combination_key']),
                );
            }
            if ($identity->status !== 'active') {
                $identity = $this->identities->transitionOfferStatus(
                    $uuid,
                    'active',
                    $identity->version,
                    $command->websiteId,
                    $this->subRequestHash($command->requestHash, 'matrix:activate:' . $row['combination_key']),
                );
            }

            $local = $this->offers->findByGlobalUuid($command->websiteId, $uuid)
                ?? throw new \InvalidArgumentException('offer_website_projection_not_found');
            if ((int)$local->getData(Offer::schema_fields_PUBLISH_VERSION) !== (int)$row['offer_version']) {
                throw new ProductV2ConflictException(
                    'variant_offer_version_conflict',
                    (string)__('Offer 本地版本冲突，请刷新后重试'),
                );
            }
            if ((string)$local->getData(Offer::schema_fields_STATUS) === 'archived') {
                throw new ProductV2ConflictException(
                    'offer_projection_archived',
                    (string)__('已归档 Offer 不能重新加入规格矩阵'),
                );
            }
            $local = $this->offers->updateVersioned(
                $command->websiteId,
                (int)$local->getId(),
                (int)$row['offer_version'],
                [
                    'sku' => $identity->sku,
                    'identity_version' => $identity->version,
                    'combination_key' => (string)$row['combination_key'],
                    'is_default' => (string)$row['combination_key'] === $primaryKey ? 1 : 0,
                    'requires_shipping' => $definition->requiresShipping ? 1 : 0,
                    'type_config_json' => $this->json(['combination' => $row['combination']]),
                ],
            );
            if ((string)$local->getData(Offer::schema_fields_STATUS) === 'disabled') {
                $local = $this->offers->transition(
                    $command->websiteId,
                    (int)$local->getId(),
                    (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
                    'draft',
                );
            }
            $this->writeMatrixPrice($command->websiteId, (int)$local->getId(), $matrix, $row);
        }

        foreach ($plan['create'] as $row) {
            $identity = $this->identities->createOffer(
                $globalProductUuid,
                (string)$row['sku'],
                $this->subRequestHash($command->requestHash, 'matrix:create:' . $row['combination_key']),
            );
            $local = $this->offers->create($command->websiteId, [
                Offer::schema_fields_PRODUCT_ID => $productId,
                Offer::schema_fields_GLOBAL_OFFER_UUID => $identity->globalOfferUuid,
                'sku' => $identity->sku,
                'identity_version' => $identity->version,
                'combination_key' => (string)$row['combination_key'],
                'is_default' => (string)$row['combination_key'] === $primaryKey ? 1 : 0,
                'requires_shipping' => $definition->requiresShipping ? 1 : 0,
                'type_config_json' => $this->json(['combination' => $row['combination']]),
            ]);
            foreach ($selectedStoreIds as $storeId) {
                $this->storeOffers->select($command->websiteId, $storeId, (int)$local->getId(), true);
            }
            $this->writeMatrixPrice($command->websiteId, (int)$local->getId(), $matrix, $row);
        }

        foreach ($plan['disable'] as $row) {
            $uuid = (string)($row['global_offer_uuid'] ?? '');
            $identity = $this->identities->resolveOfferByUuid($uuid)
                ?? throw new \InvalidArgumentException('offer_v2_identity_not_found');
            if ($identity->status === 'active') {
                $identity = $this->identities->transitionOfferStatus(
                    $uuid,
                    'disabled',
                    $identity->version,
                    $command->websiteId,
                    $this->subRequestHash($command->requestHash, 'matrix:disable:' . ($row['combination_key'] ?? $uuid)),
                );
            }
            $local = $this->offers->findByGlobalUuid($command->websiteId, $uuid)
                ?? throw new \InvalidArgumentException('offer_website_projection_not_found');
            if ((int)$local->getData(Offer::schema_fields_IDENTITY_VERSION) !== $identity->version) {
                $local = $this->offers->updateVersioned(
                    $command->websiteId,
                    (int)$local->getId(),
                    (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
                    ['identity_version' => $identity->version],
                );
            }
            if (!in_array((string)$local->getData(Offer::schema_fields_STATUS), ['disabled', 'archived'], true)) {
                $local = $this->offers->transition(
                    $command->websiteId,
                    (int)$local->getId(),
                    (int)$local->getData(Offer::schema_fields_PUBLISH_VERSION),
                    'disabled',
                );
            }
            foreach ($this->activeStoreIds($command->websiteId) as $storeId) {
                $this->storeOffers->select($command->websiteId, $storeId, (int)$local->getId(), false);
            }
        }

        return [
            'created' => count($plan['create']),
            'updated' => count($plan['update']),
            'disabled' => count($plan['disable']),
            'impact' => $plan['impact'],
            'primary_sku' => (string)($plan['desired'][0]['sku'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $matrix @param array<string,mixed> $row */
    private function writeMatrixPrice(
        int $websiteId,
        int $offerId,
        array $matrix,
        array $row,
    ): void {
        if (!array_key_exists('scope_state', $row) && !array_key_exists('amount_minor', $row)) {
            return;
        }
        $price = [
            'store_id' => (int)($row['store_id'] ?? 0),
            'currency' => (string)($row['currency'] ?? $matrix['currency'] ?? 'CNY'),
            'scope_state' => (string)($row['scope_state'] ?? 'explicit'),
        ];
        if (array_key_exists('amount_minor', $row)) {
            $price['amount_minor'] = $row['amount_minor'];
        }
        $this->writePriceRow($websiteId, $offerId, $price);
    }

    private function validate(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $context = $this->reader->validationContext(
            $command->websiteId,
            $identity->globalProductUuid,
            isset($command->payload['store_id']) ? (int)$command->payload['store_id'] : null,
            (string)($command->payload['locale'] ?? ''),
            (string)($command->payload['currency'] ?? 'CNY'),
        );
        return ProductAdminResult::ok([
            'diagnostics' => $this->publishDiagnostics($context),
        ]);
    }

    private function publish(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $product = $this->localProduct($command, $identity->globalProductUuid);
        $productId = (int)$product->getId();
        $context = $this->reader->validationContext(
            $command->websiteId,
            $identity->globalProductUuid,
            null,
            (string)($command->payload['locale'] ?? ''),
            (string)($command->payload['currency'] ?? 'CNY'),
        );
        $diagnostics = $this->publishDiagnostics($context);
        if (!($diagnostics['valid'] ?? false)) {
            return ProductAdminResult::fail(
                'product_publish_validation_failed',
                (string)__('发布校验未通过'),
                ['diagnostics' => $diagnostics],
            );
        }

        $result = $this->transactions->run(
            $this->connectionFactory,
            function () use ($command, $identity, $product, $productId): array {
                $publishedOffers = [];
                foreach ($this->offers->listByProductIds($command->websiteId, [$productId]) as $offer) {
                    $publishedOffers[] = $this->offers->publish(
                        $command->websiteId,
                        (int)($offer['offer_id'] ?? 0),
                        (int)($offer['publish_version'] ?? 0),
                    )->getData();
                }
                $published = $this->products->publish(
                    $command->websiteId,
                    $productId,
                    $this->localVersion($command),
                );
                $globalIdentity = $identity;
                if ($identity->ownerWebsiteId === $command->websiteId
                    && $identity->lifecycleStatus !== Product::STATUS_PUBLISHED
                ) {
                    $globalIdentity = $this->identities->transitionProduct(
                        $identity->globalProductUuid,
                        Product::STATUS_PUBLISHED,
                        $this->identityVersion($command),
                        $command->websiteId,
                        $command->requestHash,
                    );
                } elseif ($identity->ownerWebsiteId !== $command->websiteId
                    && $identity->lifecycleStatus !== Product::STATUS_PUBLISHED
                ) {
                    throw new ProductV2ConflictException(
                        'product_source_not_published',
                        (string)__('归属站商品尚未发布，复制站不能发布'),
                    );
                }
                return [
                    'identity' => $globalIdentity->toArray(),
                    'product' => $published->getData(),
                    'offers' => $publishedOffers,
                ];
            },
        );

        return ProductAdminResult::ok($result, (string)__('商品已发布'));
    }

    private function transition(ProductAdminCommand $command, string $targetStatus): ProductAdminResult
    {
        $identity = $this->identity($command);
        $product = $this->localProduct($command, $identity->globalProductUuid);
        $productId = (int)$product->getId();

        $result = $this->transactions->run(
            $this->connectionFactory,
            function () use ($command, $targetStatus, $identity, $productId): array {
                $localOffers = [];
                foreach ($this->offers->listByProductIds($command->websiteId, [$productId]) as $offer) {
                    $localOffers[] = $this->offers->transition(
                        $command->websiteId,
                        (int)($offer['offer_id'] ?? 0),
                        (int)($offer['publish_version'] ?? 0),
                        $targetStatus,
                    )->getData();
                }
                $local = $this->products->transition(
                    $command->websiteId,
                    $productId,
                    $this->localVersion($command),
                    $targetStatus,
                );
                $global = $identity;
                if ($identity->ownerWebsiteId === $command->websiteId) {
                    $global = $this->identities->transitionProduct(
                        $identity->globalProductUuid,
                        $targetStatus,
                        $this->identityVersion($command),
                        $command->websiteId,
                        $command->requestHash,
                    );
                }
                return [
                    'identity' => $global->toArray(),
                    'product' => $local->getData(),
                    'offers' => $localOffers,
                ];
            },
        );

        return ProductAdminResult::ok($result);
    }

    private function changeType(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $providerCode = strtolower(trim((string)($command->payload['provider_code'] ?? 'default')));
        $productType = strtolower(trim((string)($command->payload['product_type'] ?? '')));
        $this->providers->getByType($productType, true)
            ?? throw new \InvalidArgumentException('product_provider_unavailable');
        $updated = $this->identities->changeType(
            $identity->globalProductUuid,
            $providerCode,
            $productType,
            $this->identityVersion($command),
            $command->websiteId,
            $command->requestHash,
            (bool)($command->payload['has_copies'] ?? false),
            (bool)($command->payload['has_order_references'] ?? false),
        );
        $product = $this->localProduct($command, $identity->globalProductUuid);
        $local = $this->products->updateVersioned(
            $command->websiteId,
            (int)$product->getId(),
            $this->localVersion($command),
            [
                'provider_code' => $updated->providerCode,
                'product_type' => $updated->productType,
                'identity_version' => $updated->version,
            ],
        );
        return ProductAdminResult::ok([
            'identity' => $updated->toArray(),
            'product' => $local->getData(),
        ]);
    }

    private function share(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $updated = $this->governance->setShare(
            $identity->globalProductUuid,
            $command->websiteId,
            (int)($command->payload['target_website_id'] ?? -1),
            (bool)($command->payload['allowed'] ?? true),
            $this->identityVersion($command),
            $command->requestHash,
        );
        return ProductAdminResult::ok(['identity' => $updated->toArray()]);
    }

    private function initiateTransfer(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        $transferUuid = $this->governance->initiateTransfer(
            $identity->globalProductUuid,
            $command->websiteId,
            (int)($command->payload['target_website_id'] ?? -1),
            $this->identityVersion($command),
            $command->requestHash,
        );
        return ProductAdminResult::ok(['transfer_uuid' => $transferUuid]);
    }

    private function confirmTransfer(ProductAdminCommand $command): ProductAdminResult
    {
        $transferUuid = trim((string)($command->payload['transfer_uuid'] ?? ''));
        if ($transferUuid === '') {
            throw new \InvalidArgumentException('ownership_transfer_uuid_required');
        }
        $identity = $this->governance->confirmTransfer(
            $transferUuid,
            $command->websiteId,
            $command->requestHash,
        );
        return ProductAdminResult::ok(['identity' => $identity->toArray()]);
    }

    private function renameSku(ProductAdminCommand $command): ProductAdminResult
    {
        $identity = $this->identity($command);
        if ($identity->ownerWebsiteId !== $command->websiteId) {
            throw new ProductV2ConflictException(
                'product_structure_owner_required',
                (string)__('仅归属 Website 可修改 SKU'),
            );
        }
        $offerUuid = trim((string)($command->payload['global_offer_uuid'] ?? ''));
        $newSku = trim((string)($command->payload['sku'] ?? ''));
        $offerVersion = (int)($command->payload['offer_version'] ?? -1);
        if ($offerUuid === '' || $newSku === '' || $offerVersion < 0) {
            throw new \InvalidArgumentException('offer_rename_payload_invalid');
        }
        $renamed = $this->identities->renameSku(
            $offerUuid,
            $newSku,
            $offerVersion,
            $command->websiteId,
            $command->requestHash,
        );
        $localOffer = $this->offers->findByGlobalUuid($command->websiteId, $offerUuid)
            ?? throw new \InvalidArgumentException('offer_website_projection_not_found');
        $updatedOffer = $this->offers->updateVersioned(
            $command->websiteId,
            (int)$localOffer->getId(),
            (int)$localOffer->getData(Offer::schema_fields_PUBLISH_VERSION),
            ['sku' => $renamed->sku, 'identity_version' => $renamed->version],
        );
        $product = $this->localProduct($command, $identity->globalProductUuid);
        $productOffers = $this->offers->listByProductIds(
            $command->websiteId,
            [(int)$product->getId()],
        );
        $fields = ['identity_version' => $identity->version];
        if (($productOffers[0]['global_offer_uuid'] ?? '') === $offerUuid) {
            $fields[Product::schema_fields_SKU] = $renamed->sku;
        }
        $updatedProduct = $this->products->updateVersioned(
            $command->websiteId,
            (int)$product->getId(),
            $this->localVersion($command),
            $fields,
        );
        return ProductAdminResult::ok([
            'offer_identity' => $renamed->toArray(),
            'offer' => $updatedOffer->getData(),
            'product' => $updatedProduct->getData(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function offerSpecs(string $productType, array $payload): array
    {
        if ($productType === 'configurable') {
            $axes = $payload['axes'] ?? [];
            $overrides = $payload['sku_overrides'] ?? [];
            if (!is_array($axes) || !is_array($overrides)) {
                throw new \InvalidArgumentException('variant_axes_invalid');
            }
            $prefix = trim((string)($payload['sku_prefix'] ?? $payload['sku'] ?? ''));
            return array_map(
                static fn(array $row): array => [
                    'sku' => $row['sku'],
                    'combination_key' => $row['combination_key'],
                    'configuration' => ['combination' => $row['combination']],
                ],
                $this->variantMatrix->generate($axes, $prefix, $overrides),
            );
        }

        $raw = $payload['offers'] ?? null;
        if (is_array($raw) && $raw !== []) {
            $rows = [];
            $seen = [];
            foreach ($raw as $index => $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('product_offer_invalid');
                }
                $sku = trim((string)($item['sku'] ?? ''));
                if ($sku === '' || strlen($sku) > 128) {
                    throw new \InvalidArgumentException('product_offer_sku_invalid');
                }
                $identity = strtolower($sku);
                if (isset($seen[$identity])) {
                    throw new \InvalidArgumentException('product_offer_sku_duplicate');
                }
                $seen[$identity] = true;
                $configuration = $item['configuration'] ?? [];
                if (!is_array($configuration)) {
                    throw new \InvalidArgumentException('product_offer_configuration_invalid');
                }
                $combinationKey = trim((string)($item['combination_key'] ?? ''));
                if ($combinationKey === '' && count($raw) > 1) {
                    $combinationKey = 'offer=' . rawurlencode((string)($item['code'] ?? $index + 1));
                }
                $rows[] = [
                    'sku' => $sku,
                    'combination_key' => $combinationKey,
                    'configuration' => $configuration,
                ];
            }
            return $rows;
        }

        $sku = trim((string)($payload['sku'] ?? ''));
        if ($sku === '' || strlen($sku) > 128) {
            throw new \InvalidArgumentException('product_sku_required');
        }
        return [['sku' => $sku, 'combination_key' => '', 'configuration' => []]];
    }

    /** @param list<object> $localOffers */
    private function writeCreatePrices(int $websiteId, array $localOffers, array $payload): void
    {
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'CNY')));
        if (array_key_exists('price_minor', $payload)) {
            $amount = (int)$payload['price_minor'];
            foreach ($localOffers as $offer) {
                $this->prices->writeExplicit($websiteId, 0, (int)$offer->getId(), $currency, $amount);
            }
        }
        $priceRows = $payload['prices'] ?? [];
        if (!is_array($priceRows)) {
            throw new \InvalidArgumentException('product_prices_invalid');
        }
        $uuidToId = [];
        foreach ($localOffers as $offer) {
            $uuidToId[(string)$offer->getData(Offer::schema_fields_GLOBAL_OFFER_UUID)] = (int)$offer->getId();
            $uuidToId[(string)$offer->getData('sku')] = (int)$offer->getId();
        }
        foreach ($priceRows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_price_invalid');
            }
            $key = (string)($row['global_offer_uuid'] ?? $row['sku'] ?? '');
            $offerId = $uuidToId[$key] ?? null;
            if ($offerId === null) {
                throw new \InvalidArgumentException('product_price_offer_unknown');
            }
            $this->writePriceRow($websiteId, $offerId, $row);
        }
    }

    private function writeTaxonomyAndMedia(
        int $websiteId,
        int $productId,
        array $payload,
    ): void {
        if (array_key_exists('category_assignments', $payload)) {
            $rows = $this->categoryRows($websiteId, $payload['category_assignments'], false);
            $this->categoryLinks->syncProductScope($websiteId, $productId, 0, $rows);
        }

        $activeStoreIds = $this->activeStoreIds($websiteId);
        $activeStoreLookup = array_fill_keys($activeStoreIds, true);
        if (array_key_exists('store_category_overrides', $payload)) {
            $rows = $payload['store_category_overrides'];
            if (!is_array($rows)) {
                throw new \InvalidArgumentException('product_store_category_overrides_invalid');
            }
            $byStore = array_fill_keys($activeStoreIds, []);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('product_store_category_override_invalid');
                }
                $storeId = (int)($row['store_id'] ?? 0);
                if (!isset($activeStoreLookup[$storeId])) {
                    throw new \InvalidArgumentException('product_store_not_active');
                }
                $byStore[$storeId][] = $row;
            }
            foreach ($byStore as $storeId => $storeRows) {
                $this->categoryLinks->syncProductScope(
                    $websiteId,
                    $productId,
                    (int)$storeId,
                    $this->categoryRows($websiteId, $storeRows, true),
                );
            }
        }

        if (array_key_exists('category_assignments', $payload)
            || array_key_exists('store_category_overrides', $payload)
        ) {
            $this->catalogCache->notifyCatalogChanged($websiteId, 'product_category_assignments', [
                'product_id' => $productId,
            ]);
        }

        if (array_key_exists('media_assignments', $payload)) {
            $rows = $this->mediaRows($payload['media_assignments']);
            $this->media->syncProductScope($websiteId, $productId, 0, $rows);
        }
        if (array_key_exists('store_media_overrides', $payload)) {
            $rows = $payload['store_media_overrides'];
            if (!is_array($rows)) {
                throw new \InvalidArgumentException('product_store_media_overrides_invalid');
            }
            $baseAssetIds = [];
            foreach ($this->media->listByProductIds($websiteId, [$productId], [0]) as $row) {
                $assetId = strtolower(trim((string)($row['asset_id'] ?? '')));
                if ($assetId !== '') {
                    $baseAssetIds[$assetId] = true;
                }
            }
            $byStore = array_fill_keys($activeStoreIds, []);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('product_store_media_override_invalid');
                }
                $storeId = (int)($row['store_id'] ?? 0);
                $assetId = strtolower(trim((string)($row['asset_id'] ?? '')));
                if (!isset($activeStoreLookup[$storeId])) {
                    throw new \InvalidArgumentException('product_store_not_active');
                }
                if (!isset($baseAssetIds[$assetId])) {
                    throw new \InvalidArgumentException('product_store_media_base_asset_required');
                }
                $byStore[$storeId][] = $row;
            }
            foreach ($byStore as $storeId => $storeRows) {
                $this->media->syncProductScope(
                    $websiteId,
                    $productId,
                    (int)$storeId,
                    $this->mediaRows($storeRows),
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function categoryRows(int $websiteId, mixed $input, bool $allowExcluded): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('product_category_assignments_invalid');
        }
        $rows = [];
        foreach ($input as $index => $row) {
            $row = is_array($row) ? $row : ['category_id' => $row];
            $categoryId = (int)($row['category_id'] ?? 0);
            if ($categoryId < 1 || $this->categories->findById($websiteId, $categoryId) === null) {
                throw new \InvalidArgumentException('product_category_not_found');
            }
            $selected = filter_var(
                $row['selected'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            if ($selected === null || (!$allowExcluded && !$selected)) {
                throw new \InvalidArgumentException('product_category_assignment_invalid');
            }
            $rows[] = [
                'category_id' => $categoryId,
                'scope_state' => (string)($row['scope_state'] ?? 'explicit'),
                'selected' => $selected,
                'position' => (int)($row['position'] ?? $index),
            ];
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function mediaRows(mixed $input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException('product_media_assignments_invalid');
        }
        $rows = [];
        foreach ($input as $index => $row) {
            if (!is_array($row)
                || array_key_exists('path', $row)
                || array_key_exists('blob_key', $row)
            ) {
                throw new \InvalidArgumentException('product_media_legacy_input_forbidden');
            }
            $assetId = strtolower(trim((string)($row['asset_id'] ?? '')));
            try {
                $asset = $this->fileAssets->get($assetId);
            } catch (Throwable) {
                throw new \InvalidArgumentException('product_media_asset_not_found');
            }
            if (!$asset->isReady() || $asset->isDeleted()) {
                throw new \InvalidArgumentException('product_media_asset_not_ready');
            }
            $role = strtolower(trim((string)($row['role'] ?? 'gallery')));
            if (!in_array($role, ['main', 'gallery', 'file', 'download'], true)) {
                throw new \InvalidArgumentException('product_media_role_invalid');
            }
            $mimeType = strtolower(trim($asset->getMimeType()));
            $visibility = strtolower(trim($asset->getVisibility()));
            if (in_array($role, ['main', 'gallery'], true) && !str_starts_with($mimeType, 'image/')) {
                throw new \InvalidArgumentException('product_media_image_required');
            }
            if ($role === 'download' && $visibility !== 'private') {
                throw new \InvalidArgumentException('product_download_asset_must_be_private');
            }

            $policyInput = $row['access_policy'] ?? $row['access_policy_json'] ?? null;
            $policy = null;
            if ($policyInput !== null && $policyInput !== '') {
                if (is_string($policyInput)) {
                    try {
                        $policyInput = json_decode($policyInput, true, 64, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new \InvalidArgumentException('product_media_access_policy_invalid');
                    }
                }
                if (!is_array($policyInput)) {
                    throw new \InvalidArgumentException('product_media_access_policy_invalid');
                }
                $policy = $this->json($policyInput);
            } elseif ($role === 'download') {
                $policy = '{}';
            }
            if ($policy !== null && strlen($policy) > 65535) {
                throw new \InvalidArgumentException('product_media_access_policy_too_large');
            }
            $rows[] = [
                'asset_id' => $asset->getAssetId(),
                'asset_visibility' => $visibility,
                'mime_type' => $mimeType,
                'access_policy_json' => $policy,
                'scope_state' => 'explicit',
                'hidden' => !empty($row['hidden']),
                'role' => $role,
                'position' => max(0, (int)($row['position'] ?? $index)),
            ];
        }
        return $rows;
    }

    private function writeAttributes(int $websiteId, int $productId, array $payload): void
    {
        if (array_key_exists('name', $payload)) {
            $this->attributes->writeTyped(
                $websiteId,
                (int)($payload['store_id'] ?? 0),
                'product',
                $productId,
                'name',
                (string)($payload['locale'] ?? ''),
                'string',
                (string)$payload['name'],
                true,
            );
        }
        $rows = $payload['attributes'] ?? [];
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('product_attributes_invalid');
        }
        $rows = $this->attributeMetadata->normalizeRows($rows);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_attribute_invalid');
            }
            $scopeState = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
            $storeId = (int)($row['store_id'] ?? 0);
            $entityType = (string)($row['entity_type'] ?? 'product');
            $entityId = (int)($row['entity_id'] ?? $productId);
            $code = (string)($row['attribute_code'] ?? '');
            $locale = (string)($row['locale'] ?? '');
            if ($scopeState === 'inherit') {
                $this->attributes->deleteOverlay(
                    $websiteId,
                    $storeId,
                    $entityType,
                    $entityId,
                    $code,
                    $locale,
                );
            } elseif ($scopeState === 'cleared') {
                $this->attributes->writeCleared(
                    $websiteId,
                    $storeId,
                    $entityType,
                    $entityId,
                    $code,
                    $locale,
                    (bool)($row['is_required'] ?? false),
                );
            } elseif ($scopeState === 'explicit') {
                $this->attributes->writeTyped(
                    $websiteId,
                    $storeId,
                    $entityType,
                    $entityId,
                    $code,
                    $locale,
                    (string)($row['value_type'] ?? 'string'),
                    $row['value'] ?? null,
                    (bool)($row['is_required'] ?? false),
                );
            } else {
                throw new \InvalidArgumentException('product_attribute_scope_state_invalid');
            }
        }
        if (array_key_exists('type_configuration', $payload)) {
            if (!is_array($payload['type_configuration'])) {
                throw new \InvalidArgumentException('product_type_configuration_invalid');
            }
            $this->attributes->writeTyped(
                $websiteId,
                0,
                'product',
                $productId,
                'type_configuration',
                '',
                'json',
                $payload['type_configuration'],
            );
        }
    }

    private function writePrices(int $websiteId, int $productId, array $payload): void
    {
        $rows = $payload['prices'] ?? [];
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('product_prices_invalid');
        }
        $offers = $this->offers->listByProductIds($websiteId, [$productId]);
        $byUuid = [];
        foreach ($offers as $offer) {
            $byUuid[(string)($offer['global_offer_uuid'] ?? '')] = (int)($offer['offer_id'] ?? 0);
            $byUuid[(string)($offer['sku'] ?? '')] = (int)($offer['offer_id'] ?? 0);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_price_invalid');
            }
            $key = (string)($row['global_offer_uuid'] ?? $row['sku'] ?? '');
            $offerId = $byUuid[$key] ?? null;
            if ($offerId === null) {
                throw new \InvalidArgumentException('product_price_offer_unknown');
            }
            $this->writePriceRow($websiteId, $offerId, $row);
        }
    }

    private function writePriceRow(int $websiteId, int $offerId, array $row): void
    {
        $storeId = (int)($row['store_id'] ?? 0);
        $currency = (string)($row['currency'] ?? 'CNY');
        $scopeState = strtolower(trim((string)($row['scope_state'] ?? 'explicit')));
        if ($scopeState === 'inherit') {
            $this->prices->deleteOverlay($websiteId, $storeId, $offerId, $currency);
        } elseif ($scopeState === 'cleared') {
            $this->prices->writeCleared($websiteId, $storeId, $offerId, $currency);
        } elseif ($scopeState === 'explicit') {
            if (!array_key_exists('amount_minor', $row)) {
                throw new \InvalidArgumentException('product_price_amount_required');
            }
            $this->prices->writeExplicit(
                $websiteId,
                $storeId,
                $offerId,
                $currency,
                (int)$row['amount_minor'],
            );
        } else {
            throw new \InvalidArgumentException('product_price_scope_state_invalid');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{store_id:int,offer_id:int,on_hand_minor:int}>
     */
    private function inventoryRows(
        int $websiteId,
        string $productType,
        int $productId,
        array $payload,
    ): array {
        if (!array_key_exists('inventory', $payload)) {
            return [];
        }
        if (!is_array($payload['inventory'])) {
            throw new \InvalidArgumentException('product_inventory_rows_invalid');
        }
        if ($payload['inventory'] === []) {
            return [];
        }

        $provider = $this->providers->getByType($productType, false)
            ?? throw new \InvalidArgumentException('product_provider_unavailable');
        $providerV2 = $provider instanceof ProductProviderV2Interface
            ? $provider
            : new ProductProviderV1Adapter($provider);
        if (!$providerV2->getDefinition()->tracksInventory) {
            throw new \InvalidArgumentException('product_inventory_type_unsupported');
        }

        $selectedStoreIds = array_key_exists('store_ids', $payload)
            ? $this->selectedStoreIds($websiteId, $payload)
            : array_values(array_filter(
                $this->activeStoreIds($websiteId),
                fn(int $storeId): bool => $this->storeProducts->isSelected(
                    $websiteId,
                    $storeId,
                    $productId,
                ),
            ));
        $selectedStores = array_fill_keys($selectedStoreIds, true);
        $offersById = [];
        $offersByUuid = [];
        foreach ($this->offers->listByProductIds($websiteId, [$productId]) as $offer) {
            $offerId = (int)($offer['offer_id'] ?? 0);
            $offerUuid = strtolower(trim((string)($offer['global_offer_uuid'] ?? '')));
            if ($offerId <= 0
                || in_array((string)($offer['status'] ?? ''), ['disabled', 'archived'], true)
            ) {
                continue;
            }
            $offersById[$offerId] = $offerId;
            if ($offerUuid !== '') {
                $offersByUuid[$offerUuid] = $offerId;
            }
        }

        $rows = [];
        $seen = [];
        foreach ($payload['inventory'] as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('product_inventory_row_invalid');
            }
            foreach (['reserved_minor', 'available_minor', 'stock_version', 'sellable', 'strategy'] as $field) {
                if (array_key_exists($field, $row)) {
                    throw new \InvalidArgumentException('product_inventory_readonly_field');
                }
            }
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId <= 0 || !isset($selectedStores[$storeId])) {
                throw new \InvalidArgumentException('product_inventory_store_not_selected');
            }

            $offerId = (int)($row['offer_id'] ?? 0);
            $offerUuid = strtolower(trim((string)($row['global_offer_uuid'] ?? '')));
            $resolvedByUuid = $offerUuid === '' ? null : ($offersByUuid[$offerUuid] ?? null);
            if ($offerId <= 0) {
                $offerId = (int)($resolvedByUuid ?? 0);
            }
            if (!isset($offersById[$offerId])
                || ($resolvedByUuid !== null && $resolvedByUuid !== $offerId)
            ) {
                throw new \InvalidArgumentException('product_inventory_offer_unknown');
            }

            $rawOnHand = $row['on_hand_minor'] ?? null;
            if (is_int($rawOnHand)) {
                $onHandMinor = $rawOnHand;
            } elseif (is_string($rawOnHand) && preg_match('/^\d+$/', $rawOnHand) === 1) {
                $onHandMinor = (int)$rawOnHand;
            } else {
                throw new \InvalidArgumentException('product_inventory_on_hand_invalid');
            }
            if ($onHandMinor < 0) {
                throw new \InvalidArgumentException('product_inventory_on_hand_invalid');
            }

            $key = $storeId . ':' . $offerId;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('product_inventory_row_duplicate');
            }
            $seen[$key] = true;
            $rows[] = [
                'store_id' => $storeId,
                'offer_id' => $offerId,
                'on_hand_minor' => $onHandMinor,
            ];
        }
        usort(
            $rows,
            static fn(array $left, array $right): int => [
                $left['store_id'],
                $left['offer_id'],
            ] <=> [
                $right['store_id'],
                $right['offer_id'],
            ],
        );
        return $rows;
    }

    /**
     * @param list<array{store_id:int,offer_id:int,on_hand_minor:int}> $rows
     */
    private function writeInventoryRows(
        ProductAdminCommand $command,
        array $rows,
        InventoryCatalogCopyCapabilityInterface $inventory,
    ): void {
        foreach ($rows as $row) {
            $scope = 'inventory:' . $row['store_id'] . ':' . $row['offer_id'];
            $idempotencyKey = 'product-admin-' . substr(
                hash('sha256', $command->requestHash . ':' . $scope),
                0,
                64,
            );
            $requestHash = hash(
                'sha256',
                $command->requestHash . ':' . $scope . ':' . $row['on_hand_minor'],
            );
            try {
                $inventory->setOnHand(
                    $command->websiteId,
                    $row['store_id'],
                    $row['offer_id'],
                    $row['on_hand_minor'],
                    $idempotencyKey,
                    $requestHash,
                );
            } catch (Throwable $exception) {
                throw new CatalogConflictException(
                    'product_inventory_write_failed',
                    (string)__('库存保存失败，商品修改已回滚'),
                    [
                        'store_id' => $row['store_id'],
                        'offer_id' => $row['offer_id'],
                    ],
                    $exception,
                );
            }
        }
    }

    private function inventory(): ?InventoryCatalogCopyCapabilityInterface
    {
        if ($this->inventoryResolved) {
            return $this->resolvedInventory;
        }
        $this->inventoryResolved = true;
        if (!class_exists(InventoryCatalogCopyCapability::class)) {
            return null;
        }
        try {
            $resolved = ObjectManager::getInstance(InventoryCatalogCopyCapability::class);
            $this->resolvedInventory = $resolved instanceof InventoryCatalogCopyCapabilityInterface
                ? $resolved
                : null;
        } catch (Throwable) {
            $this->resolvedInventory = null;
        }
        return $this->resolvedInventory;
    }

    /** @return list<int> */
    private function selectedStoreIds(int $websiteId, array $payload): array
    {
        $active = $this->activeStoreIds($websiteId);
        if (!array_key_exists('store_ids', $payload)) {
            return $active;
        }
        if (!is_array($payload['store_ids'])) {
            throw new \InvalidArgumentException('product_store_ids_invalid');
        }
        $requested = array_values(array_unique(array_map('intval', $payload['store_ids'])));
        $allowed = array_fill_keys($active, true);
        foreach ($requested as $storeId) {
            if (!isset($allowed[$storeId])) {
                throw new \InvalidArgumentException('product_store_not_active');
            }
        }
        sort($requested, SORT_NUMERIC);
        return $requested;
    }

    /** @return list<int> */
    private function activeStoreIds(int $websiteId): array
    {
        $rows = [];
        foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
            if ($store->websiteId === $websiteId
                && $store->enabled
                && $store->lifecycleStatus === 'active'
                && $store->tombstonedAt === null
            ) {
                $rows[] = $store->id;
            }
        }
        sort($rows, SORT_NUMERIC);
        return array_values(array_unique($rows));
    }

    /** @return array<string, mixed> */
    private function publishDiagnostics(ProductValidationContext $context): array
    {
        $validation = $this->publishValidator->validate($context);
        if ($context->storeIds === []) {
            $validation = $validation->merge(new ProductValidationResult(
                errors: [$this->storeSelectionDiagnostic()],
            ));
        }
        return $validation->toArray($context);
    }

    /** @return array{code:string,message:string,path:string} */
    private function storeSelectionDiagnostic(): array
    {
        return [
            'code' => 'product_publish_store_required',
            'message' => (string)__('至少选择一个活动 Store 才能发布'),
            'path' => 'stores',
        ];
    }

    private function identity(ProductAdminCommand $command): \Weline\Product\Api\Data\ProductIdentityV2
    {
        $uuid = $command->globalProductUuid
            ?? throw new \InvalidArgumentException('product_admin_product_uuid_required');
        return $this->identities->resolveProductByUuid($uuid)
            ?? throw new \InvalidArgumentException('product_v2_identity_not_found');
    }

    private function localProduct(ProductAdminCommand $command, string $uuid): Product
    {
        return $this->products->findByGlobalUuid($command->websiteId, $uuid)
            ?? throw new \InvalidArgumentException('product_website_projection_not_found');
    }

    private function identityVersion(ProductAdminCommand $command): int
    {
        if ($command->expectedVersion === null) {
            throw new \InvalidArgumentException('product_admin_expected_version_required');
        }
        return $command->expectedVersion;
    }

    private function localVersion(ProductAdminCommand $command): int
    {
        $version = $command->payload['local_version'] ?? null;
        if ($version === null || (int)$version < 0) {
            throw new \InvalidArgumentException('product_admin_local_version_required');
        }
        return (int)$version;
    }

    private function subRequestHash(string $requestHash, string $scope): string
    {
        return hash('sha256', $requestHash . ':' . $scope);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
