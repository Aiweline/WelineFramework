<?php

declare(strict_types=1);

namespace WeShop\Invoice\Api\Rest\V1;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Invoice\Service\InvoicePageDataService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Invoice extends FrontendRestController
{
    public function __construct(
        private ?CustomerContextInterface $customerContext = null,
        private ?InvoicePageDataService $invoicePageDataService = null
    ) {
    }

    public function getList(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('Please log in first'),
                'data' => ['invoices' => []],
            ]);
        }

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('Success'),
            'data' => $this->getInvoicePageDataService()->build(
                $customerId,
                max(1, (int) ($this->request->getParam('page', 1) ?? 1)),
                max(1, (int) ($this->request->getParam('page_size', 20) ?? 20))
            ),
        ]);
    }

    protected function fetchJson(array $data): string
    {
        return (string) ($this->fetch($data, self::fetch_JSON) ?: '');
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getInvoicePageDataService(): InvoicePageDataService
    {
        return $this->invoicePageDataService ??= ObjectManager::getInstance(InvoicePageDataService::class);
    }
}
