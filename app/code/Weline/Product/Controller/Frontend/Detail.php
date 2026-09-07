<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Service\ProductLayoutCacheBustService;
use Weline\Theme\Service\ProductLayoutResolveService;

final class Detail extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontVariantSelectionService $variantSelection,
        private readonly StorefrontEavLabelResolver $variantLabels,
        private readonly EventsManager $events,
    ) {
    }

    public function index(): string
    {
        $slug = strtolower(trim((string)$this->request->getParam('slug', '')));
        $productId = (int)$this->request->getParam('id', 0);
        $requestedOfferUuid = trim((string)$this->request->getParam('offer', ''));

        $offers = [];
        if ($slug !== '') {
            $offers = $this->catalog->publishedOffersBySlug($slug);
        } elseif ($productId > 0) {
            $offers = $this->catalog->publishedOffersForProduct($productId);
        }

        if ($offers === []) {
            $this->getMessageManager()->addError(__('商品不存在或当前不可用。'));
            return (string)$this->redirect($this->getUrl('products'));
        }

        $canonicalSlug = strtolower(trim((string)($offers[0]['slug'] ?? '')));
        $productIdForLabels = max(
            0,
            (int)($offers[0]['product_id'] ?? $productId),
        );
        $variantLabels = $this->variantLabels->forProduct($productIdForLabels);
        if ($slug === '' && $canonicalSlug !== '') {
            $target = $this->getUrl('product/' . $canonicalSlug);
            $query = [];
            if ($requestedOfferUuid !== '') {
                $query['offer'] = $requestedOfferUuid;
            } else {
                foreach ($this->variantSelection->collectAxisCodes($offers) as $axisCode) {
                    $axisValue = trim((string)$this->request->getParam($axisCode, ''));
                    if ($axisValue !== '') {
                        $query[$axisCode] = $variantLabels->publicOptionCode($axisCode, $axisValue);
                    }
                }
            }
            if ($query !== []) {
                $target .= '?' . http_build_query($query);
            }
            return (string)$this->redirect($target);
        }

        $selectedOffer = $this->variantSelection->resolveSelectedOffer(
            $offers,
            $variantLabels->canonicalizeAxisQuery(
                $this->request->getParams(),
                $this->variantSelection->collectAxisCodes($offers),
            ),
        );
        $displayOffer = $selectedOffer ?? $offers[0];
        $requiresExplicitSelection = count($offers) > 1
            && $selectedOffer === null
            && $requestedOfferUuid === ''
            && !$this->hasAxisSelection($offers);
        if ($requiresExplicitSelection) {
            $displayOffer['global_offer_uuid'] = '';
            $displayOffer['sellable'] = false;
            $displayOffer['selection_required'] = true;
            $displayOffer['message'] = (string)__('请选择规格后再加入购物车。');
        } else {
            $displayOffer['selection_required'] = false;
        }

        $name = trim((string)($displayOffer['name'] ?? ''));
        $publicRoute = $canonicalSlug !== ''
            ? 'product/' . $canonicalSlug
            : 'product/' . (int)($displayOffer['product_id'] ?? 0);

        $this->layoutType = 'product';
        $this->request->setGet('page_type', 'product');
        $productIdForLayout = max(0, (int)($displayOffer['product_id'] ?? 0));
        $layoutResolution = $this->resolveProductLayoutOption($productIdForLayout, $displayOffer);
        $layoutOption = (string)($layoutResolution['layout_option'] ?? 'default');
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        $this->layoutType = 'product.' . $layoutOption;
        $this->request->setGet('layout_type', 'product');
        $this->request->setGet('layout_option', $layoutOption);
        $this->request->setGet('theme_layout_option', $layoutOption);
        if ((int)($layoutResolution['schedule_id'] ?? 0) > 0) {
            $this->request->setGet('theme_layout_schedule_id', (string)(int)$layoutResolution['schedule_id']);
        }
        $resolvedTargetType = (string)($layoutResolution['target_type'] ?? '');
        $resolvedTargetId = (int)($layoutResolution['target_id'] ?? 0);
        if ($resolvedTargetType !== '' && $resolvedTargetType !== ThemeVirtualLayout::TARGET_GLOBAL && $resolvedTargetId > 0) {
            $this->request->setGet('theme_layout_target_type', $resolvedTargetType);
            $this->request->setGet('theme_layout_target_id', (string)$resolvedTargetId);
            $this->request->setGet('theme_layout_source_target_type', $resolvedTargetType);
            $this->request->setGet('theme_layout_source_target_id', (string)$resolvedTargetId);
        }
        $this->assign('product_layout_resolution', $layoutResolution);
        $this->request->setGet('theme_public_route', $publicRoute);
        $this->request->setGet('theme_page_title', $name !== '' ? $name : (string)__('商品详情'));
        $seoTitle = trim((string)($displayOffer['meta_name'] ?? '')) ?: ($name !== '' ? $name : (string)__('商品详情'));
        $seoDescription = trim((string)($displayOffer['meta_description'] ?? ''));
        if ($seoDescription === '') {
            $seoDescription = trim((string)($displayOffer['short_description'] ?? $displayOffer['description'] ?? ''));
        }
        $seoKeywords = trim((string)($displayOffer['meta_keywords'] ?? ''));
        $seoImage = trim((string)($displayOffer['image'] ?? ''));

        $this->assign('page_title', $name !== '' ? $name : __('商品详情'));
        // Head rendering uses a separate template instance. Keep the body-local product
        // assignment, and publish the same EAV projection through the shared SEO profile.
        $seoProduct = $displayOffer;
        $seoProduct['storefront_offers'] = $offers;
        $this->assign('product', $seoProduct);
        $this->assign('seo', [
            'page_type' => 'product',
            'title' => $seoTitle,
            'description' => $seoDescription,
            'keywords' => $seoKeywords,
            'image' => $seoImage,
            'product' => $seoProduct,
        ]);
        $this->assign('meta_title', $seoTitle);
        $this->assign('meta_description', $seoDescription);
        $this->assign('meta_keywords', $seoKeywords);
        $this->assign('storefront_offer', $displayOffer);
        $this->assign('storefront_offers', $offers);
        $this->assign(
            'selected_offer_uuid',
            $selectedOffer === null ? '' : trim((string)($selectedOffer['global_offer_uuid'] ?? '')),
        );
        $this->assign(
            'variant_catalog',
            $this->variantSelection->compactCatalogMedia($this->enrichCatalogOptionCodes(
                $this->variantSelection->buildCatalog($offers, $displayOffer),
                $variantLabels,
            )),
        );

        $productIdForView = max(0, (int)($displayOffer['product_id'] ?? 0));
        if ($productIdForView > 0) {
            $viewedEvent = ['product_id' => $productIdForView];
            $this->events->dispatch('Weline_Product::product_viewed', $viewedEvent);
        }

        // Product main info is rendered by the product-info widget (default_injections → product-main).
        $html = (string)$this->fetch('Weline_Product::templates/frontend/catalog/detail-shell.phtml');
        return $html;
    }

    /** @param list<array<string, mixed>> $offers */
    private function hasAxisSelection(array $offers): bool
    {
        foreach ($this->variantSelection->collectAxisCodes($offers) as $axisCode) {
            if (trim((string)$this->request->getParam($axisCode, '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>} $catalog
     * @return array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>,selected:array<string,string>}
     */
    private function enrichCatalogOptionCodes(
        array $catalog,
        ?StorefrontEavLabelResolver $variantLabels = null,
    ): array {
        $variantLabels ??= $this->variantLabels;
        foreach ($catalog['axes'] as $axisIndex => $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $axisCode = strtolower(trim((string)($axis['code'] ?? '')));
            if ($axisCode === '') {
                continue;
            }
            $options = [];
            foreach ((array)($axis['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $value = trim((string)($option['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = trim((string)($option['label'] ?? ''));
                $resolvedLabel = trim($variantLabels->resolve($axisCode, $value));
                if ($resolvedLabel !== '' && ($label === '' || $label === $value)) {
                    $option['label'] = $resolvedLabel;
                }
                if (trim((string)($option['code'] ?? '')) === '') {
                    $option['code'] = $variantLabels->publicOptionCode($axisCode, $value);
                }
                $options[] = $option;
            }
            $catalog['axes'][$axisIndex]['options'] = $options;
        }

        return $catalog;
    }

    /**
     * @param array<string,mixed> $displayOffer
     * @return array<string,mixed>
     */
    private function resolveProductLayoutOption(int $productId, array $displayOffer): array
    {
        if ($productId <= 0 || !class_exists(ProductLayoutResolveService::class)) {
            return [
                'layout_option' => 'default',
                'source' => 'file',
                'schedule_id' => 0,
                'target_type' => ThemeVirtualLayout::TARGET_GLOBAL,
                'target_id' => 0,
                'fallback_chain' => ['file:default'],
            ];
        }
        $categoryIds = $this->categoryIdsForProduct($productId, $displayOffer);
        /** @var ProductLayoutResolveService $resolver */
        $resolver = ObjectManager::getInstance(ProductLayoutResolveService::class);
        $resolved = $resolver->resolveForProduct(
            $productId,
            $categoryIds,
            null,
            null,
            null,
            max(0, (int)($displayOffer['website_id'] ?? $this->request->getParam('website_id', 0))),
        );
        if (class_exists(ProductLayoutCacheBustService::class)) {
            ObjectManager::getInstance(ProductLayoutCacheBustService::class)
                ->bustIfScheduleMembershipChanged($productId, (int)($resolved['schedule_id'] ?? 0));
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $displayOffer
     * @return list<int>
     */
    private function categoryIdsForProduct(int $productId, array $displayOffer): array
    {
        $ids = [];
        foreach (['category_id', 'primary_category_id', 'main_category_id'] as $key) {
            $id = (int)($displayOffer[$key] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $pathCategory = max(0, (int)$this->request->getParam('category_id', 0));
        if ($pathCategory > 0) {
            $ids = [$pathCategory => $pathCategory] + $ids;
        }
        if (!class_exists(CategoryLinkRepository::class)) {
            return array_values($ids);
        }
        try {
            $websiteId = max(0, (int)($displayOffer['website_id'] ?? $this->request->getParam('website_id', 0)));
            /** @var CategoryLinkRepository $links */
            $links = ObjectManager::getInstance(CategoryLinkRepository::class);
            foreach ($links->listByProductIds($websiteId, [$productId], [0]) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $categoryId = (int)($row['category_id'] ?? 0);
                $selected = (int)($row['selected'] ?? 1);
                if ($categoryId > 0 && $selected === 1) {
                    $ids[$categoryId] = $categoryId;
                }
            }
        } catch (\Throwable) {
        }

        return array_values($ids);
    }
}
