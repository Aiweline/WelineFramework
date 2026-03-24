<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderDetailPageDataService;

class View extends BaseController
{
    protected const CONTENT_TEMPLATE = 'templates/Frontend/Order/View/index';

    protected ?string $layoutType = 'order';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderDetailPageDataService $orderDetailPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = $this->customerContext->getUserId();
        if (!$customerId) {
            $this->redirect('customer/account/login');
            return '';
        }

        $pageData = $this->orderDetailPageDataService->build(
            (int) $customerId,
            (int) ($this->request->getParam('id') ?? 0)
        );
        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('page_title', (string) __('Order Details'));

        return $this->renderPage();
    }

    protected function renderPage(): string
    {
        return $this->fetchTemplateWithEvents(self::CONTENT_TEMPLATE);
    }
}
