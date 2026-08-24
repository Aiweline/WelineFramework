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

        $offer = null;
        if ($slug !== '') {
            $offer = $this->catalog->publishedOfferBySlug($slug);
        } elseif ($productId > 0) {
            $offer = $this->catalog->publishedOffer($productId);
            $canonicalSlug = strtolower(trim((string)($offer['slug'] ?? '')));
            if ($offer !== null && $canonicalSlug !== '') {
                return (string)$this->redirect($this->getUrl('product/' . $canonicalSlug));
            }
        }

        if ($offer === null) {
            $this->getMessageManager()->addError(__('商品不存在或当前不可用。'));
            return (string)$this->redirect($this->getUrl('products'));
        }

        $name = trim((string)($offer['name'] ?? ''));
        $canonicalSlug = strtolower(trim((string)($offer['slug'] ?? '')));
        $publicRoute = $canonicalSlug !== ''
            ? 'product/' . $canonicalSlug
            : 'product/' . (int)($offer['product_id'] ?? 0);

        $this->layoutType = 'product_detail';
        $this->request->setGet('page_type', 'product_detail');
        $this->request->setGet('theme_public_route', $publicRoute);
        $this->request->setGet('theme_page_title', $name !== '' ? $name : (string)__('商品详情'));
        $this->assign('page_title', $name !== '' ? $name : __('商品详情'));
        $this->assign('storefront_offer', $offer);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/detail.phtml');
    }
}
