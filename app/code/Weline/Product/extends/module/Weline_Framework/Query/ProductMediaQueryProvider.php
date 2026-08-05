<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\CatalogConflictException;
use Weline\Product\Service\ProductMediaQueryHarnessCatalog;

/**
 * 前台 Product Media shareCopy/COW Facade（TEST-P2A-07）。
 *
 * 走真实 website shard DB（MediaRepository），不伪造 Browser 表象。
 */
class ProductMediaQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'product_media';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'prepareHarness' => $this->prepareHarness($params),
            'runShareCow' => $this->runShareCow($params),
            'clearHarness' => $this->clearHarness(),
            default => throw new \InvalidArgumentException((string)__('商品媒体接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function prepareHarness(array $params): array
    {
        $runId = trim((string)($params['run_id'] ?? ''));
        if ($runId === '') {
            $runId = 'p2a07-' . bin2hex(random_bytes(6));
        }
        $websiteId = (int)($params['website_id'] ?? 0);
        ProductMediaQueryHarnessCatalog::put([
            'run_id' => $runId,
            'website_id' => $websiteId,
            'product_ids' => [],
            'media_ids' => [],
        ]);

        return [
            'success' => true,
            'harness_active' => ProductMediaQueryHarnessCatalog::isActive(),
            'run_id' => $runId,
            'website_id' => $websiteId,
        ];
    }

    /**
     * Product A 建 owner → shareCopy 到 Product B → cowEdit 副本。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function runShareCow(array $params): array
    {
        if (!ProductMediaQueryHarnessCatalog::isActive()) {
            return [
                'success' => false,
                'error_code' => 'media_harness_inactive',
                'message' => (string)__('请先调用 prepareHarness'),
            ];
        }

        $state = ProductMediaQueryHarnessCatalog::load() ?? [
            'run_id' => 'p2a07',
            'website_id' => 0,
            'product_ids' => [],
            'media_ids' => [],
        ];
        $websiteId = array_key_exists('website_id', $params)
            ? (int)$params['website_id']
            : (int)$state['website_id'];
        $suffix = trim((string)($params['suffix'] ?? ''));
        if ($suffix === '') {
            $suffix = (string)time();
        }
        $runId = (string)$state['run_id'];

        try {
            /** @var ProductRepository $products */
            $products = ObjectManager::getInstance(ProductRepository::class);
            /** @var MediaRepository $media */
            $media = ObjectManager::getInstance(MediaRepository::class);

            $productA = $products->create($websiteId, [
                Product::schema_fields_SKU => 'p2a07-a-' . $suffix,
                Product::schema_fields_GLOBAL_PRODUCT_UUID => $this->uuidFromSeed($runId . '-a-' . $suffix),
            ]);
            $productB = $products->create($websiteId, [
                Product::schema_fields_SKU => 'p2a07-b-' . $suffix,
                Product::schema_fields_GLOBAL_PRODUCT_UUID => $this->uuidFromSeed($runId . '-b-' . $suffix),
            ]);

            $ownerBlob = 'blob-p2a07-' . $suffix;
            $owner = $media->create($websiteId, [
                Media::schema_fields_PRODUCT_ID => (int)$productA->getId(),
                Media::schema_fields_PATH => '/media/p2a07-owner-' . $suffix . '.jpg',
                Media::schema_fields_BLOB_KEY => $ownerBlob,
                Media::schema_fields_POSITION => 1,
            ]);
            $copy = $media->shareCopy(
                $websiteId,
                (int)$owner->getId(),
                (int)$productB->getId(),
                2,
            );
            $ownerAfterShare = $media->findById($websiteId, (int)$owner->getId());
            $forkBlob = 'blob-p2a07-fork-' . $suffix;
            $fork = $media->cowEdit(
                $websiteId,
                (int)$copy->getId(),
                '/media/p2a07-fork-' . $suffix . '.jpg',
                $forkBlob,
            );
            $ownerAfterCow = $media->findById($websiteId, (int)$owner->getId());
            $copyAfterCow = $media->findById($websiteId, (int)$copy->getId());

            $productIds = [
                (int)$productA->getId(),
                (int)$productB->getId(),
            ];
            $mediaIds = [
                (int)$owner->getId(),
                (int)$copy->getId(),
            ];
            ProductMediaQueryHarnessCatalog::put([
                'run_id' => $runId,
                'website_id' => $websiteId,
                'product_ids' => $productIds,
                'media_ids' => $mediaIds,
            ]);

            return [
                'success' => true,
                'website_id' => $websiteId,
                'run_id' => $runId,
                'product_a_id' => (int)$productA->getId(),
                'product_b_id' => (int)$productB->getId(),
                'owner' => $this->mediaSnapshot($ownerAfterShare),
                'copy_after_share' => [
                    'media_id' => (int)$copy->getId(),
                    'product_id' => (int)$copy->getData(Media::schema_fields_PRODUCT_ID),
                    'blob_key' => (string)$copy->getData(Media::schema_fields_BLOB_KEY),
                    'ref_count' => (int)$copy->getData(Media::schema_fields_REF_COUNT),
                    'cow_source_media_id' => $copy->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID),
                ],
                'fork' => [
                    'cow' => (bool)($fork['cow'] ?? false),
                    'previous_blob_key' => (string)($fork['previous_blob_key'] ?? ''),
                    'media' => $this->mediaSnapshot($fork['media'] ?? $copyAfterCow),
                ],
                'owner_after_cow' => $this->mediaSnapshot($ownerAfterCow),
                'copy_after_cow' => $this->mediaSnapshot($copyAfterCow),
            ];
        } catch (CatalogConflictException $e) {
            return [
                'success' => false,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_code' => 'media_share_cow_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clearHarness(): array
    {
        ProductMediaQueryHarnessCatalog::clear();

        return ['success' => true, 'cleaned' => true];
    }

    /**
     * @param Media|null $row
     * @return array<string, mixed>|null
     */
    private function mediaSnapshot(?Media $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'media_id' => (int)$row->getId(),
            'product_id' => (int)$row->getData(Media::schema_fields_PRODUCT_ID),
            'path' => (string)$row->getData(Media::schema_fields_PATH),
            'blob_key' => (string)$row->getData(Media::schema_fields_BLOB_KEY),
            'ref_count' => (int)$row->getData(Media::schema_fields_REF_COUNT),
            'cow_source_media_id' => $row->getData(Media::schema_fields_COW_SOURCE_MEDIA_ID),
            'position' => (int)$row->getData(Media::schema_fields_POSITION),
        ];
    }

    private function uuidFromSeed(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            '4' . substr($hex, 13, 3),
            '8' . substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Product',
            'summary' => 'Product Media shareCopy/COW E2E harness (live shard DB)',
            'operations' => [
                [
                    'name' => 'prepareHarness',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'run_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Activate Product Media E2E harness marker',
                ],
                [
                    'name' => 'runShareCow',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'suffix' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create A→B shareCopy then COW edit on live MediaRepository',
                ],
                [
                    'name' => 'clearHarness',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Clear Product Media harness marker',
                ],
            ],
        ];
    }
}
