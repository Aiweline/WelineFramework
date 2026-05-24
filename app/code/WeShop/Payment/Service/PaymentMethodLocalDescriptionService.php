<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Weline\Framework\App\State;

class PaymentMethodLocalDescriptionService
{
    /**
     * @var array<string, array<string, array{title: string, description: string, checkout_note: string}>>
     */
    private const DEFAULT_LOCAL_DESCRIPTIONS = [
        'b2b_credit_account' => [
            'zh_Hans_CN' => [
                'title' => '企业信用账户',
                'description' => '订单计入企业信用额度，并在到期日前按发票付款。',
                'checkout_note' => '仅适用于已开通有效信用额度的企业客户。',
            ],
            'en_US' => [
                'title' => 'B2B Credit Account',
                'description' => 'Charge the order to the business credit line and pay by invoice before the due date.',
                'checkout_note' => 'Only available to business customers with an active credit line.',
            ],
        ],
        'manual_transfer' => [
            'zh_Hans_CN' => [
                'title' => '银行转账',
                'description' => '下单后通过银行转账付款。',
                'checkout_note' => '请将订单金额转入配置的银行账户，并使用订单号作为付款备注。',
            ],
            'en_US' => [
                'title' => 'Manual Transfer',
                'description' => 'Pay by bank transfer after the order is created.',
                'checkout_note' => 'Please transfer the order amount to the configured bank account and use the order number as the payment reference.',
            ],
        ],
        'cash_on_delivery' => [
            'zh_Hans_CN' => [
                'title' => '货到付款',
                'description' => '配送送达时现金付款。',
                'checkout_note' => '配送送达时向客户收款。',
            ],
            'en_US' => [
                'title' => 'Cash on Delivery',
                'description' => 'Pay in cash when the shipment is delivered.',
                'checkout_note' => 'Collect the payment from the customer when the shipment is delivered.',
            ],
        ],
        'paypal' => [
            'zh_Hans_CN' => [
                'title' => 'PayPal',
                'description' => '使用 PayPal 账户在线支付。',
                'checkout_note' => '下单后将跳转到 PayPal 安全完成支付。',
            ],
            'en_US' => [
                'title' => 'PayPal',
                'description' => 'Pay online with your PayPal account.',
                'checkout_note' => 'You will be redirected to PayPal after placing the order to complete payment securely.',
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    public function localize(array $method, ?string $locale = null): array
    {
        $code = trim((string) ($method['code'] ?? ''));
        if ($code === '') {
            return $method;
        }

        $description = $this->resolveLocalDescription($code, $method, $this->normalizeLocale($locale));
        if ($description === []) {
            return $method;
        }

        if (trim((string) ($description['title'] ?? '')) !== '') {
            $method['title'] = (string) $description['title'];
            $method['name'] = (string) $description['title'];
        }
        if (trim((string) ($description['description'] ?? '')) !== '') {
            $method['description'] = (string) $description['description'];
        }
        if (trim((string) ($description['checkout_note'] ?? '')) !== '') {
            $method['checkout_note'] = (string) $description['checkout_note'];
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, string>
     */
    private function resolveLocalDescription(string $code, array $method, string $locale): array
    {
        $inline = $this->readInlineLocalDescription($method, $locale);
        if ($inline !== []) {
            return $inline;
        }

        return self::DEFAULT_LOCAL_DESCRIPTIONS[$code][$this->normalizeSupportedLocale($locale)] ?? [];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, string>
     */
    private function readInlineLocalDescription(array $method, string $locale): array
    {
        $descriptions = $method['local_descriptions'] ?? $method['local_description'] ?? [];
        if (!\is_array($descriptions)) {
            return [];
        }

        $row = $descriptions[$locale] ?? $descriptions[$this->normalizeSupportedLocale($locale)] ?? [];
        if (!\is_array($row)) {
            return [];
        }

        return array_filter([
            'title' => trim((string) ($row['title'] ?? $row['name'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'checkout_note' => trim((string) ($row['checkout_note'] ?? '')),
        ], static fn(string $value): bool => $value !== '');
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = trim((string) ($locale ?: State::getLangLocal()));

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    private function normalizeSupportedLocale(string $locale): string
    {
        if (isset(self::DEFAULT_LOCAL_DESCRIPTIONS['manual_transfer'][$locale])) {
            return $locale;
        }

        return str_starts_with($locale, 'zh') ? 'zh_Hans_CN' : 'en_US';
    }
}
