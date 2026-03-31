<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Controller\Frontend\RecentlyViewed;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\RecentlyViewed\Service\RecentlyViewedPageDataService;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RecentlyViewedPageDataService $recentlyViewedPageDataService
    ) {
    }

    private const CONTENT_TEMPLATE = 'WeShop_RecentlyViewed::templates/Frontend/RecentlyViewed/Index/index.phtml';

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));

            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        foreach ($this->recentlyViewedPageDataService->build($customerId) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
