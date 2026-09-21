<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * PayPal JS SDK URL 构建（Payment 模块独占）。
 * 按 google_pay_enabled / apple_pay_enabled 写入 enable-funding / disable-funding。
 */
final class PayPalJsSdkUrlBuilder
{
    public const SDK_BASE = 'https://www.paypal.com/sdk/js';

    /**
     * @param array<string, mixed> $config runtime config（含 client_id / environment / 两开关）
     * @param array<string, mixed> $context currency / intent / components 等
     */
    public function build(array $config, array $context = []): string
    {
        $clientId = $this->resolveClientId($config);
        if ($clientId === '') {
            return '';
        }

        $googleOn = $this->isEnabledFlag($config['google_pay_enabled'] ?? true);
        $appleOn = $this->isEnabledFlag($config['apple_pay_enabled'] ?? true);

        $enable = [];
        $disable = [];
        if ($googleOn) {
            $enable[] = 'googlepay';
        } else {
            $disable[] = 'googlepay';
        }
        if ($appleOn) {
            $enable[] = 'applepay';
        } else {
            $disable[] = 'applepay';
        }

        $currency = strtoupper(trim((string) ($context['currency'] ?? $config['default_currency'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }
        $intent = strtolower(trim((string) ($context['intent'] ?? 'capture')));
        if ($intent === '') {
            $intent = 'capture';
        }
        $components = trim((string) ($context['components'] ?? 'buttons,googlepay,applepay'));
        if ($components === '') {
            $components = 'buttons,googlepay,applepay';
        }

        $query = [
            'client-id' => $clientId,
            'currency' => $currency,
            'intent' => $intent,
            'components' => $components,
        ];
        if ($enable !== []) {
            $query['enable-funding'] = implode(',', $enable);
        }
        if ($disable !== []) {
            $query['disable-funding'] = implode(',', $disable);
        }

        return self::SDK_BASE . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{google_pay_enabled:bool,apple_pay_enabled:bool,js_sdk_src:string,client_id:string}
     */
    public function walletMeta(array $config, array $context = []): array
    {
        $googleOn = $this->isEnabledFlag($config['google_pay_enabled'] ?? true);
        $appleOn = $this->isEnabledFlag($config['apple_pay_enabled'] ?? true);
        $clientId = $this->resolveClientId($config);
        $src = ($googleOn || $appleOn) ? $this->build($config, $context) : '';
        // 两关时仍构建带 disable 的 URL，便于验收断言；无 client_id 则空串
        if ($src === '' && $clientId !== '') {
            $src = $this->build($config, $context);
        }

        return [
            'google_pay_enabled' => $googleOn,
            'apple_pay_enabled' => $appleOn,
            'js_sdk_src' => $src,
            'client_id' => $clientId,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveClientId(array $config): string
    {
        $direct = trim((string) ($config['client_id'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        $environment = strtolower(trim((string) ($config['environment'] ?? 'sandbox')));
        if ($environment === 'live') {
            return trim((string) ($config['live_client_id'] ?? ''));
        }

        return trim((string) ($config['sandbox_client_id'] ?? ''));
    }

    private function isEnabledFlag(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === '0' || $value === '' || $value === null) {
            return false;
        }
        if (\is_string($value) && strtolower(trim($value)) === 'false') {
            return false;
        }

        return true;
    }
}
