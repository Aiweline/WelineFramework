<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentBrowserReturnLandingOrchestrator;
use Weline\Payment\Service\PaymentCheckoutSessionPersistenceService;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentReturnPresentationService;
use Weline\Payment\Service\PaymentService;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

class Checkout extends FrontendController
{
    private PaymentService $paymentService;
    private PaymentMethodManager $methodManager;
    private OrderFacadeInterface $orders;
    private StorefrontCatalogViewService $catalog;
    private PaymentCheckoutSessionPersistenceService $checkoutSessionPersistence;
    private PaymentReturnPresentationService $returnPresentation;

    public function __construct(
        ObjectManager $objectManager,
        ?PaymentService $paymentService = null,
        ?PaymentMethodManager $methodManager = null,
        ?OrderFacadeInterface $orders = null,
        ?StorefrontCatalogViewService $catalog = null,
        ?PaymentCheckoutSessionPersistenceService $checkoutSessionPersistence = null,
        ?PaymentReturnPresentationService $returnPresentation = null,
    ) {
        $this->paymentService = $paymentService ?? $objectManager->getInstance(PaymentService::class);
        $this->methodManager = $methodManager ?? $objectManager->getInstance(PaymentMethodManager::class);
        $this->orders = $orders ?? $objectManager->getInstance(OrderFacadeInterface::class);
        $this->catalog = $catalog ?? $objectManager->getInstance(StorefrontCatalogViewService::class);
        $this->checkoutSessionPersistence = $checkoutSessionPersistence
            ?? $objectManager->getInstance(PaymentCheckoutSessionPersistenceService::class);
        $this->returnPresentation = $returnPresentation
            ?? $objectManager->getInstance(PaymentReturnPresentationService::class);
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
        $transactionNo = (string) $this->request->getParam('transaction_no', '');
        if ($transactionNo !== '') {
            try {
                $transaction = $this->paymentService->queryPaymentStatus($transactionNo);
                if ($transaction !== null && $transaction->isSuccess()) {
                    /** @var PaymentBrowserReturnLandingOrchestrator $orchestrator */
                    $orchestrator = ObjectManager::getInstance(PaymentBrowserReturnLandingOrchestrator::class);
                    $decision = $orchestrator->decide($transaction);
                    if (($decision['decision'] ?? '') !== PaymentBrowserReturnLandingOrchestrator::DECISION_STATUS_PAGE) {
                        return $this->redirect($this->getUrl($decision['redirect_path'], $decision['redirect_params']));
                    }
                }
            } catch (\Throwable) {
                // fall through to status page
            }
        }

        $this->layoutType = 'checkout';
        $this->request->setGet('page_type', 'payment');
        $this->request->setGet('theme_page_title', (string) __('支付结果'));
        $this->assign('page_title', (string) __('支付结果'));

        $fakeMode = (string) $this->request->getParam('fake', $this->request->getParam('payment_fake_mode', '')) === '1';
        if ($fakeMode) {
            $this->assign('payment_fake_mode', true);

            return $this->fetch('fake');
        }

        $params = $this->request->getParams();
        if (!\is_array($params)) {
            $params = [];
        }
        if (!isset($params['outcome']) || trim((string) $params['outcome']) === '') {
            $fromGet = trim((string) $this->request->getParam('outcome', ''));
            if ($fromGet === '' && isset($_GET['outcome'])) {
                $fromGet = trim((string) $_GET['outcome']);
            }
            if ($fromGet !== '') {
                $params['outcome'] = $fromGet;
            }
        }
        $isCancel = \Weline\Payment\Service\PaymentBrowserCallbackRoutes::isCancelOutcome($params);
        $cancelAlready = false;
        if ($isCancel) {
            $cancelAlready = \Weline\Payment\Service\PaymentBrowserCallbackRoutes::isCancelAlready($params);
            if (!$cancelAlready && $transactionNo !== '') {
                // 幂等回访：交易已失败则视为「已取消」
                try {
                    $probe = $this->paymentService->queryPaymentStatus($transactionNo);
                    if ($probe !== null && $probe->isFailed()) {
                        $cancelAlready = true;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
        $transaction = null;

        if ($transactionNo === '') {
            if ($isCancel) {
                $title = $cancelAlready ? (string) __('已取消') : (string) __('已取消成功');
                $this->assign('transaction', null);
                $this->assign('payment_return_empty', false);
                $this->assign('payment_return_cancelled', true);
                $this->assign('payment_cancel_already', $cancelAlready);
                $this->assign('page_title', $title);
                $this->request->setGet('theme_page_title', $title);

                return $this->fetch();
            }
            if ($this->isProductionLive()) {
                return $this->redirect('/');
            }
            $this->assign('transaction', null);
            $this->assign('payment_return_empty', true);

            return $this->fetch();
        }

        try {
            $transaction = $this->paymentService->queryPaymentStatus($transactionNo);
            if ($isCancel) {
                if (!$cancelAlready && $transaction !== null && $transaction->isFailed()) {
                    $cancelAlready = true;
                }
                $title = $cancelAlready ? (string) __('已取消') : (string) __('已取消成功');
                $this->assign('payment_return_cancelled', true);
                $this->assign('payment_cancel_already', $cancelAlready);
                $this->assign('page_title', $title);
                $this->request->setGet('theme_page_title', $title);
            } elseif ($transaction && $transaction->isSuccess()) {
                $this->getMessageManager()->addSuccess(__('Payment succeeded.'));
            } elseif ($transaction && !$transaction->isSuccess()) {
                $this->getMessageManager()->addError(__('Payment failed or is still processing.'));
            } else {
                $this->getMessageManager()->addError(__('Payment failed or is still processing.'));
            }

            $this->assign('transaction', $transaction);
            $this->assign('payment_return_empty', false);
            $this->assign('payment_return_status_only', true);
            $this->assignPaymentReturnContext($transaction);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('Query payment status failed: %{message}', ['message' => $throwable->getMessage()]));
            $this->assign('transaction', null);
            $this->assign('payment_return_empty', false);
            if ($isCancel) {
                $this->assign('payment_return_cancelled', true);
                $this->assign('payment_cancel_already', $cancelAlready);
            }
        }

        return $this->fetch();
    }

    private function assignPaymentReturnContext(?PaymentTransaction $transaction): void
    {
        if ($transaction === null) {
            return;
        }

        $methodCode = (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE);
        $method = $this->methodManager->getMethodByCode($methodCode);
        $paymentMethodLabel = $methodCode;
        if ($method !== null) {
            $display = $this->methodManager->getEffectiveDisplayMetadata($method);
            $paymentMethodLabel = (string) ($display['title'] ?? $method->getData('name') ?? $methodCode);
        }
        $this->assign('payment_method_label', $paymentMethodLabel);

        $paidAt = trim((string) $transaction->getData(PaymentTransaction::schema_fields_PAID_AT));
        $this->assign('payment_paid_at_label', $paidAt);

        $orderUuid = trim((string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID));
        $order = null;
        if ($orderUuid !== '') {
            try {
                $order = $this->orders->get($orderUuid);
                $this->assign('order_uuid', $orderUuid);
                $this->assign('order_v2', $order->toArray());
                $this->assign('order_v2_display_number', $order->displayNumber ?: $order->orderUuid);
                $this->assign('order_v2_status', $order->status);
                $this->assign(
                    'order_v2_total_label',
                    sprintf(
                        '%s %s',
                        $order->currency,
                        number_format(((int) ($order->money['grand_total_minor'] ?? 0)) / 100, 2, '.', ','),
                    ),
                );
                $this->assign('order_v2_items_display', $this->buildOrderItemsDisplay($order->items));
            } catch (\Throwable) {
                // 订单不可读时仍展示交易摘要。
            }
        }

        $session = $this->checkoutSessionPersistence->loadByTransaction($transaction);
        $presentation = $this->returnPresentation->build($transaction, $order, $session);
        $this->assign('order_v2_totals_lines', $presentation['totals_lines']);
        $this->assign('order_v2_status_label', $presentation['status_label']);
        $this->assign('shipping_method_label', $presentation['shipping_method_label']);
        if ($transaction->isSuccess()) {
            $this->assign('payment_return_status_only', false);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function buildOrderItemsDisplay(array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

        $imageByProductId = [];
        if ($productIds !== []) {
            try {
                $offers = $this->catalog->publishedOffersForProductIds(
                    $productIds,
                    max(24, count($productIds) * 3),
                );
                foreach ($offers as $offer) {
                    $productId = (int) ($offer['product_id'] ?? 0);
                    if ($productId > 0 && !isset($imageByProductId[$productId])) {
                        $imageByProductId[$productId] = (string) ($offer['image'] ?? '');
                    }
                }
            } catch (\Throwable) {
                $imageByProductId = [];
            }
        }

        $display = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $imageResolved = StorefrontImagePlaceholder::resolve(
                $imageByProductId[$productId] ?? '',
                $productId,
            );
            $display[] = array_merge($item, [
                'image_src' => $imageResolved['src'],
                'image_fallback' => $imageResolved['fallback'],
            ]);
        }

        return $display;
    }

    private function isProductionLive(): bool
    {
        $systemEnv = strtolower(trim((string) Env::get('system.env', '')));
        if ($systemEnv === 'production' || $systemEnv === 'prod') {
            return true;
        }

        $deploy = strtolower(trim((string) Env::get('deploy', '')));
        if ($deploy === '') {
            $deploy = strtolower(trim((string) Env::get('system.deploy', '')));
        }

        return $deploy === 'production' || $deploy === 'prod';
    }
}
