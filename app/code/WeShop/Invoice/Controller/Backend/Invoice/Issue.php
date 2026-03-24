<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller\Backend\Invoice;

use WeShop\Invoice\Service\InvoiceService;
use Weline\Admin\Controller\BaseController;

class Issue extends BaseController
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->getBackendUrl('*/backend/invoice'));
        $invoiceId = (int) $this->request->getParam('invoice_id', 0);

        if ($invoiceId <= 0) {
            $this->getMessageManager()->addError(__('Invoice ID is required.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $invoice = $this->invoiceService->issueInvoice($invoiceId);
            $this->getMessageManager()->addSuccess(__('Invoice issued.'));
            $this->redirect($this->getBackendUrl('*/backend/invoice/view', ['id' => $invoice->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Invoice issue failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
