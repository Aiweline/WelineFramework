<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Scoped payment method configuration')]
#[Index(name: 'uniq_payment_method_scope_env', columns: ['method_code', 'scope', 'environment'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_scope_enabled', columns: ['scope', 'enabled'])]
#[Index(name: 'idx_payment_scope_test_status', columns: ['method_code', 'test_status'])]
class PaymentMethodConfig extends Model
{
    public const schema_table = 'weline_payment_method_config';
    public const schema_primary_key = 'config_id';

    public const TEST_STATUS_UNTESTED = 'untested';
    public const TEST_STATUS_PASSED = 'passed';
    public const TEST_STATUS_FAILED = 'failed';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Config ID')]
    public const schema_fields_ID = 'config_id';
    #[Col('varchar', 96, nullable: false, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 191, nullable: false, default: 'default.default.default', comment: 'SystemConfig-compatible payment config scope')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('varchar', 16, nullable: false, default: 'sandbox', comment: 'sandbox or live')]
    public const schema_fields_ENVIRONMENT = 'environment';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Enabled for this scope')]
    public const schema_fields_ENABLED = 'enabled';
    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Default method for this scope')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('text', nullable: true, comment: 'Configuration JSON')]
    public const schema_fields_CONFIG_JSON = 'config_json';
    #[Col('varchar', 16, nullable: false, default: 'untested', comment: 'Config test status')]
    public const schema_fields_TEST_STATUS = 'test_status';
    #[Col('text', nullable: true, comment: 'Config test message')]
    public const schema_fields_TEST_MESSAGE = 'test_message';
    #[Col('datetime', nullable: true, comment: 'Last tested at')]
    public const schema_fields_TESTED_AT = 'tested_at';
    #[Col('varchar', 96, nullable: true, comment: 'Config release code')]
    public const schema_fields_CONFIG_RELEASE_CODE = 'config_release_code';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['config_id'];
    public array $_index_sort_keys = ['method_code', 'scope', 'environment', 'enabled', 'test_status'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function isEnabled(): bool
    {
        return (int) $this->getData(self::schema_fields_ENABLED) === 1;
    }

    public function isTestPassed(): bool
    {
        return (string) $this->getData(self::schema_fields_TEST_STATUS) === self::TEST_STATUS_PASSED;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigData(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG_JSON);
        if (\is_array($config)) {
            return $config;
        }
        if (!\is_string($config) || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfigData(array $config): static
    {
        return $this->setData(self::schema_fields_CONFIG_JSON, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function loadByScope(string $methodCode, string $scope, string $environment): self
    {
        $this->reset()
            ->where(self::schema_fields_METHOD_CODE, $methodCode)
            ->where(self::schema_fields_SCOPE, $scope)
            ->where(self::schema_fields_ENVIRONMENT, $environment)
            ->find()
            ->fetch();

        return $this;
    }
}
