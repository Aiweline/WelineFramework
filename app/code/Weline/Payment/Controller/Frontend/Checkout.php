<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentService;

class Checkout extends FrontendController
{
    private PaymentService $paymentService;

    public function __construct(
        ObjectManager $objectManager,
        ?PaymentService $paymentService = null
    ) {
        $this->paymentService = $paymentService ?? $objectManager->getInstance(PaymentService::class);
    }

    public function create()
    {
        $methodCode = (string) $this->request->getParam('method_code', '');
        $payableId = (string) $this->request->getParam('payable_id', $this->request->getParam('order_id', ''));
        $payableType = (string) $this->request->getParam('payable_type', 'order');
        $amount = (float) $this->request->getParam('amount', 0);
        $currency = (string) $this->request->getParam('currency', $this->request->getParam('currency_code', 'CNY'));

        if ($methodCode === '' || $payableId === '' || $amount <= 0) {
            return $this->error(__('Payment method, payable ID and amount are required.'));
        }

        try {
            $transaction = $this->paymentService->createPayment($methodCode, [
                'order_id' => (string) $this->request->getParam('order_id', $payableId),
                'payable_type' => $payableType,
                'payable_id' => $payableId,
                'amount' => $amount,
                'currency' => $currency,
                'country_code' => (string) $this->request->getParam('country_code', ''),
                'language_code' => (string) $this->request->getParam('language_code', ''),
                'subject' => (string) $this->request->getParam('subject', __('Payment')),
                'description' => (string) $this->request->getParam('description', ''),
                'scope' => (string) $this->request->getParam('scope', ''),
                'environment' => (string) $this->request->getParam('environment', 'sandbox'),
                'return_url' => $this->getUrl('*/frontend/checkout/return'),
                'notify_url' => $this->getUrl('*/frontend/callback/notify'),
            ]);

            return $this->success(__('Payment transaction created.'), array_merge([
                'transaction_no' => $transaction->getData('transaction_no'),
                'status' => $transaction->getData('status'),
            ], $transaction->getResponseData()));
        } catch (\Throwable $throwable) {
            return $this->error(__('Create payment failed: %{message}', ['message' => $throwable->getMessage()]));
        }
    }

    public function fake()
    {
        $this->assign('payment_fake_mode', true);

        return $this->fetch();
    }

    public function return()
    {
        $fakeMode = (string) $this->request->getParam('fake', $this->request->getParam('payment_fake_mode', '')) === '1';
        if ($fakeMode) {
            $this->assign('payment_fake_mode', true);

            return $this->fetch('fake');
        }

        $transactionNo = (string) $this->request->getParam('transaction_no', '');

        if ($transactionNo === '') {
            $this->getMessageManager()->addError(__('Payment transaction number is required.'));

            return $this->redirect('/');
        }

        try {
            $transaction = $this->paymentService->queryPaymentStatus($transactionNo);
            if ($transaction && $transaction->isSuccess()) {
                $this->getMessageManager()->addSuccess(__('Payment succeeded.'));
            } else {
                $this->getMessageManager()->addError(__('Payment failed or is still processing.'));
            }

            $this->assign('transaction', $transaction);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('Query payment status failed: %{message}', ['message' => $throwable->getMessage()]));
        }

        return $this->fetch();
    }
}
