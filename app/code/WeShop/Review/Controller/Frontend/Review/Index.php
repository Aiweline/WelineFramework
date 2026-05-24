<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Frontend\Review;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Review\Service\ReviewPageDataService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class Index extends BaseController
{
    protected ?string $layoutType = 'review';

    public function __construct(
        private readonly ReviewPageDataService $reviewPageDataService,
        private readonly Url $url,
        private readonly ?CustomerContextInterface $customerContext = null
    ) {
    }

    public function index(): string
    {
        $productId = (int) ($this->request->getParam('product_id') ?? 0);
        if ($productId <= 0) {
            $this->getMessageManager()->addError(__('Product ID cannot be empty.'));
            $this->redirect('catalog/category');
            return '';
        }

        $page = (int) max(1, ($this->request->getParam('page') ?? 1));
        $pageSize = (int) max(5, min(50, ($this->request->getParam('page_size') ?? 20)));

        foreach ($this->reviewPageDataService->build($productId, $page, $pageSize) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('product_id', $productId);
        $this->assign('create_url', $this->url->getUrl('review/create'));
        $this->assign('is_customer_logged_in', $this->isCustomerLoggedIn());
        $this->assign('title', __('Product Reviews'));

        return $this->fetch();
    }

    private function isCustomerLoggedIn(): bool
    {
        try {
            $customerContext = $this->customerContext ?? ObjectManager::getInstance(CustomerContextInterface::class);
        } catch (\Throwable) {
            return false;
        }

        return (int) ($customerContext->getUserId() ?? 0) > 0;
    }
}
