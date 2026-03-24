<?php

declare(strict_types=1);

namespace WeShop\Payment\Provider;

use WeShop\Order\Model\Order;

trait ProviderContextHelperTrait
{
    /**
     * @return array<string, mixed>
     */
    protected function readPaymentMethod(array $context): array
    {
        $method = $context['payment_method'] ?? $context['method'] ?? [];

        return \is_array($method) ? $method : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readConfig(array $context): array
    {
        if (\is_array($context['config'] ?? null)) {
            return $context['config'];
        }

        $method = $this->readPaymentMethod($context);

        return \is_array($method['config'] ?? null) ? $method['config'] : [];
    }

    protected function readConfigString(array $context, string $key, string $default = ''): string
    {
        $config = $this->readConfig($context);

        return trim((string) ($config[$key] ?? $default));
    }

    protected function readConfigBool(array $context, string $key, bool $default = false): bool
    {
        $config = $this->readConfig($context);
        $value = $config[$key] ?? $default;

        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<int, string> $keys
     */
    protected function requireConfigKeys(array $context, array $keys, string $providerName): void
    {
        $config = $this->readConfig($context);
        $missing = [];

        foreach ($keys as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException((string) __('%{1} is missing required configuration: %{2}', [
                $providerName,
                implode(', ', $missing),
            ]));
        }
    }

    protected function readOrderNumber(Order $order): string
    {
        if (method_exists($order, 'getIncrementId')) {
            $incrementId = (string) $order->getIncrementId();
            if ($incrementId !== '') {
                return $incrementId;
            }
        }

        if (defined(Order::class . '::schema_fields_increment_id')) {
            $incrementId = (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
            if ($incrementId !== '') {
                return $incrementId;
            }
        }

        return (string) $order->getId();
    }

    protected function readOrderAmount(Order $order, array $paymentData = []): string
    {
        $amount = $paymentData['amount'] ?? null;
        if ($amount === null) {
            $amount = $order->getData(Order::schema_fields_total) ?? 0;
        }

        return number_format((float) $amount, 2, '.', '');
    }

    protected function readOrderSubject(Order $order, array $paymentData = []): string
    {
        $subject = trim((string) ($paymentData['subject'] ?? ''));
        if ($subject !== '') {
            return $subject;
        }

        return (string) __('Order %{1}', [$this->readOrderNumber($order)]);
    }

    protected function generateNonce(int $length = 32): string
    {
        try {
            return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
        } catch (\Throwable) {
            return substr(str_replace('.', '', uniqid('pay', true)), 0, $length);
        }
    }

    protected function resolveNotifyUrl(string $paymentMethodCode, array $paymentData, array $context): string
    {
        $urls = \is_array($paymentData['urls'] ?? null) ? $paymentData['urls'] : [];
        $notifyUrl = trim((string) ($paymentData['notify_url'] ?? $urls['notify_url'] ?? $this->readConfigString($context, 'notify_url')));

        return $notifyUrl !== '' ? $notifyUrl : '/payment/callback?payment_method=' . rawurlencode($paymentMethodCode);
    }

    protected function resolveReturnUrl(string $paymentMethodCode, array $paymentData, array $context): string
    {
        $urls = \is_array($paymentData['urls'] ?? null) ? $paymentData['urls'] : [];
        $returnUrl = trim((string) ($paymentData['return_url'] ?? $urls['return_url'] ?? $this->readConfigString($context, 'return_url')));

        return $returnUrl !== '' ? $returnUrl : '/checkout/success?payment_method=' . rawurlencode($paymentMethodCode);
    }

    /**
     * @return array<string, string>
     */
    protected function parseXmlPayload(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || !function_exists('simplexml_load_string')) {
            return [];
        }

        $element = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($element === false) {
            return [];
        }

        $decoded = json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);

        return \is_array($decoded) ? array_map(static fn(mixed $value): string => (string) $value, $decoded) : [];
    }
}
