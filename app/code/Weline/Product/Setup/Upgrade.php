<?php

declare(strict_types=1);

namespace Weline\Product\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Product\Model\Category\LocalDescription;
use Weline\Product\Model\Product\LocalDescription as ProductLocalDescription;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Model\ProductCatalogAttributeEntity;
use Weline\Product\Service\ProductCategoryEavBootstrap;
use Weline\Product\Service\ProductCatalogEavBootstrap;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->upgrade($setup, $context);
    }

    public function upgrade(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);

        /** @var ProductCatalogAttributeEntity $productEntity */
        $productEntity = ObjectManager::getInstance(ProductCatalogAttributeEntity::class);
        $modelSetup->putModel($productEntity);
        $productEntity->upgrade($modelSetup, $context);

        /** @var CategoryAttributeEntity $categoryEntity */
        $categoryEntity = ObjectManager::getInstance(CategoryAttributeEntity::class);
        $modelSetup->putModel($categoryEntity);
        $categoryEntity->upgrade($modelSetup, $context);

        ObjectManager::getInstance(ProductCategoryEavBootstrap::class)->ensureCategorySchema();

        /** @var LocalDescription $categoryLocal */
        $categoryLocal = ObjectManager::getInstance(LocalDescription::class);
        $modelSetup->putModel($categoryLocal);
        $categoryLocal->upgrade($modelSetup, $context);

        /** @var ProductLocalDescription $productLocal */
        $productLocal = ObjectManager::getInstance(ProductLocalDescription::class);
        $modelSetup->putModel($productLocal);
        $productLocal->upgrade($modelSetup, $context);

        $catalogBootstrap = ObjectManager::getInstance(ProductCatalogEavBootstrap::class);
        $catalog = $catalogBootstrap->ensureStorefrontSchema();
        $catalogBootstrap->ensureProductFreeSet((int)($catalog['entity_id'] ?? 0));
        $catalogBootstrap->detachDedicatedIdentityAttributesFromSets((int)($catalog['entity_id'] ?? 0));

        $this->purgeOrphanBestSellersHeroLayoutAdditions();
    }

    /**
     * REQ-PRODUCT-0051 follow-up: drop pure layout additions of best-sellers-hero that lack
     * template_ref. The layout slot now owns the default via exclusive inline <w:widget>;
     * leftover seed rows from 1.0.277–1.0.280 stack a second preview beside the template shell.
     */
    private function purgeOrphanBestSellersHeroLayoutAdditions(): void
    {
        if (!class_exists(\Weline\Theme\Model\ThemeLayout::class)) {
            return;
        }

        try {
            /** @var \Weline\Theme\Model\ThemeLayout $layout */
            $layout = ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class);
            $rows = $layout->clear()
                ->where(\Weline\Theme\Model\ThemeLayout::schema_fields_PAGE_TYPE, 'best_sellers')
                ->where(\Weline\Theme\Model\ThemeLayout::schema_fields_SLOT_ID, 'best-sellers-hero')
                ->where(\Weline\Theme\Model\ThemeLayout::schema_fields_WIDGET_CODE, 'best-sellers-hero')
                ->select()
                ->fetch()
                ->getItems();
            foreach ($rows as $row) {
                if (!\is_object($row) || !\method_exists($row, 'getData')) {
                    continue;
                }
                $configRaw = $row->getData(\Weline\Theme\Model\ThemeLayout::schema_fields_CONFIG);
                $config = [];
                if (\is_array($configRaw)) {
                    $config = $configRaw;
                } elseif (\is_string($configRaw) && $configRaw !== '') {
                    $decoded = json_decode($configRaw, true);
                    $config = \is_array($decoded) ? $decoded : [];
                }
                $templateRef = trim((string)($config['template_ref'] ?? ''));
                if ($templateRef !== '') {
                    // CoW override of the inline shell — keep.
                    continue;
                }
                if (\method_exists($row, 'delete')) {
                    $row->delete();
                }
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                w_log_warning('product_best_sellers_hero_orphan_purge_failed: ' . $e->getMessage());
            }
        }
    }
}
