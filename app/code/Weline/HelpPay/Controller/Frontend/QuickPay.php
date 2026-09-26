<?php

declare(strict_types=1);

namespace Weline\HelpPay\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\PaymentLinkServiceInterface;
use Weline\Payment\Service\PaymentLinkService;
use Weline\Theme\Model\ThemeLayout;

final class QuickPay extends FrontendController
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
        try {
            $links = ObjectManager::getInstance(PaymentLinkServiceInterface::class);
            if (!$links instanceof PaymentLinkServiceInterface) {
                $links = new PaymentLinkService();
            }
        } catch (\Throwable) {
            $links = new PaymentLinkService();
        }

        $row = $links->resolve($token, PaymentLinkServiceInterface::KIND_QUICK_PAY);
        if ($row === null) {
            $expired = $this->isExpiredLink($links, $token);
            $this->assign('page_title', (string) ($expired ? __('快捷购买链接已过期') : __('快捷购买链接无效')));
            $this->assign('error', $expired ? 'quick_pay_expired' : 'quick_pay_invalid');
            $this->assign('invalid_heading', (string) ($expired ? __('链接已过期') : __('链接不可用')));
            $this->assign(
                'invalid_message',
                (string) ($expired
                    ? __('该快捷购买链接已过期。请回到商品页或订单重新生成链接。')
                    : __('该分享或代付链接已过期、撤销或无效。'))
            );

            return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/invalid.phtml');
        }

        // Self quick pay: owner may see own shipping (not help_pay redaction).
        $shipping = $links->resolveShippingForFulfillment($token, PaymentLinkServiceInterface::KIND_QUICK_PAY);
        $this->assign('page_title', (string) __('快捷购买'));
        $this->assign('bill', $row);
        $this->assign('shipping', $shipping);
        $this->assign('token', $token);
        $path = '/' . ltrim((string) ($row['path'] ?? ('q/' . $token)), '/');
        $this->assign('share_url', $path);
        $this->assign('share_title', (string) __('跨设备支付'));
        $this->assign('share_hint', (string) __('复制链接或二维码，在另一台设备上打开并完成支付。'));
        $this->assign('preferred_payment_method', $this->resolvePreferredQuickPaymentMethod($row, $shipping));

        return (string) $this->fetch('Weline_HelpPay::templates/frontend/pay/quick.phtml');
    }

    /**
     * 快捷购买默认支付方式：只取 Provider 列表（与万能结账同一目录）。
     * 列表为空或不可用时返回空串，由模板提示「暂无可用支付方式」，不得兜底编造 code。
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $shipping
     */
    private function resolvePreferredQuickPaymentMethod(array $row, ?array $shipping): string
    {
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? 'USD'))) ?: 'USD';
        $amountMinor = max(0, (int) ($row['amount_minor'] ?? 0));
        $country = '';
        if (is_array($shipping)) {
            $country = strtoupper(trim((string) ($shipping['country_code'] ?? $shipping['country'] ?? '')));
            if (strlen($country) > 2) {
                $country = strtoupper(trim((string) ($shipping['country_code'] ?? '')));
            }
        }
        try {
            /** @var \Weline\Checkout\Service\CheckoutPaymentMethodsProvider $provider */
            $provider = ObjectManager::getInstance(
                \Weline\Checkout\Service\CheckoutPaymentMethodsProvider::class
            );
            $params = [
                'currency' => $currency,
                'amount' => $amountMinor / 100.0,
                'amount_minor' => $amountMinor,
                'payable_type' => 'helppay',
            ];
            if ($country !== '' && strlen($country) === 2) {
                $params['country'] = $country;
                $params['country_code'] = $country;
            }
            $codes = [];
            foreach ($provider->listMethods($params) as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $code = strtolower(trim((string) ($method['code'] ?? '')));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            if (in_array('paypal', $codes, true)) {
                return 'paypal';
            }
            if ($codes !== []) {
                return $codes[0];
            }
        } catch (\Throwable) {
            // 列表不可用时按「无可用支付方式」处理，不编造兜底 code。
        }

        return '';
    }

    private function isExpiredLink(PaymentLinkServiceInterface $links, string $token): bool
    {
        try {
            $repo = ObjectManager::getInstance(\Weline\Payment\Service\PaymentLinkRecordRepository::class);
            if (!$repo instanceof \Weline\Payment\Service\PaymentLinkRecordRepository) {
                $repo = new \Weline\Payment\Service\PaymentLinkRecordRepository();
            }
        } catch (\Throwable) {
            $repo = new \Weline\Payment\Service\PaymentLinkRecordRepository();
        }
        $raw = $repo->findByToken(PaymentLinkServiceInterface::KIND_QUICK_PAY, $token);
        if (!is_array($raw)) {
            return false;
        }
        $status = (string) ($raw['status'] ?? '');

        return $status === PaymentLinkServiceInterface::STATUS_EXPIRED
            || ((int) ($raw['expires_at'] ?? 0) > 0 && (int) $raw['expires_at'] < time());
    }
}
