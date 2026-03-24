<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Frontend\Invoice;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Invoice\Service\InvoicePageDataService;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';
    private const CONTENT_TEMPLATE = 'WeShop_Invoice::templates/Frontend/Invoice/Index/index.phtml';

    protected ?string $layoutType = 'invoice';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly InvoicePageDataService $invoicePageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $page = (int) max(1, ($this->request->getParam('page') ?? 1));
        $pageSize = (int) max(5, min(50, ($this->request->getParam('page_size') ?? 20)));

        foreach ($this->invoicePageDataService->build($customerId, $page, $pageSize) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch(self::CONTENT_TEMPLATE);
    }
}
