<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Backend\Invoice;

use WeShop\Invoice\Service\InvoiceAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly InvoiceAdminPageDataService $invoiceAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $invoiceIndexUrl = $this->getUrl('*/backend/invoice');

        $this->assign(array_merge(
            [
                'title' => (string) __('Invoice Management'),
                'invoiceIndexUrl' => $invoiceIndexUrl,
                'invoiceIssueUrl' => $this->getUrl('*/backend/invoice/issue'),
            ],
            $this->invoiceAdminPageDataService->getListData($page, $pageSize, [
                'invoice_number' => $this->request->getParam('invoice_number', ''),
                'order_increment_id' => $this->request->getParam('order_increment_id', ''),
                'status' => $this->request->getParam('status', ''),
            ])
        ));

        return (string) $this->fetchBase('WeShop_Invoice::backend/templates/invoice/index.phtml');
    }
}
