<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Backend\Invoice;

use WeShop\Invoice\Service\InvoiceAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Invoice::invoice_management', 'Invoice Management', 'mdi mdi-file-document-outline', 'Manage invoices', 'Weline_Backend::order_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly InvoiceAdminPageDataService $invoiceAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Invoice::invoice_management_index', 'View invoices', 'mdi mdi-file-search-outline', 'View invoice management page')]
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
