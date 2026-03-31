<?php

declare(strict_types=1);

namespace WeShop\B2B\Api\Rest\V1;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\B2B\Service\CreditService;
use WeShop\B2B\Service\ReceivableService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class B2BInvoice extends FrontendRestController
{
    public function __construct(
        private ?CustomerContextInterface $customerContext = null
    ) {
    }

    public function getReceivables(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('Please log in first'),
                'data' => ['items' => []],
            ]);
        }

        $page = max(1, (int) ($this->request->getParam('page', 1)));
        $pageSize = max(1, min(50, (int) ($this->request->getParam('page_size', 20))));

        $service = ObjectManager::getInstance(ReceivableService::class);
        $list = $service->getReceivableList($page, $pageSize, ['customer_id' => $customerId]);

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('Success'),
            'data' => $list,
        ]);
    }

    public function getCredit(): string
    {
        $customerId = (int) ($this->getCustomerContext()->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->fetchJson([
                'code' => 401,
                'msg' => (string) __('Please log in first'),
                'data' => [],
            ]);
        }

        $summary = ObjectManager::getInstance(CreditService::class)->getCreditSummary($customerId);

        return $this->fetchJson([
            'code' => 200,
            'msg' => (string) __('Success'),
            'data' => $summary,
        ]);
    }

    protected function fetchJson(array $data): string
    {
        $response = $this->request->getResponse();
        $httpCode = match ((int) ($data['code'] ?? 200)) {
            401 => 401,
            404 => 404,
            403 => 403,
            default => 200,
        };
        $response->setHttpResponseCode($httpCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function getCustomerContext(): CustomerContextInterface
    {
        return $this->customerContext ??= ObjectManager::getInstance(CustomerContextInterface::class);
    }
}
