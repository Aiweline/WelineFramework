<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\OfferIdentityRegistry;
use Weline\Product\Model\ProductIdentityRegistry;
use Weline\Product\Model\ProductMigrationConflict;
use Weline\Product\Model\SkuRegistry;

/**
 * Idempotent legacy 1:1 -> Product 1:N Offer migration and cutover verifier.
 */
final readonly class ProductV2MigrationService
{
    public const PHASE = 'MIG-PRODUCT-V2';

    /** @var (\Closure(): SkuRegistry)|null */
    private mixed $legacyFactory;
    /** @var (\Closure(): ProductIdentityRegistry)|null */
    private mixed $productFactory;
    /** @var (\Closure(): OfferIdentityRegistry)|null */
    private mixed $offerFactory;
    /** @var (\Closure(): ProductMigrationConflict)|null */
    private mixed $conflictFactory;

    /**
     * @param (\Closure(): SkuRegistry)|null $legacyFactory
     * @param (\Closure(): ProductIdentityRegistry)|null $productFactory
     * @param (\Closure(): OfferIdentityRegistry)|null $offerFactory
     * @param (\Closure(): ProductMigrationConflict)|null $conflictFactory
     */
    public function __construct(
        private ProductIdentityV2Service $identities,
        private ProductIdentityCutoverService $cutover,
        ?callable $legacyFactory = null,
        ?callable $productFactory = null,
        ?callable $offerFactory = null,
        ?callable $conflictFactory = null,
    ) {
        $this->legacyFactory = $legacyFactory;
        $this->productFactory = $productFactory;
        $this->offerFactory = $offerFactory;
        $this->conflictFactory = $conflictFactory;
    }

    /** @return array<string, mixed> */
    public function inventory(): array
    {
        $legacy = $this->legacyRows();
        $products = $this->newProducts()->clear()->select()->fetchArray();
        $offers = $this->newOffers()->clear()->select()->fetchArray();
        $conflicts = $this->openConflicts();

        return [
            'ok' => true,
            'phase' => self::PHASE,
            'legacy_identity_count' => count($legacy),
            'v2_product_count' => count($products),
            'v2_offer_count' => count($offers),
            'missing_offer_count' => max(0, count($products) - count($offers)),
            'open_conflict_count' => count($conflicts),
            'source_digest' => $this->sourceDigest($legacy),
            'cutover_state' => $this->cutover->current(),
        ];
    }

    /**
     * @param array<string, list<array{website_id:int,copy_source?:bool,created_at?:string}>> $websiteFacts
     * @return array<string, mixed>
     */
    public function migrate(bool $dryRun = true, array $websiteFacts = []): array
    {
        $rows = $this->legacyRows();
        $sourceDigest = $this->sourceDigest($rows);
        $result = [
            'ok' => true,
            'phase' => self::PHASE,
            'dry_run' => $dryRun,
            'scanned' => count($rows),
            'created_products' => 0,
            'created_offers' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'owner_decisions' => [],
            'source_digest' => $sourceDigest,
        ];

        foreach ($rows as $row) {
            $productUuid = (string)($row[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? '');
            $offerUuid = (string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? '');
            $sku = (string)($row[SkuRegistry::schema_fields_SKU] ?? '');
            $requestHash = (string)($row[SkuRegistry::schema_fields_REQUEST_HASH] ?? '');
            $ownerWebsiteId = self::selectOwnerWebsiteId($websiteFacts[$productUuid] ?? []);
            $result['owner_decisions'][$productUuid] = $ownerWebsiteId;

            if ($dryRun) {
                $productExists = $this->identities->resolveProductByUuid($productUuid) !== null;
                $offerExists = $this->identities->resolveOfferByUuid($offerUuid) !== null;
                $result['created_products'] += $productExists ? 0 : 1;
                $result['created_offers'] += $offerExists ? 0 : 1;
                $result['skipped'] += $productExists && $offerExists ? 1 : 0;
                continue;
            }

            try {
                $beforeProduct = $this->identities->resolveProductByUuid($productUuid);
                $beforeOffer = $this->identities->resolveOfferByUuid($offerUuid);
                $this->identities->createProductWithUuid(
                    $productUuid,
                    $ownerWebsiteId,
                    'default',
                    'simple',
                    $requestHash,
                );
                $this->identities->createOfferWithUuid(
                    $productUuid,
                    $offerUuid,
                    $sku,
                    $requestHash,
                );
                $result['created_products'] += $beforeProduct === null ? 1 : 0;
                $result['created_offers'] += $beforeOffer === null ? 1 : 0;
                $result['skipped'] += $beforeProduct !== null && $beforeOffer !== null ? 1 : 0;
            } catch (\Throwable $exception) {
                $result['ok'] = false;
                $result['conflicts']++;
                $this->recordConflict(
                    'legacy_sku_registry',
                    $sku,
                    $exception instanceof ProductV2ConflictException
                        ? $exception->errorCode
                        : 'migration_unclassified',
                    [
                        'global_product_uuid' => $productUuid,
                        'global_offer_uuid' => $offerUuid,
                        'message' => $exception->getMessage(),
                    ],
                );
            }
        }

        if ($dryRun) {
            $result['compatibility_mode'] = $this->cutover->mode();
            $result['cutover_state'] = $this->cutover->current();
            return $result;
        }

        $result['cutover_state'] = $this->cutover->markMigrated($sourceDigest);
        $result['compatibility_mode'] = $result['cutover_state']['mode'];
        return $result;
    }

    /** @return array<string, mixed> */
    public function verify(): array
    {
        $rows = $this->legacyRows();
        $sourceDigest = $this->sourceDigest($rows);
        $mismatches = [];
        $mismatchCount = 0;

        foreach ($rows as $row) {
            $productUuid = (string)($row[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? '');
            $offerUuid = (string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? '');
            $sku = (string)($row[SkuRegistry::schema_fields_SKU] ?? '');
            $codes = [];
            try {
                $product = $this->identities->resolveProductByUuid($productUuid);
                $offer = $this->identities->resolveOfferByUuid($offerUuid);
                if ($product === null) {
                    $codes[] = 'v2_product_missing';
                }
                if ($offer === null) {
                    $codes[] = 'v2_offer_missing';
                } elseif ($offer->globalProductUuid !== $productUuid) {
                    $codes[] = 'v2_offer_product_mismatch';
                } elseif ($offer->sku !== $sku) {
                    $codes[] = 'v2_offer_sku_mismatch';
                } elseif ($offer->status !== OfferIdentityRegistry::STATUS_ACTIVE) {
                    $codes[] = 'v2_offer_not_active';
                }
            } catch (\Throwable $exception) {
                $codes[] = $exception instanceof ProductV2ConflictException
                    ? $exception->errorCode
                    : 'v2_identity_read_failed';
            }
            if ($codes === []) {
                continue;
            }
            $mismatchCount++;
            if (count($mismatches) < 100) {
                $mismatches[] = [
                    'sku' => $sku,
                    'global_product_uuid' => $productUuid,
                    'global_offer_uuid' => $offerUuid,
                    'codes' => $codes,
                ];
            }
        }

        $openConflicts = $this->openConflicts();
        $errorCount = $mismatchCount + count($openConflicts);
        $ok = $errorCount === 0;
        $state = $this->cutover->recordVerification(
            $sourceDigest,
            $ok,
            count($rows),
            $errorCount,
        );

        return [
            'ok' => $ok,
            'phase' => self::PHASE,
            'action' => 'verify',
            'scanned' => count($rows),
            'source_digest' => $sourceDigest,
            'mismatch_count' => $mismatchCount,
            'mismatches' => $mismatches,
            'open_conflict_count' => count($openConflicts),
            'cutover_state' => $state,
        ];
    }

    /** @return array<string, mixed> */
    public function cutover(int $expectedVersion): array
    {
        $rows = $this->legacyRows();
        $state = $this->cutover->cutover(
            $this->sourceDigest($rows),
            $expectedVersion,
        );
        return [
            'ok' => true,
            'phase' => self::PHASE,
            'action' => 'cutover',
            'cutover_state' => $state,
        ];
    }

    /** @return array<string, mixed> */
    public function rollback(
        int $expectedVersion,
        string $targetMode = ProductIdentityCutoverService::MODE_DUAL_READ,
    ): array {
        return [
            'ok' => true,
            'phase' => self::PHASE,
            'action' => 'rollback',
            'cutover_state' => $this->cutover->rollback($expectedVersion, $targetMode),
        ];
    }

    /**
     * Ownership precedence: copy audit source, earliest created projection, default site, smallest ID.
     *
     * @param list<array{website_id:int,copy_source?:bool,created_at?:string}> $facts
     */
    public static function selectOwnerWebsiteId(array $facts): int
    {
        if ($facts === []) {
            return 0;
        }
        usort($facts, static function (array $left, array $right): int {
            $leftCopy = ($left['copy_source'] ?? false) ? 0 : 1;
            $rightCopy = ($right['copy_source'] ?? false) ? 0 : 1;
            $leftTime = trim((string)($left['created_at'] ?? '')) ?: '9999-12-31 23:59:59';
            $rightTime = trim((string)($right['created_at'] ?? '')) ?: '9999-12-31 23:59:59';
            $leftWebsite = (int)($left['website_id'] ?? PHP_INT_MAX);
            $rightWebsite = (int)($right['website_id'] ?? PHP_INT_MAX);
            return [$leftCopy, $leftTime, $leftWebsite === 0 ? 0 : 1, $leftWebsite]
                <=> [$rightCopy, $rightTime, $rightWebsite === 0 ? 0 : 1, $rightWebsite];
        });
        return max(0, (int)($facts[0]['website_id'] ?? 0));
    }

    /** @return list<array<string,mixed>> */
    private function legacyRows(): array
    {
        $rows = $this->newLegacy()->clear()
            ->where(SkuRegistry::schema_fields_STATUS, SkuRegistry::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        usort(
            $rows,
            static fn (array $left, array $right): int => [
                (string)($left[SkuRegistry::schema_fields_SKU] ?? ''),
                (string)($left[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''),
                (string)($left[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''),
            ] <=> [
                (string)($right[SkuRegistry::schema_fields_SKU] ?? ''),
                (string)($right[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''),
                (string)($right[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''),
            ],
        );
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private function sourceDigest(array $rows): string
    {
        $facts = array_map(
            static fn (array $row): array => [
                'sku' => (string)($row[SkuRegistry::schema_fields_SKU] ?? ''),
                'global_product_uuid'
                    => (string)($row[SkuRegistry::schema_fields_GLOBAL_PRODUCT_UUID] ?? ''),
                'global_offer_uuid'
                    => (string)($row[SkuRegistry::schema_fields_GLOBAL_OFFER_UUID] ?? ''),
                'request_hash'
                    => (string)($row[SkuRegistry::schema_fields_REQUEST_HASH] ?? ''),
            ],
            $rows,
        );
        return hash(
            'sha256',
            json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<array<string,mixed>> */
    private function openConflicts(): array
    {
        return $this->newConflict()->clear()
            ->where(ProductMigrationConflict::schema_fields_RESOLUTION_STATUS, 'open')
            ->select()
            ->fetchArray();
    }

    /** @param array<string,mixed> $details */
    private function recordConflict(
        string $sourceKind,
        string $sourceKey,
        string $code,
        array $details,
    ): void {
        $existing = $this->newConflict()->clear()
            ->where(ProductMigrationConflict::schema_fields_SOURCE_KIND, $sourceKind)
            ->where(ProductMigrationConflict::schema_fields_SOURCE_KEY, $sourceKey)
            ->where(ProductMigrationConflict::schema_fields_CONFLICT_CODE, $code)
            ->find()->fetch();
        if ($existing->getId()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $this->newConflict()->clear()->setData([
            ProductMigrationConflict::schema_fields_SOURCE_KIND => $sourceKind,
            ProductMigrationConflict::schema_fields_SOURCE_KEY => $sourceKey,
            ProductMigrationConflict::schema_fields_CONFLICT_CODE => $code,
            ProductMigrationConflict::schema_fields_DETAILS_JSON => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            ProductMigrationConflict::schema_fields_RESOLUTION_STATUS => 'open',
            ProductMigrationConflict::schema_fields_CREATED_AT => $now,
            ProductMigrationConflict::schema_fields_UPDATED_AT => $now,
        ])->save();
    }

    private function newLegacy(): SkuRegistry
    {
        return $this->legacyFactory !== null
            ? ($this->legacyFactory)()
            : ObjectManager::make(SkuRegistry::class);
    }

    private function newProducts(): ProductIdentityRegistry
    {
        return $this->productFactory !== null
            ? ($this->productFactory)()
            : ObjectManager::make(ProductIdentityRegistry::class);
    }

    private function newOffers(): OfferIdentityRegistry
    {
        return $this->offerFactory !== null
            ? ($this->offerFactory)()
            : ObjectManager::make(OfferIdentityRegistry::class);
    }

    private function newConflict(): ProductMigrationConflict
    {
        return $this->conflictFactory !== null
            ? ($this->conflictFactory)()
            : ObjectManager::make(ProductMigrationConflict::class);
    }
}
