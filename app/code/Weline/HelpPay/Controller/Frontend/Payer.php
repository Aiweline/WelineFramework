<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\HelpPay\Service\HelpPayOrchestrator;
use Weline\Payment\Api\PaymentLinkServiceInterface;
use Weline\Payment\Service\PaymentLinkService;
use Weline\Theme\Model\ThemeLayout;

final class Payer extends FrontendController
{
    public function index(): string
    {
        $this->layoutType = ThemeLayout::PAGE_TYPE_DEFAULT;

        $token = trim((string) $this->request->getParam('token', ''));
        if ($token === '') {
            $token = trim((string) ($this->request->getData('token') ?? ''));
        }
        if ($token === '') {
            $token = trim((string) ($this->request->getRule('token') ?? ''));
        }
        $orch = $this->orchestrator();
        $bill = $orch->resolveHelpPayForPayer($token);
        if ($bill === null) {
            $this->assign('page_title', (string) __('帮我付链接无效'));
            $this->assign('error', 'helppay_link_invalid');

            return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/invalid.phtml');
        }

        $this->assign('page_title', (string) __('帮他人付款'));
        $this->assign('bill', $bill);
        $this->assign('help_rules_url', '/faq/help-pay-payer');
        $this->assign('privacy_url', '/faq/help-pay-privacy');

        return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/payer.phtml');
    }

    private function orchestrator(): HelpPayOrchestrator
    {
        try {
            $links = ObjectManager::getInstance(PaymentLinkServiceInterface::class);
            if (!$links instanceof PaymentLinkServiceInterface) {
                $links = new PaymentLinkService();
            }
        } catch (\Throwable) {
            $links = new PaymentLinkService();
        }

        return new HelpPayOrchestrator($links);
    }
}
