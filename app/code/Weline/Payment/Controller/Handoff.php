<?php

declare(strict_types=1);

namespace Weline\Payment\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentBrowserHandoffReadinessService;

/**
 * Payment L2 handoff at /payment/handoff (+ /payment/handoff/status).
 */
class Handoff extends FrontendController
{
    public function index()
    {
        $this->layoutType = 'checkout';
        $checkoutSessionCode = (string) $this->request->getParam('checkout_session_code', '');
        $this->assign('page_title', (string) __('正在确认订单'));
        $this->assign('checkout_session_code', $checkoutSessionCode);
        $this->assign('handoff_max_wait_ms', PaymentBrowserHandoffReadinessService::HANDOFF_MAX_WAIT_MS);

        return $this->fetch('Weline_Payment::templates/Frontend/checkout/handoff.phtml');
    }

    public function status()
    {
        $checkoutSessionCode = (string) $this->request->getParam('checkout_session_code', '');
        $elapsedMs = (int) $this->request->getParam('elapsed_ms', 0);
        /** @var PaymentBrowserHandoffReadinessService $readiness */
        $readiness = ObjectManager::getInstance(PaymentBrowserHandoffReadinessService::class);
        $result = $readiness->evaluate($checkoutSessionCode, $elapsedMs);

        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
