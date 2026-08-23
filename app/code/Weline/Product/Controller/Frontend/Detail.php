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
        $productId = (int)$this->request->getParam('id', 0);
        $offer = $this->catalog->publishedOffer($productId);
        if ($offer === null) {
            $this->getMessageManager()->addError(__('商品不存在或当前不可用。'));
            return (string)$this->redirect($this->getUrl('products'));
        }

        $name = trim((string)($offer['name'] ?? ''));
        $this->layoutType = 'product';
        $this->request->setGet('page_type', 'product');
        $this->request->setGet('theme_public_route', 'product/' . $productId);
        $this->request->setGet('theme_page_title', $name !== '' ? $name : (string)__('商品详情'));
        $this->assign('page_title', $name !== '' ? $name : __('商品详情'));
        $this->assign('storefront_offer', $offer);

        return (string)$this->fetch('Weline_Product::templates/frontend/catalog/detail.phtml');
    }
}
