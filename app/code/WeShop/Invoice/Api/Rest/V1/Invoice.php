<?php

declare(strict_types=1);

namespace WeShop\Invoice\Api\Rest\V1;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Invoice\Service\InvoicePageDataService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Invoice extends FrontendRestController
{
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 50;

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
                $this->resolvePage(),
                $this->resolvePageSize()
            ),
        ]);
    }

    protected function fetchJson(array $data): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }

    private function getInvoicePageDataService(): InvoicePageDataService
    {
        return $this->invoicePageDataService ??= ObjectManager::getInstance(InvoicePageDataService::class);
    }

    private function resolvePage(): int
    {
        return max(1, (int) ($this->request->getParam('page', self::DEFAULT_PAGE) ?? self::DEFAULT_PAGE));
    }

    private function resolvePageSize(): int
    {
        $pageSize = (int) ($this->request->getParam('page_size', self::DEFAULT_PAGE_SIZE) ?? self::DEFAULT_PAGE_SIZE);
        return max(1, min(self::MAX_PAGE_SIZE, $pageSize));
    }
}
