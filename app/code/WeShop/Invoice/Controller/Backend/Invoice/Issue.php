<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Backend\Invoice;

use WeShop\Invoice\Service\InvoiceService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Invoice::invoice_management_actions', 'Invoice actions', 'mdi mdi-file-document-edit-outline', 'Issue invoices', 'WeShop_Invoice::invoice_management')]
class Issue extends BaseController
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    #[Acl('WeShop_Invoice::invoice_management_issue_post', 'Issue invoice', 'mdi mdi-file-document-plus-outline', 'Issue invoice data')]
    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->getUrl('*/backend/invoice'));
        $invoiceId = (int) $this->request->getParam('invoice_id', 0);

        if ($invoiceId <= 0) {
            $this->getMessageManager()->addError(__('Invoice ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $invoice = $this->invoiceService->issueInvoice($invoiceId);
            $this->getMessageManager()->addSuccess(__('Invoice issued.'));
            $this->redirect($this->getUrl('*/backend/invoice/view', ['id' => $invoice->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Invoice issue failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    #[Acl('WeShop_Invoice::invoice_management_issue_index', 'Open invoice issue route', 'mdi mdi-file-document-plus', 'Open invoice issue route')]
    public function index(): string
    {
        return $this->post();
    }
}
