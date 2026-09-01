<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontVariantSelectionService;

final class Detail extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly StorefrontVariantSelectionService $variantSelection,
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
                        $query[$axisCode] = $axisValue;
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
            $this->request->getParams(),
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
        $this->assign('page_title', $name !== '' ? $name : __('商品详情'));
        $this->assign('storefront_offer', $displayOffer);
        $this->assign('storefront_offers', $offers);
        $this->assign(
            'selected_offer_uuid',
            $selectedOffer === null ? '' : trim((string)($selectedOffer['global_offer_uuid'] ?? '')),
        );
        $this->assign(
            'variant_catalog',
            $this->variantSelection->buildCatalog($offers, $displayOffer),
        );

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
}
