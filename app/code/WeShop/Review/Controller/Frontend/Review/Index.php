<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Frontend\Review;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Review\Service\ReviewPageDataService;
use WeShop\Review\Service\ReviewService;
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
        $targetReviewId = (int) ($this->request->getParam('review_id') ?? 0);
        $targetReplyId = (int) ($this->request->getParam('reply_id') ?? 0);

        foreach ($this->reviewPageDataService->build($productId, $page, $pageSize, $targetReviewId, $targetReplyId) as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('product_id', $productId);
        $this->assign('review_url', $this->url->getUrl(ReviewService::FRONTEND_ROUTE));
        $this->assign('create_url', $this->url->getUrl(ReviewService::FRONTEND_CREATE_ROUTE));
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
