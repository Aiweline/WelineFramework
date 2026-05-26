<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Interface\PaymentConfigTesterInterface;
use Weline\Payment\Model\PaymentMethodConfig;

class PaymentConfigValidationService
{
    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array{success: bool, status: string, message: string, missing_config: array<int, string>, details: array<string, mixed>, tested_at: string}
     */
    public function validateMethod(array $method, array $config, ?object $provider = null, array $context = []): array
    {
        $testedAt = date('Y-m-d H:i:s');
        $resolvedConfig = $this->resolveEnvironmentConfig($config, (string) ($context['environment'] ?? $config['environment'] ?? 'sandbox'));
        $missing = $this->getMissingRequiredConfig($method, $resolvedConfig);

        if ($missing !== []) {
            return $this->result(false, 'Required payment configuration is missing: ' . implode(', ', $missing), $missing, [
                'environment' => (string) ($resolvedConfig['environment'] ?? 'sandbox'),
            ], $testedAt);
        }

        if (!empty($context['require_documentation']) && array_key_exists('has_documentation', $method) && empty($method['has_documentation'])) {
            return $this->result(false, 'Configuration documentation is missing or incomplete.', [], [], $testedAt);
        }

        if ($provider instanceof PaymentConfigTesterInterface) {
            try {
                $testResult = $provider->testConfig($resolvedConfig, array_merge($context, [
                    'config' => $resolvedConfig,
                    'payment_method' => $method,
                ]));
            } catch (\Throwable $throwable) {
                return $this->result(false, $throwable->getMessage(), [], [
                    'exception' => $throwable::class,
                ], $testedAt);
            }

            return $this->result(
                !empty($testResult['success']),
                (string) ($testResult['message'] ?? (!empty($testResult['success']) ? 'Configuration test passed.' : 'Configuration test failed.')),
                [],
                \is_array($testResult['details'] ?? null) ? $testResult['details'] : [],
                $testedAt
            );
        }

        if ($this->requiresRemoteTest($method, $context)) {
            return $this->result(false, 'This payment provider does not expose a configuration test adapter yet, so it cannot be enabled.', [], [
                'provider' => (string) ($method['provider'] ?? ''),
            ], $testedAt);
        }

        return $this->result(true, 'Static configuration validation passed.', [], [
            'test_type' => 'static',
        ], $testedAt);
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function getMissingRequiredConfig(array $method, array $config): array
    {
        $required = [];
        foreach ((array) ($method['required_config'] ?? []) as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $required[] = $key;
            }
        }

        foreach ((array) ($method['config_fields'] ?? []) as $fieldKey => $field) {
            if (!\is_array($field) || empty($field['required'])) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? (\is_string($fieldKey) ? $fieldKey : '')));
            if ($key !== '') {
                $required[] = $key;
            }
        }

        $missing = [];
        foreach (array_values(array_unique($required)) as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function resolveEnvironmentConfig(array $config, string $environment): array
    {
        $environment = strtolower(trim($environment)) === 'live' ? 'live' : 'sandbox';
        foreach ($config as $key => $value) {
            if (!\is_string($key) || !str_starts_with($key, $environment . '_')) {
                continue;
            }

            $baseKey = substr($key, strlen($environment) + 1);
            if ($baseKey === '') {
                continue;
            }
            if (trim((string) $value) !== '' || !array_key_exists($baseKey, $config)) {
                $config[$baseKey] = $value;
            }
        }

        $config['environment'] = $environment;
        $config['sandbox'] = $environment !== 'live';

        return $config;
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $context
     */
    private function requiresRemoteTest(array $method, array $context): bool
    {
        if (array_key_exists('requires_remote_test', $context)) {
            return !empty($context['requires_remote_test']);
        }
        if (array_key_exists('requires_remote_test', $method)) {
            return !empty($method['requires_remote_test']);
        }

        $flow = strtolower((string) ($method['flow'] ?? ''));
        $type = strtolower((string) ($method['method_type'] ?? ''));

        return !\in_array($flow, ['offline', 'event'], true)
            && !\in_array($type, ['manual', 'offline', 'bank_transfer', 'credit'], true);
    }

    /**
     * @param array<int, string> $missing
     * @param array<string, mixed> $details
     * @return array{success: bool, status: string, message: string, missing_config: array<int, string>, details: array<string, mixed>, tested_at: string}
     */
    private function result(bool $success, string $message, array $missing, array $details, string $testedAt): array
    {
        return [
            'success' => $success,
            'status' => $success ? PaymentMethodConfig::TEST_STATUS_PASSED : PaymentMethodConfig::TEST_STATUS_FAILED,
            'message' => $message,
            'missing_config' => $missing,
            'details' => $details,
            'tested_at' => $testedAt,
        ];
    }
}
