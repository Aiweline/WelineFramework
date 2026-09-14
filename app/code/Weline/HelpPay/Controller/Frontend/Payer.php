<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller\Frontend;

use Weline\Checkout\Service\CheckoutHtmlRenderer;
use Weline\Checkout\Service\CheckoutPaymentMethodsProvider;
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

        $paymentMethods = $this->paymentMethodOptions($bill);
        $defaultIndex = 0;
        foreach ($paymentMethods as $i => $method) {
            if (empty($method['requires_billing'])) {
                $defaultIndex = $i;
                break;
            }
        }
        $defaultMethod = $paymentMethods[$defaultIndex] ?? null;
        $billingRequired = is_array($defaultMethod) && !empty($defaultMethod['requires_billing']);

        $this->assign('page_title', (string) __('帮他人付款'));
        $this->assign('bill', $bill);
        $this->assign('help_rules_url', '/faq/help-pay-payer');
        $this->assign('privacy_url', '/faq/help-pay-privacy');
        $this->assign('payment_methods', $paymentMethods);
        $this->assign('payment_methods_html', $this->renderPaymentMethodsHtml($paymentMethods, $defaultIndex));
        $this->assign('payment_method_default_index', $defaultIndex);
        $this->assign('billing_required_initially', $billingRequired);
        $this->assign('billing_address_html', $this->renderBillingAddressWidget());
        $this->assign('token', $token);

        return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/payer.phtml');
    }

    /**
     * 与万能结账同一目录：CheckoutPaymentMethodsProvider → payment.getCheckoutPaymentMethods。
     *
     * @param array<string, mixed> $bill
     * @return list<array<string, mixed>>
     */
    private function paymentMethodOptions(array $bill): array
    {
        $amountMinor = max(0, (int) ($bill['amount_minor'] ?? 0));
        $currency = trim((string) ($bill['currency_code'] ?? 'USD'));
        if ($currency === '') {
            $currency = 'USD';
        }

        try {
            /** @var CheckoutPaymentMethodsProvider $provider */
            $provider = ObjectManager::getInstance(CheckoutPaymentMethodsProvider::class);

            return $provider->listMethods([
                'currency' => $currency,
                'amount' => $amountMinor / 100.0,
                'amount_minor' => $amountMinor,
                'payable_type' => 'helppay',
            ]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $methods
     */
    private function renderPaymentMethodsHtml(array $methods, int $defaultIndex): string
    {
        try {
            /** @var CheckoutHtmlRenderer $renderer */
            $renderer = ObjectManager::getInstance(CheckoutHtmlRenderer::class);

            return $renderer->renderPaymentMethodOptions(
                $methods,
                'helppay_payment_method',
                (string) __('暂无可用支付方式。'),
                [
                    'selected_index' => $defaultIndex,
                    'radio_boolean_attrs' => ['data-helppay-payment-method'],
                    'testid_prefix' => 'help-pay-method-',
                ]
            );
        } catch (\Throwable) {
            return '';
        }
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

        // Reusable template output cache may ignore hide_billing_same; strip nested checkout billing-same block.
        $inner = (string) preg_replace(
            '#<div class="w-shipping-checkout-address__section w-shipping-checkout-address__section--billing"[^>]*>.*?</div>\s*(?=<link|</section>)#s',
            '',
            $inner,
            1
        );

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
