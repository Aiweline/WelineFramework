<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Frontend\Order;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Service\OrderListPageDataService;
use Weline\Framework\Manager\ObjectManager;

class OrderList extends BaseController
{
    protected const CONTENT_TEMPLATE = 'WeShop_Order::templates/Frontend/Order/OrderList/index.phtml';

    protected ?string $layoutType = 'order';

    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?OrderListPageDataService $orderListPageDataService = null
    ) {
    }

    public function index(): string
    {
        $customerId = $this->getCustomerContext()->getUserId();
        if (!$customerId) {
            $this->redirect($this->getStorefrontLoginRoute());
            return '';
        }

        $pageData = $this->getOrderListPageDataService()->build(
            (int) $customerId,
            max(1, (int) ($this->request->getParam('page') ?? 1)),
            max(1, (int) ($this->request->getParam('page_size') ?? 20))
        );
        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        $this->assign('page_title', (string) __('我的订单'));

        return $this->renderPage();
    }

    protected function renderPage(): string
    {
        return $this->fetchTemplateWithEvents(self::CONTENT_TEMPLATE);
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getOrderListPageDataService(): OrderListPageDataService
    {
        return $this->orderListPageDataService ??= ObjectManager::getInstance(OrderListPageDataService::class);
    }
}
