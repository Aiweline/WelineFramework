<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderListPageDataService;

class OrderList extends BaseController
{
    protected const CONTENT_TEMPLATE = 'templates/Frontend/Order/OrderList/index';

    protected ?string $layoutType = 'order';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderListPageDataService $orderListPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = $this->customerContext->getUserId();
        if (!$customerId) {
            $this->redirect('customer/account/login');
            return '';
        }

        $pageData = $this->orderListPageDataService->build(
            (int) $customerId,
            max(1, (int) ($this->request->getParam('page') ?? 1)),
            max(1, (int) ($this->request->getParam('page_size') ?? 20))
        );
        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('page_title', (string) __('My Orders'));

        return $this->renderPage();
    }

    protected function renderPage(): string
    {
        return $this->fetchTemplateWithEvents(self::CONTENT_TEMPLATE);
    }
}
