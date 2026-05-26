<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentMethodConfig;

class PaymentScopeConfigService
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_STORE = 'store';
    public const SCOPE_CHANNEL = 'channel';
    public const SCOPE_CUSTOM = 'custom';

    private const DEFAULT_SCOPE_CODE = 'default';
    private const DEFAULT_ENVIRONMENT = 'sandbox';

    public function __construct(
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array{scope_type: string, scope_code: string, environment: string, scope_key: string}
     */
    public function resolveScope(array $context = []): array
    {
        $scopeType = $this->normalizeScopeType((string) ($context['scope_type'] ?? $context['scope'] ?? ''));
        $scopeCode = $this->normalizeScopeCode((string) ($context['scope_code'] ?? $context['website_id'] ?? $context['store_id'] ?? ''), $scopeType);
        $environment = $this->normalizeEnvironment((string) ($context['environment'] ?? ''));

        return [
            'scope_type' => $scopeType,
            'scope_code' => $scopeCode,
            'environment' => $environment,
            'scope_key' => $this->buildScopeKey($scopeType, $scopeCode),
        ];
    }

    public function normalizeScopeType(string $scopeType): string
    {
        $scopeType = strtolower(trim($scopeType));
        if (!\in_array($scopeType, [
            self::SCOPE_GLOBAL,
            self::SCOPE_WEBSITE,
            self::SCOPE_STORE,
            self::SCOPE_CHANNEL,
            self::SCOPE_CUSTOM,
        ], true)) {
            return self::SCOPE_GLOBAL;
        }

        return $scopeType;
    }

    public function normalizeScopeCode(string $scopeCode, string $scopeType = self::SCOPE_GLOBAL): string
    {
        $scopeType = $this->normalizeScopeType($scopeType);
        $scopeCode = trim($scopeCode);
        if ($scopeType === self::SCOPE_GLOBAL || $scopeCode === '') {
            return self::DEFAULT_SCOPE_CODE;
        }

        return preg_replace('/[^A-Za-z0-9_.:-]/', '_', $scopeCode) ?: self::DEFAULT_SCOPE_CODE;
    }

    public function normalizeEnvironment(string $environment): string
    {
        return strtolower(trim($environment)) === 'live' ? 'live' : self::DEFAULT_ENVIRONMENT;
    }

    public function buildScopeKey(string $scopeType, string $scopeCode): string
    {
        $scopeType = $this->normalizeScopeType($scopeType);
        $scopeCode = $this->normalizeScopeCode($scopeCode, $scopeType);

        return $scopeType . ':' . $scopeCode;
    }

    public function getProfile(string $methodCode, string $scopeType, string $scopeCode, string $environment = self::DEFAULT_ENVIRONMENT): ?PaymentMethodConfig
    {
        $methodCode = trim($methodCode);
        if ($methodCode === '') {
            return null;
        }

        $profile = $this->newProfile();
        $profile->loadByScope(
            $methodCode,
            $this->normalizeScopeType($scopeType),
            $this->normalizeScopeCode($scopeCode, $scopeType),
            $this->normalizeEnvironment($environment)
        );

        return $profile->getId() ? $profile : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveProfile(string $methodCode, string $scopeType, string $scopeCode, string $environment, array $data): PaymentMethodConfig
    {
        $methodCode = trim($methodCode);
        if ($methodCode === '') {
            throw new \InvalidArgumentException((string) __('Payment method code is required.'));
        }

        $scopeType = $this->normalizeScopeType($scopeType);
        $scopeCode = $this->normalizeScopeCode($scopeCode, $scopeType);
        $environment = $this->normalizeEnvironment($environment);
        $profile = $this->getProfile($methodCode, $scopeType, $scopeCode, $environment) ?? $this->newProfile();
        $now = date('Y-m-d H:i:s');

        if (!$profile->getId()) {
            $profile->setData(PaymentMethodConfig::schema_fields_METHOD_CODE, $methodCode)
                ->setData(PaymentMethodConfig::schema_fields_SCOPE_TYPE, $scopeType)
                ->setData(PaymentMethodConfig::schema_fields_SCOPE_CODE, $scopeCode)
                ->setData(PaymentMethodConfig::schema_fields_ENVIRONMENT, $environment)
                ->setData(PaymentMethodConfig::schema_fields_CREATED_AT, $now)
                ->setData(PaymentMethodConfig::schema_fields_TEST_STATUS, PaymentMethodConfig::TEST_STATUS_UNTESTED);
        }

        $profile->setData(PaymentMethodConfig::schema_fields_ENABLED, !empty($data['enabled']) ? 1 : 0)
            ->setData(PaymentMethodConfig::schema_fields_IS_DEFAULT, !empty($data['is_default']) ? 1 : 0)
            ->setData(PaymentMethodConfig::schema_fields_SORT_ORDER, (int) ($data['sort_order'] ?? 0))
            ->setData(PaymentMethodConfig::schema_fields_UPDATED_AT, $now)
            ->setConfigData(\is_array($data['config'] ?? null) ? $data['config'] : []);

        if (isset($data['test_status'])) {
            $profile->setData(PaymentMethodConfig::schema_fields_TEST_STATUS, (string) $data['test_status'])
                ->setData(PaymentMethodConfig::schema_fields_TEST_MESSAGE, (string) ($data['test_message'] ?? ''))
                ->setData(PaymentMethodConfig::schema_fields_TESTED_AT, (string) ($data['tested_at'] ?? $now));
        }

        $profile->save();

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRuntimeOverride(PaymentMethodConfig $profile): array
    {
        return [
            'enabled' => $profile->isEnabled(),
            'is_default' => (int) $profile->getData(PaymentMethodConfig::schema_fields_IS_DEFAULT) === 1,
            'sort_order' => (int) $profile->getData(PaymentMethodConfig::schema_fields_SORT_ORDER),
            'environment' => (string) $profile->getData(PaymentMethodConfig::schema_fields_ENVIRONMENT),
            'config' => $profile->getConfigData(),
            'scope_type' => (string) $profile->getData(PaymentMethodConfig::schema_fields_SCOPE_TYPE),
            'scope_code' => (string) $profile->getData(PaymentMethodConfig::schema_fields_SCOPE_CODE),
            'config_test_status' => (string) $profile->getData(PaymentMethodConfig::schema_fields_TEST_STATUS),
            'config_test_message' => (string) $profile->getData(PaymentMethodConfig::schema_fields_TEST_MESSAGE),
            'config_tested_at' => (string) $profile->getData(PaymentMethodConfig::schema_fields_TESTED_AT),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRuntimeOverridesForScope(string $scopeType, string $scopeCode, string $environment = self::DEFAULT_ENVIRONMENT): array
    {
        $scopeType = $this->normalizeScopeType($scopeType);
        $scopeCode = $this->normalizeScopeCode($scopeCode, $scopeType);
        $environment = $this->normalizeEnvironment($environment);
        $collection = $this->newProfile()
            ->where(PaymentMethodConfig::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(PaymentMethodConfig::schema_fields_SCOPE_CODE, $scopeCode)
            ->where(PaymentMethodConfig::schema_fields_ENVIRONMENT, $environment)
            ->select()
            ->fetch();

        if (\is_object($collection) && method_exists($collection, 'getItems')) {
            $rows = $collection->getItems();
        } else {
            $rows = $collection;
        }

        if (!\is_array($rows)) {
            return [];
        }

        $overrides = [];
        foreach ($rows as $row) {
            if ($row instanceof PaymentMethodConfig) {
                $methodCode = (string) $row->getData(PaymentMethodConfig::schema_fields_METHOD_CODE);
                if ($methodCode !== '') {
                    $overrides[$methodCode] = $this->toRuntimeOverride($row);
                }
                continue;
            }

            if (\is_array($row)) {
                $methodCode = (string) ($row[PaymentMethodConfig::schema_fields_METHOD_CODE] ?? '');
                if ($methodCode === '') {
                    continue;
                }
                $config = json_decode((string) ($row[PaymentMethodConfig::schema_fields_CONFIG_JSON] ?? ''), true);
                $overrides[$methodCode] = [
                    'enabled' => !empty($row[PaymentMethodConfig::schema_fields_ENABLED]),
                    'is_default' => !empty($row[PaymentMethodConfig::schema_fields_IS_DEFAULT]),
                    'sort_order' => (int) ($row[PaymentMethodConfig::schema_fields_SORT_ORDER] ?? 0),
                    'environment' => (string) ($row[PaymentMethodConfig::schema_fields_ENVIRONMENT] ?? $environment),
                    'config' => \is_array($config) ? $config : [],
                    'scope_type' => (string) ($row[PaymentMethodConfig::schema_fields_SCOPE_TYPE] ?? $scopeType),
                    'scope_code' => (string) ($row[PaymentMethodConfig::schema_fields_SCOPE_CODE] ?? $scopeCode),
                    'config_test_status' => (string) ($row[PaymentMethodConfig::schema_fields_TEST_STATUS] ?? PaymentMethodConfig::TEST_STATUS_UNTESTED),
                    'config_test_message' => (string) ($row[PaymentMethodConfig::schema_fields_TEST_MESSAGE] ?? ''),
                    'config_tested_at' => (string) ($row[PaymentMethodConfig::schema_fields_TESTED_AT] ?? ''),
                ];
            }
        }

        return $overrides;
    }

    private function newProfile(): PaymentMethodConfig
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(PaymentMethodConfig::class);
    }
}
