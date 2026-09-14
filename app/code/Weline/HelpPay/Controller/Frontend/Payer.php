<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
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
        $this->assign('billing_address_html', $this->renderBillingAddressWidget());
        $this->assign('token', $token);

        return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/payer.phtml');
    }

    /**
     * Checkout-equivalent address widget for payer billing (session-isolated; never writes HelpPay shipping).
     */
    private function renderBillingAddressWidget(): string
    {
        $templatePath = BP . '/app/code/Weline/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml';
        if (!is_file($templatePath)) {
            return '';
        }

        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $inner = trim((string) $template->fetch(
                'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml',
                [
                    'title' => (string) __('付款账单地址'),
                    'saved_heading' => (string) __('选择账单地址'),
                    'hide_billing_same' => true,
                ]
            ));
        } catch (\Throwable) {
            return '';
        }

        if ($inner === '') {
            return '';
        }

        return '<form data-helppay-billing-host data-testid="helppay-billing-host" data-session-isolation="1"'
            . ' action="javascript:void(0)" method="post" onsubmit="return false;">'
            . $inner
            . '</form>';
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
