<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Backend\Invoice;

use WeShop\Invoice\Service\InvoiceAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Invoice::invoice_management_view', 'Invoice view actions', 'mdi mdi-eye-outline', 'View invoice details', 'WeShop_Invoice::invoice_management')]
class View extends BaseController
{
    public function __construct(
        private readonly InvoiceAdminPageDataService $invoiceAdminPageDataService
    ) {
    }

    #[Acl('WeShop_Invoice::invoice_management_view_index', 'View invoice detail', 'mdi mdi-eye', 'View invoice detail page')]
    public function index(): string
    {
        $invoiceId = (int) $this->request->getParam('id', 0);
        if ($invoiceId <= 0) {
            $this->getMessageManager()->addError(__('Invoice ID is required.'));
            $this->redirect('*/backend/invoice');
            return '';
        }

        try {
            $detailData = $this->invoiceAdminPageDataService->getDetailData($invoiceId);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Invoice not found.'));
            $this->redirect('*/backend/invoice');
            return '';
        }

        $this->assign(array_merge(
            [
                'title' => (string) __('Invoice Detail'),
                'invoiceIndexUrl' => $this->getUrl('*/backend/invoice'),
                'invoiceIssueUrl' => $this->getUrl('*/backend/invoice/issue'),
            ],
            $detailData
        ));

        return (string) $this->fetchBase('WeShop_Invoice::backend/templates/invoice/view/index.phtml');
    }
}
