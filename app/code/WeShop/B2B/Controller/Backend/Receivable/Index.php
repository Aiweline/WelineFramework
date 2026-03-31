<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Receivable;

use WeShop\B2B\Service\ReceivableService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly ReceivableService $receivableService
    ) {
    }

    public function index(): string
    {
        $this->receivableService->refreshOverdueFlags();

        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $filters = [
            'customer_id' => (int) $this->request->getParam('customer_id', 0),
            'status' => (string) $this->request->getParam('status', ''),
        ];

        $list = $this->receivableService->getReceivableList($page, $pageSize, $filters);

        $this->assign([
            'title' => (string) __('B2B Receivables'),
            'receivableIndexUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/receivable'),
            'paymentPostUrl' => $this->request->getUrlBuilder()->getBackendUrl('*/backend/receivable/payment'),
            'items' => $list['items'],
            'pagination' => $list['pagination'],
            'total' => $list['total'],
            'filters' => $filters,
        ]);

        return (string) $this->fetchBase('WeShop_B2B::backend/templates/receivable/index.phtml');
    }
}
