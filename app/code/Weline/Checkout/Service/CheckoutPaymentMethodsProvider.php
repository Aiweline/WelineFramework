<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 万能结账支付方式统一目录：店面结账 / 代付 / 其它结账面共用，禁止各面自扫 Provider 拼列表。
 *
 * 数据源：{@see w_query('payment', 'getCheckoutPaymentMethods')}（PaymentQueryProvider）。
 */
final class CheckoutPaymentMethodsProvider
{
    /** @var (callable(string):string)|null */
    private $urlBuilder;

    /**
     * @param (callable(string):string)|null $urlBuilder
     */
    public function __construct(?callable $urlBuilder = null)
    {
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param array<string, mixed> $params currency / amount / amount_minor / country_id …
     * @return list<array{
     *   code:string,
     *   label:string,
     *   title:string,
     *   description:string,
     *   icon_url:string,
     *   guide_url:string,
     *   has_guide:bool,
     *   requires_billing:bool,
     *   capabilities:array<string,mixed>,
     *   checkout_template_code:string,
     *   cod_fee_amount_minor:int,
     *   source:string,
     *   sort_order:int
     * }>
     */
    public function listMethods(array $params = []): array
    {
        try {
            if (!\function_exists('w_query')) {
                return [];
            }
            $result = w_query('payment', 'getCheckoutPaymentMethods', $params);
        } catch (\Throwable) {
            return [];
        }

        $methods = [];
        if (\is_array($result)) {
            $list = \is_array($result['data'] ?? null) ? $result['data'] : $result;
            if (\array_is_list($list) || (isset($list[0]) && \is_array($list[0]))) {
                $methods = $list;
            }
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }
            $code = strtolower(trim((string) ($method['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            if (\array_key_exists('enabled', $method) && !$method['enabled']) {
                continue;
            }
            $display = \is_array($method['display_metadata'] ?? null) ? $method['display_metadata'] : [];
            $iconUrl = trim((string) ($method['icon_url'] ?? $display['icon_url'] ?? $display['icon'] ?? ''));
            $guideUrl = trim((string) ($method['guide_url'] ?? ''));
            if ($guideUrl === '') {
                $guideRoute = trim((string) ($method['guide_route'] ?? ''));
                if ($guideRoute === '') {
                    $guideRoute = 'guide/payment/' . rawurlencode($code);
                }
                $guideUrl = $this->storefrontUrl($guideRoute);
            }

            $caps = \is_array($method['capabilities'] ?? null) ? $method['capabilities'] : [];
            $tpl = strtolower(trim((string) ($method['checkout_template_code'] ?? '')));
            $label = (string) ($method['label'] ?? $method['title'] ?? $method['name'] ?? $code);
            $wallet = \is_array($method['paypal_wallet'] ?? null) ? $method['paypal_wallet'] : null;

            $normalized[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'title' => (string) ($method['title'] ?? $method['label'] ?? $code),
                'description' => (string) ($method['description'] ?? ''),
                'icon_url' => $iconUrl,
                'guide_url' => $guideUrl,
                'has_guide' => \array_key_exists('has_guide', $method)
                    ? (bool) $method['has_guide']
                    : true,
                'requires_billing' => $this->methodRequiresBilling($code, $caps, $tpl),
                'capabilities' => $caps,
                'checkout_template_code' => $tpl,
                'paypal_wallet' => $wallet,
                'cod_fee_amount_minor' => $this->resolvePaymentCodFeeMinor(
                    $code,
                    $method,
                    (float) ($params['amount'] ?? 0),
                ),
                'source' => (string) ($method['source'] ?? 'Weline_Payment'),
                'sort_order' => (int) ($method['sort_order'] ?? 100),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 100)) <=> ((int) ($right['sort_order'] ?? 100))
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    public function methodRequiresBilling(string $code, array $capabilities = [], string $checkoutTemplateCode = ''): bool
    {
        if (\array_key_exists('requires_billing_address', $capabilities)) {
            return (bool) $capabilities['requires_billing_address'];
        }
        $hay = strtolower($code) . '|' . strtolower($checkoutTemplateCode);

        return str_contains($hay, 'card') || str_contains($hay, 'stripe');
    }

    /**
     * @param array<string, mixed> $method
     */
    private function resolvePaymentCodFeeMinor(string $code, array $method, float $amountMajor): int
    {
        $explicit = max(0, (int) ($method['cod_fee_amount_minor'] ?? $method['fee_amount_minor'] ?? 0));
        if ($explicit > 0) {
            return $explicit;
        }
        try {
            if (!class_exists(\Weline\Payment\Service\CodFeeCalculator::class)) {
                return 0;
            }
            /** @var \Weline\Payment\Service\CodFeeCalculator $calc */
            $calc = ObjectManager::getInstance(\Weline\Payment\Service\CodFeeCalculator::class);
            if (!$calc->isCodMethod($code)) {
                return 0;
            }
            $baseMinor = (int) round(max(0.0, $amountMajor) * 100);

            return $calc->forMethodCode($code, $baseMinor, 2);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function storefrontUrl(string $route): string
    {
        $route = ltrim(trim($route), '/');
        if ($route === '') {
            return '';
        }
        if ($this->urlBuilder !== null) {
            return (string) ($this->urlBuilder)($route);
        }
        /** @var \Weline\Framework\Http\Url $url */
        $url = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);

        return $url->getUrl($route);
    }
}
