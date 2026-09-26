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

            $row = [
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
            $normalized[] = $row + $this->normalizeIncentiveFields($method);
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 100)) <=> ((int) ($right['sort_order'] ?? 100))
        );

        return $normalized;
    }

    /**
     * 按 code 取结账展示用支付方式（含名称/图标）；列表未命中时回落 Provider 元数据。
     *
     * @param array<string, mixed> $params
     * @return array{
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
     *   paypal_wallet:?array,
     *   cod_fee_amount_minor:int,
     *   source:string,
     *   sort_order:int
     * }|null
     */
    public function findMethod(string $code, array $params = []): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        // Known storefront chrome (fake_card/paypal/…)：跳过 getCheckoutPaymentMethods 全量列表，
        // 续付空车 adopt 不能被慢查询拖成裸 code 兜底。
        if ($this->staticMethodChrome($code) !== null) {
            return $this->buildFallbackMethod($code, $params);
        }
        foreach ($this->listMethods($params) as $row) {
            if (($row['code'] ?? '') === $code) {
                return $row;
            }
        }

        return $this->buildFallbackMethod($code, $params);
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function ensureMethodPresent(array $methods, string $code, array $params = []): array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return $methods;
        }
        foreach ($methods as $row) {
            if (is_array($row) && strtolower(trim((string)($row['code'] ?? ''))) === $code) {
                return $methods;
            }
        }
        $found = $this->findMethod($code, $params);
        if ($found === null) {
            return $methods;
        }
        array_unshift($methods, $found);

        return $methods;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
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
     *   paypal_wallet:?array,
     *   cod_fee_amount_minor:int,
     *   source:string,
     *   sort_order:int
     * }|null
     */
    private function buildFallbackMethod(string $code, array $params = []): ?array
    {
        $label = $code;
        $description = '';
        $iconUrl = '';
        $tpl = $code;
        $caps = [];

        try {
            if (class_exists(\Weline\Payment\Service\PaymentMethodManager::class)) {
                /** @var \Weline\Payment\Service\PaymentMethodManager $manager */
                $manager = ObjectManager::getInstance(\Weline\Payment\Service\PaymentMethodManager::class);
                $model = $manager->getMethodByCode($code);
                if ($model !== null) {
                    $provider = $manager->getProviderInstance($model);
                    if ($provider !== null) {
                        $display = $provider->getDisplayMetadata();
                        $label = trim((string)($display['title'] ?? $display['label'] ?? $label)) ?: $label;
                        $description = (string)($display['description'] ?? '');
                        $tpl = strtolower(trim((string)($display['checkout_template_code'] ?? $code))) ?: $code;
                        $caps = $provider->getCapabilities();
                        if (!is_array($caps)) {
                            $caps = [];
                        }
                        if (class_exists(\Weline\Payment\Service\PaymentMethodIconResolver::class)) {
                            /** @var \Weline\Payment\Service\PaymentMethodIconResolver $icons */
                            $icons = ObjectManager::getInstance(\Weline\Payment\Service\PaymentMethodIconResolver::class);
                            $iconUrl = $icons->toPublicUrl(
                                (string)($display['icon_url'] ?? $display['icon'] ?? '')
                            );
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // fall through to static chrome
        }

        if ($label === $code || $iconUrl === '') {
            $static = $this->staticMethodChrome($code);
            if ($static !== null) {
                if ($label === $code) {
                    $label = $static['label'];
                }
                if ($description === '') {
                    $description = $static['description'];
                }
                if ($iconUrl === '' && $static['icon_raw'] !== '') {
                    try {
                        /** @var \Weline\Payment\Service\PaymentMethodIconResolver $icons */
                        $icons = ObjectManager::getInstance(\Weline\Payment\Service\PaymentMethodIconResolver::class);
                        $iconUrl = $icons->toPublicUrl($static['icon_raw']);
                    } catch (\Throwable) {
                        $iconUrl = '';
                    }
                }
            } elseif ($label === $code && $iconUrl === '') {
                // Unknown code with no provider — still return a selectable stub (label=code).
            }
        }

        $guideUrl = $this->storefrontUrl('guide/payment/' . rawurlencode($code));

        return [
            'code' => $code,
            'label' => $label !== '' ? $label : $code,
            'title' => $label !== '' ? $label : $code,
            'description' => $description,
            'icon_url' => $iconUrl,
            'guide_url' => $guideUrl,
            'has_guide' => true,
            'requires_billing' => $this->methodRequiresBilling($code, $caps, $tpl),
            'capabilities' => $caps,
            'checkout_template_code' => $tpl,
            'paypal_wallet' => null,
            'cod_fee_amount_minor' => 0,
            'source' => 'Weline_Payment',
            'sort_order' => 0,
        ] + $this->normalizeIncentiveFields([]);
    }

    /**
     * 透传 Payment 列表扁字段（contracts：incentive_savings_minor 等）；未给出站则默认为 0/空。
     *
     * @param array<string, mixed> $method
     * @return array{
     *   incentive_savings_minor:int,
     *   incentive_display:string,
     *   incentive_available:bool,
     *   incentive_type?:string,
     *   incentive_percent?:float|int
     * }
     */
    private function normalizeIncentiveFields(array $method): array
    {
        $savings = max(0, (int) ($method['incentive_savings_minor'] ?? 0));
        $available = !empty($method['incentive_available']) && $savings > 0;
        $display = $available ? trim((string) ($method['incentive_display'] ?? '')) : '';
        $fields = [
            'incentive_savings_minor' => $available ? $savings : 0,
            'incentive_display' => $display,
            'incentive_available' => $available,
        ];
        $type = strtolower(trim((string) ($method['incentive_type'] ?? '')));
        if ($available && ($type === 'fixed_amount' || $type === 'percentage')) {
            $fields['incentive_type'] = $type;
        }
        if ($available && \array_key_exists('incentive_percent', $method) && is_numeric($method['incentive_percent'])) {
            $fields['incentive_percent'] = 0 + $method['incentive_percent'];
        }

        return $fields;
    }

    /**
     * @return array{label:string,description:string,icon_raw:string}|null
     */
    private function staticMethodChrome(string $code): ?array
    {
        // Return Chinese sources; callers/HtmlRenderer prefetch before __().
        return match ($code) {
            'fake_card' => [
                'label' => '本地测试支付',
                'description' => '仅用于本地开发验证，不会产生真实扣款。',
                'icon_raw' => 'Weline_Payment::img/payment/fake-card.svg',
            ],
            'paypal' => [
                'label' => 'PayPal',
                'description' => '',
                'icon_raw' => 'Weline_Payment::img/payment/paypal.svg',
            ],
            'stripe' => [
                'label' => 'Stripe',
                'description' => '',
                'icon_raw' => 'Weline_Payment::img/payment/stripe.svg',
            ],
            'cash_on_delivery', 'cod' => [
                'label' => '货到付款',
                'description' => '',
                'icon_raw' => '',
            ],
            default => null,
        };
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
