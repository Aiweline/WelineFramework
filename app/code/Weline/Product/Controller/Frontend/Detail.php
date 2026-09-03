<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Event\EventsManager;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontEavLabelResolver;
use Weline\Product\Service\StorefrontVariantSelectionService;

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
        if ($slug === '' && $canonicalSlug !== '') {
            $target = $this->getUrl('product/' . $canonicalSlug);
            $query = [];
            if ($requestedOfferUuid !== '') {
                $query['offer'] = $requestedOfferUuid;
            } else {
                foreach ($this->variantSelection->collectAxisCodes($offers) as $axisCode) {
                    $axisValue = trim((string)$this->request->getParam($axisCode, ''));
                    if ($axisValue !== '') {
                        $query[$axisCode] = $this->variantLabels->publicOptionCode($axisCode, $axisValue);
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
            $this->variantLabels->canonicalizeAxisQuery(
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
            $this->enrichCatalogOptionCodes(
                $this->variantSelection->buildCatalog($offers, $displayOffer),
            ),
        );

        $productIdForView = max(0, (int)($displayOffer['product_id'] ?? 0));
        if ($productIdForView > 0) {
            $viewedEvent = ['product_id' => $productIdForView];
            $this->events->dispatch('Weline_Product::product_viewed', $viewedEvent);
        }

        // Product main info is rendered by the product-info widget (default_injections → product-main).
        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/detail-shell.phtml');
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
    private function enrichCatalogOptionCodes(array $catalog): array
    {
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
                if (trim((string)($option['code'] ?? '')) === '') {
                    $option['code'] = $this->variantLabels->publicOptionCode($axisCode, $value);
                }
                $options[] = $option;
            }
            $catalog['axes'][$axisIndex]['options'] = $options;
        }

        return $catalog;
    }
}
