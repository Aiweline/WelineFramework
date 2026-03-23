<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

class PaymentManagementService
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getManagementData(): array
    {
        $methods = $this->paymentService->getManagementPaymentMethods();
        $enabledMethods = array_values(array_filter($methods, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)));
        $defaultMethod = null;
        foreach ($methods as $method) {
            if ((bool) ($method['is_default'] ?? false)) {
                $defaultMethod = $method;
                break;
            }
        }

        return [
            'methods' => $methods,
            'stats' => [
                'total_methods' => count($methods),
                'enabled_methods' => count($enabledMethods),
                'reserved_methods' => count($methods) - count($enabledMethods),
                'default_method_code' => (string) ($defaultMethod['code'] ?? ''),
                'default_method_title' => (string) ($defaultMethod['title'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $methods = $this->paymentService->getManagementPaymentMethods();
        $inputMethods = \is_array($payload['methods'] ?? null) ? $payload['methods'] : [];
        $defaultMethod = (string) ($payload['default_method'] ?? '');
        $normalized = [];

        foreach ($methods as $method) {
            $code = (string) ($method['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $methodInput = \is_array($inputMethods[$code] ?? null) ? $inputMethods[$code] : [];
            $normalized[$code] = [
                'enabled' => $this->toBool($methodInput['enabled'] ?? $method['enabled'] ?? false),
                'sort_order' => (int) ($methodInput['sort_order'] ?? $method['sort_order'] ?? 0),
                'is_default' => false,
                'config' => $this->normalizeConfig(
                    \is_array($methodInput['config'] ?? null) ? $methodInput['config'] : [],
                    \is_array($method['config_fields'] ?? null) ? $method['config_fields'] : [],
                    \is_array($method['config'] ?? null) ? $method['config'] : []
                ),
            ];
        }

        $enabledCodes = array_keys(array_filter($normalized, static fn(array $method): bool => (bool) ($method['enabled'] ?? false)));
        if ($defaultMethod === '' || !\in_array($defaultMethod, $enabledCodes, true)) {
            $defaultMethod = $enabledCodes[0] ?? '';
        }
        if ($defaultMethod !== '' && isset($normalized[$defaultMethod])) {
            $normalized[$defaultMethod]['is_default'] = true;
        }

        $this->persistMethodConfig($normalized);

        return [
            'saved_method_count' => count($normalized),
            'default_method' => $defaultMethod,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    protected function normalizeConfig(array $input, array $fields, array $defaults): array
    {
        $config = $defaults;
        foreach ($fields as $field) {
            if (!\is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $value = $input[$key] ?? ($defaults[$key] ?? '');
            $config[$key] = match ($type) {
                'checkbox' => $this->toBool($value),
                'number' => is_numeric($value) ? (string) $value : '0',
                default => trim((string) $value),
            };
        }

        return $config;
    }

    protected function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    protected function persistMethodConfig(array $config): void
    {
        Env::getInstance()->setConfig('payment.methods', $config);
    }
}
