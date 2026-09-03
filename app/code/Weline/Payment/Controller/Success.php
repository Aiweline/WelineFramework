<?php

declare(strict_types=1);

namespace Weline\Payment\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\PaymentCheckoutSessionPersistenceService;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentReturnPresentationService;
use Weline\Payment\Service\PaymentService;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Payment L1 terminal success at /payment/success.
 */
class Success extends FrontendController
{
    public function index()
    {
        $this->layoutType = 'checkout';
        /** @var PaymentService $paymentService */
        $paymentService = ObjectManager::getInstance(PaymentService::class);
        $transactionNo = (string) $this->request->getParam('transaction_no', '');
        $transaction = null;
        if ($transactionNo !== '') {
            $transaction = $paymentService->queryPaymentStatus($transactionNo);
        }

        $this->assign('page_title', (string) __('支付成功'));
        $this->assign('transaction', $transaction);
        $this->assign('payment_success_terminal', true);
        $this->assign('payment_return_empty', false);
        $this->assignPaymentReturnContext($transaction);

        return $this->fetch('Weline_Payment::templates/Frontend/checkout/return.phtml');
    }

    private function assignPaymentReturnContext(?PaymentTransaction $transaction): void
    {
        if ($transaction === null) {
            return;
        }

        /** @var PaymentMethodManager $methodManager */
        $methodManager = ObjectManager::getInstance(PaymentMethodManager::class);
        /** @var OrderFacadeInterface $orders */
        $orders = ObjectManager::getInstance(OrderFacadeInterface::class);
        /** @var StorefrontCatalogViewService $catalog */
        $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
        /** @var PaymentCheckoutSessionPersistenceService $checkoutSessionPersistence */
        $checkoutSessionPersistence = ObjectManager::getInstance(PaymentCheckoutSessionPersistenceService::class);
        /** @var PaymentReturnPresentationService $returnPresentation */
        $returnPresentation = ObjectManager::getInstance(PaymentReturnPresentationService::class);

        $methodCode = (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE);
        $method = $methodManager->getMethodByCode($methodCode);
        $paymentMethodLabel = $methodCode;
        if ($method !== null) {
            $display = $methodManager->getEffectiveDisplayMetadata($method);
            $paymentMethodLabel = (string) ($display['title'] ?? $method->getData('name') ?? $methodCode);
        }
        $this->assign('payment_method_label', $paymentMethodLabel);

        $paidAt = trim((string) $transaction->getData(PaymentTransaction::schema_fields_PAID_AT));
        $this->assign('payment_paid_at_label', $paidAt);

        $orderUuid = trim((string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID));
        $order = null;
        if ($orderUuid !== '') {
            try {
                $order = $orders->get($orderUuid);
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
                $this->assign('order_v2_items_display', $this->buildOrderItemsDisplay($order->items, $catalog));
            } catch (\Throwable) {
                // 订单不可读时仍展示交易摘要。
            }
        }

        $session = $checkoutSessionPersistence->loadByTransaction($transaction);
        $presentation = $returnPresentation->build($transaction, $order, $session);
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
    private function buildOrderItemsDisplay(array $items, StorefrontCatalogViewService $catalog): array
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
                $offers = $catalog->publishedOffersForProductIds(
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
}
