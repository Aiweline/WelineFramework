<?php

declare(strict_types=1);

namespace WeShop\B2B\Controller\Backend\Receivable;

use WeShop\B2B\Service\B2BReceivablePaymentService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_B2B::b2b_receivable_actions', 'B2B receivable actions', 'mdi mdi-cash-register', 'Record B2B receivable payments', 'WeShop_B2B::b2b_receivable')]
class Payment extends BaseController
{
    public function __construct(
        private readonly B2BReceivablePaymentService $b2bReceivablePaymentService
    ) {
    }

    #[Acl('WeShop_B2B::b2b_receivable_payment_post', 'Record B2B receivable payment', 'mdi mdi-cash-plus', 'Record B2B receivable payment data')]
    public function post(): string
    {
        $back = (string) $this->request->getParam('back_url', $this->request->getUrlBuilder()->getBackendUrl('*/backend/receivable'));
        try {
            $id = (int) $this->request->getParam('receivable_id', 0);
            $amount = (float) $this->request->getParam('amount', 0);
            $this->b2bReceivablePaymentService->applyPayment($id, $amount);
            $this->getMessageManager()->addSuccess((string) __('Payment recorded.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage() ?: (string) __('Payment failed.'));
        }

        $this->redirect($back);

        return '';
    }
}
