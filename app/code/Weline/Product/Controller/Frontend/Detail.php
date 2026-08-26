<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Product\Service\StorefrontCatalogViewService;

final class Detail extends FrontendController
{
    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
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
            if ($requestedOfferUuid !== '') {
                $target .= '?offer=' . rawurlencode($requestedOfferUuid);
            }
            return (string)$this->redirect($target);
        }

        $selectedOffer = null;
        if (count($offers) === 1 && $requestedOfferUuid === '') {
            $selectedOffer = $offers[0];
        } elseif ($requestedOfferUuid !== '') {
            foreach ($offers as $candidate) {
                if (hash_equals(
                    trim((string)($candidate['global_offer_uuid'] ?? '')),
                    $requestedOfferUuid,
                )) {
                    $selectedOffer = $candidate;
                    break;
                }
            }
        }

        $displayOffer = $selectedOffer ?? $offers[0];
        if (count($offers) > 1 && $selectedOffer === null) {
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

        // Product main info is rendered by the product-info widget (default_injections → product-main).
        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/detail-shell.phtml');
    }

}
