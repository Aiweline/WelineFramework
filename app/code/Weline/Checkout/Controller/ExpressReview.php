<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Service\ExpressCheckoutFlowService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Manager\ObjectManager;

/**
 * Lightweight express confirm page after provider return (before capture).
 */
class ExpressReview extends FrontendController
{
    public function index(): string
    {
        $this->request->setGet('theme_page_title', (string) __('确认并付款'));
        $this->assign('page_title', __('确认并付款'));
        $this->assign('title', __('确认并付款'));

        $transactionNo = trim((string) ($this->request->getGet('transaction_no') ?? $this->request->getParam('transaction_no') ?? ''));
        $groupUuid = trim((string) ($this->request->getGet('checkout_group_uuid') ?? $this->request->getParam('checkout_group_uuid') ?? ''));
        $checkoutToken = trim((string) ($this->request->getGet('checkout_token') ?? $this->request->getParam('checkout_token') ?? ''));

        $alreadyPaid = null;
        try {
            /** @var ExpressCheckoutFlowService $flow */
            $flow = ObjectManager::getInstance(ExpressCheckoutFlowService::class);
            if ($transactionNo === '' && $groupUuid !== '') {
                $resolved = $flow->resolvePaymentTransactionNo([
                    'checkout_group_uuid' => $groupUuid,
                ]);
                if (!empty($resolved['success'])) {
                    $transactionNo = trim((string) ($resolved['transaction_no'] ?? ''));
                }
            }
            if ($transactionNo !== '') {
                $alreadyPaid = $flow->buildAlreadyPaidResult($transactionNo, [
                    'checkout_group_uuid' => $groupUuid,
                    'checkout_token' => $checkoutToken,
                ]);
                if ($checkoutToken === '' && is_array($alreadyPaid)) {
                    $checkoutToken = trim((string) ($alreadyPaid['data']['checkout_token'] ?? ''));
                }
            }
        } catch (RedirectException $e) {
            throw $e;
        } catch (\Throwable) {
            $alreadyPaid = null;
        }

        $isAlreadyPaid = is_array($alreadyPaid) && !empty($alreadyPaid['already_paid']);
        $orderUuid = $isAlreadyPaid
            ? trim((string) ($alreadyPaid['order_uuid'] ?? $alreadyPaid['data']['order_uuid'] ?? ''))
            : '';

        // Always land paid express on success; Success soft-acks when ACL capability is missing (never /cart).
        if ($isAlreadyPaid && $orderUuid !== '') {
            return $this->redirect('checkout/success', array_filter([
                'order_uuid' => $orderUuid,
                'checkout_group_uuid' => $groupUuid !== '' ? $groupUuid : null,
                'checkout_token' => $checkoutToken !== '' ? $checkoutToken : null,
            ], static fn ($v) => $v !== null && $v !== ''));
        }

        $this->assign('transaction_no', $transactionNo);
        $this->assign('checkout_group_uuid', $groupUuid);
        $this->assign('already_paid', false);
        $this->assign('already_paid_message', '');
        $this->assign('already_paid_method_label', '');
        $this->assign('already_paid_redirect', '');
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/express-review.phtml');
    }
}
