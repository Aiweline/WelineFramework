<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Theme\Model\ThemeVirtualLayout;

/**
 * Bust storefront/product detail caches when a layout schedule starts or ends.
 */
final class ProductLayoutCacheBustService
{
    /**
     * @return array{reason:string,target_type:string,target_id:int,product_ids:list<int>,steps:array<string,bool>,failures:array<string,string>}
     */
    public function bustForScheduleTarget(
        string $targetType,
        int $targetId,
        int $websiteId = 0,
        string $reason = 'theme_layout_schedule_boundary',
        string $boundary = 'start',
        int $scheduleId = 0,
    ): array {
        $targetType = strtolower(trim($targetType));
        $targetId = max(0, $targetId);
        $productIds = $this->resolveProductIds($targetType, $targetId, $websiteId);
        $result = [
            'reason' => $reason,
            'boundary' => $boundary,
            'schedule_id' => max(0, $scheduleId),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'website_id' => max(0, $websiteId),
            'product_ids' => $productIds,
            'product_count' => count($productIds),
            'steps' => [],
            'failures' => [],
        ];

        $this->runStep($result, 'theme_runtime', static function (): void {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)
                ->clearNonGlobalCaches(null, 'theme_layout_schedule_boundary');
        });

        $this->runStep($result, 'storefront_theme_generation', static function (): void {
            $namespacePath = ObjectManager::getInstance(NamespacePath::class);
            ObjectManager::getInstance(NamespaceGenerationInterface::class)->bump(
                $namespacePath->global('storefront', ['theme']),
            );
        });

        $this->runStep($result, 'product_layout_fingerprint', function () use ($productIds, $scheduleId, $boundary): void {
            $pool = ObjectManager::getInstance(CacheManager::class)->pool('product');
            foreach ($productIds as $productId) {
                $pool->delete($this->fingerprintKey($productId));
                $pool->set($this->appliedScheduleKey($productId), [
                    'schedule_id' => $scheduleId,
                    'boundary' => $boundary,
                    'at' => time(),
                ], 86400);
            }
        });

        $this->runStep($result, 'fpc_process_cache', static function (): void {
            if (class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        });

        return $result;
    }

    /**
     * Detect resolve-time schedule membership change and bust if needed.
     *
     * @return array<string,mixed>|null
     */
    public function bustIfScheduleMembershipChanged(int $productId, int $scheduleId): ?array
    {
        $productId = max(0, $productId);
        if ($productId <= 0) {
            return null;
        }
        $pool = ObjectManager::getInstance(CacheManager::class)->pool('product');
        $prev = $pool->get($this->appliedScheduleKey($productId));
        $prevId = is_array($prev) ? (int)($prev['schedule_id'] ?? 0) : (int)$prev;
        if ($prevId === $scheduleId) {
            return null;
        }

        return $this->bustForScheduleTarget(
            ThemeVirtualLayout::TARGET_PRODUCT,
            $productId,
            0,
            'theme_layout_schedule_membership_change',
            $scheduleId > 0 ? 'start' : 'end',
            $scheduleId,
        );
    }

    /**
     * @return list<int>
     */
    private function resolveProductIds(string $targetType, int $targetId, int $websiteId): array
    {
        if ($targetType === ThemeVirtualLayout::TARGET_PRODUCT && $targetId > 0) {
            return [$targetId];
        }
        if ($targetType === ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT && $targetId > 0) {
            return $this->productIdsForCategory($websiteId, $targetId);
        }

        return [];
    }

    /**
     * @return list<int>
     */
    private function productIdsForCategory(int $websiteId, int $categoryId): array
    {
        if (!class_exists(\Weline\Product\Repository\CategoryLinkRepository::class)) {
            return [];
        }
        try {
            /** @var \Weline\Product\Repository\CategoryLinkRepository $repo */
            $repo = ObjectManager::getInstance(\Weline\Product\Repository\CategoryLinkRepository::class);
            $websiteId = max(0, $websiteId);
            $rows = $repo->listByCategoryIds($websiteId, [$categoryId], [0]);
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $productId = (int)($row['product_id'] ?? $row['PRODUCT_ID'] ?? 0);
                $selected = $row['selected'] ?? $row['SELECTED'] ?? 1;
                if ($productId > 0 && (int)$selected === 1) {
                    $ids[$productId] = $productId;
                }
            }

            return array_values($ids);
        } catch (\Throwable) {
            return [];
        }
    }

    private function fingerprintKey(int $productId): string
    {
        return 'product_layout_fp_' . $productId;
    }

    private function appliedScheduleKey(int $productId): string
    {
        return 'product_layout_schedule_applied_' . $productId;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function runStep(array &$result, string $step, callable $fn): void
    {
        try {
            $fn();
            $result['steps'][$step] = true;
        } catch (\Throwable $e) {
            $result['steps'][$step] = false;
            $result['failures'][$step] = $e->getMessage();
        }
    }
}
