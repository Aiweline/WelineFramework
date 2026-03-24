<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Subscription;

use WeShop\Subscription\Service\SubscriptionAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly SubscriptionAdminPageDataService $subscriptionAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));

        $this->assign(array_merge(
            [
                'title' => (string) __('Subscription Management'),
            ],
            $this->subscriptionAdminPageDataService->getListData($page, $pageSize, [
                'status' => $this->request->getParam('status', ''),
            ])
        ));

        return (string) $this->fetchBase('WeShop_Subscription::templates/Backend/Subscription/Index/index.phtml');
    }
}
