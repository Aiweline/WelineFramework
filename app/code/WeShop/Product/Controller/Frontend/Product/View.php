<?php

declare(strict_types=1);

namespace WeShop\Product\Controller\Frontend\Product;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Product\Service\ProductViewPageDataService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;

class View extends BaseController
{
    private const PRODUCT_LIST_ROUTE = 'weshop/product/list';

    protected ?string $layoutType = 'product';

    public function __construct(
        private readonly StorefrontRecentlyViewedRecorder $storefrontRecentlyViewedRecorder,
        private readonly ProductViewPageDataService $productViewPageDataService
    ) {
    }

    public function index(): string
    {
        $productId = (int) ($this->request->getParam('id') ?? $this->request->getParam('product_id') ?? 0);

        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Product ID is required.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $pageData = $this->productViewPageDataService->build($productId);
        if (!$pageData) {
            $this->getMessageManager()->addError(__('Product is unavailable.'));
            $this->redirect(self::PRODUCT_LIST_ROUTE);
            return '';
        }

        $this->storefrontRecentlyViewedRecorder->recordProductView($productId);

        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
