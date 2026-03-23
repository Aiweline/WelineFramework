<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * Scoped 2FA runtime flags.
 *
 * The live table stores one row per scope and exposes area-level booleans
 * instead of the legacy key/value layout this model previously expected.
 */
#[Table(comment: '2FA configuration table')]
#[Index(name: 'idx_scope', columns: ['scope'], type: 'UNIQUE')]
class TwoFactorConfig extends Model
{
    public const schema_table = 'two_factor_config';
    public const schema_primary_key = 'config_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Config ID')]
    public const schema_fields_ID = 'config_id';

    #[Col('varchar', 100, nullable: false, default: 'default', comment: 'Config scope')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Customer 2FA enabled')]
    public const schema_fields_CUSTOMER_ENABLED = 'customer_2fa_enabled';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Customer 2FA mandatory')]
    public const schema_fields_CUSTOMER_MANDATORY = 'customer_2fa_mandatory';

    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Admin 2FA enabled')]
    public const schema_fields_ADMIN_ENABLED = 'admin_2fa_enabled';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Admin 2FA mandatory')]
    public const schema_fields_ADMIN_MANDATORY = 'admin_2fa_mandatory';

    #[Col('text', comment: 'Admin whitelist JSON')]
    public const schema_fields_ADMIN_WHITELIST = 'admin_whitelist';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('datetime', comment: 'Legacy created time')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col('datetime', comment: 'Legacy updated time')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';
    public const area_CUSTOMER = 'customer';
    public const default_scope = 'default';

    public function getConfig(
        string $key,
        string $module = 'Weline_TwoFactorAuth',
        string $area = self::area_BACKEND,
        mixed $default = null
    ): mixed {
        $column = $this->resolveColumnForKey($key, $area);
        if ($column === null) {
            return $default;
        }

        $config = $this->findConfigRow($module, $area);
        if (!$config || !$config->getId()) {
            return $default;
        }

        $value = $config->getData($column);
        if ($value === null || $value === '') {
            return $default;
        }

        return $this->decodeStoredValue($column, $value);
    }

    public function setConfig(
        string $key,
        mixed $value,
        string $module = 'Weline_TwoFactorAuth',
        string $area = self::area_BACKEND
    ): bool {
        $column = $this->resolveColumnForKey($key, $area);
        if ($column === null) {
            return false;
        }

        $scope = $this->resolveWritableScope($module, $area);
        $config = $this->findConfigByScope($scope);

        if (!$config || !$config->getId()) {
            $config = clone $this;
            $config->clearData();
            $config->setData(self::schema_fields_SCOPE, $scope);
            $this->seedDefaultValues($config);
        }

        $config->setData($column, $this->encodeStoredValue($column, $value));
        return (bool) $config->save();
    }

    public function deleteConfig(
        string $key,
        string $module = 'Weline_TwoFactorAuth',
        string $area = self::area_BACKEND
    ): bool {
        $column = $this->resolveColumnForKey($key, $area);
        if ($column === null) {
            return false;
        }

        $config = $this->findConfigRow($module, $area);
        if (!$config || !$config->getId()) {
            return false;
        }

        $config->setData($column, $this->getDefaultValueForColumn($column));
        return (bool) $config->save();
    }

    private function resolveColumnForKey(string $key, string $area): ?string
    {
        $normalizedKey = strtolower(trim($key));
        $normalizedArea = strtolower(trim($area));
        $isBackendArea = \in_array($normalizedArea, [self::area_BACKEND, 'admin'], true);

        if (
            $normalizedKey === self::schema_fields_ADMIN_WHITELIST
            || $normalizedKey === 'admin.whitelist'
            || $normalizedKey === 'whitelist'
        ) {
            return self::schema_fields_ADMIN_WHITELIST;
        }

        $enabledKeys = ['enabled', '2fa.enabled'];
        $mandatoryKeys = ['mandatory', '2fa.mandatory'];
        if (\str_ends_with($normalizedKey, '.enabled') || \in_array($normalizedKey, $enabledKeys, true)) {
            return $isBackendArea ? self::schema_fields_ADMIN_ENABLED : self::schema_fields_CUSTOMER_ENABLED;
        }

        if (\str_ends_with($normalizedKey, '.mandatory') || \in_array($normalizedKey, $mandatoryKeys, true)) {
            return $isBackendArea ? self::schema_fields_ADMIN_MANDATORY : self::schema_fields_CUSTOMER_MANDATORY;
        }

        return match ($normalizedKey) {
            self::schema_fields_ADMIN_ENABLED => self::schema_fields_ADMIN_ENABLED,
            self::schema_fields_ADMIN_MANDATORY => self::schema_fields_ADMIN_MANDATORY,
            self::schema_fields_CUSTOMER_ENABLED => self::schema_fields_CUSTOMER_ENABLED,
            self::schema_fields_CUSTOMER_MANDATORY => self::schema_fields_CUSTOMER_MANDATORY,
            default => null,
        };
    }

    private function findConfigRow(string $module, string $area): ?self
    {
        $scopeCandidates = [];

        $module = trim($module);
        if ($module !== '') {
            $scopeCandidates[] = $module;
        }

        $area = trim($area);
        if ($area !== '') {
            $scopeCandidates[] = $area;
        }

        $scopeCandidates[] = self::default_scope;
        $scopeCandidates = array_values(array_unique($scopeCandidates));

        foreach ($scopeCandidates as $scope) {
            $config = $this->findConfigByScope($scope);
            if ($config && $config->getId()) {
                return $config;
            }
        }

        return null;
    }

    private function findConfigByScope(string $scope): ?self
    {
        $config = clone $this;
        $config = $config->clear()
            ->where(self::schema_fields_SCOPE, $scope)
            ->find()
            ->fetch();

        return $config instanceof self && $config->getId() ? $config : null;
    }

    private function resolveWritableScope(string $module, string $area): string
    {
        $existing = $this->findConfigRow($module, $area);
        if ($existing && $existing->getId()) {
            return (string) ($existing->getData(self::schema_fields_SCOPE) ?: self::default_scope);
        }

        return self::default_scope;
    }

    private function seedDefaultValues(self $config): void
    {
        $config->setData(self::schema_fields_CUSTOMER_ENABLED, $this->getDefaultValueForColumn(self::schema_fields_CUSTOMER_ENABLED));
        $config->setData(self::schema_fields_CUSTOMER_MANDATORY, $this->getDefaultValueForColumn(self::schema_fields_CUSTOMER_MANDATORY));
        $config->setData(self::schema_fields_ADMIN_ENABLED, $this->getDefaultValueForColumn(self::schema_fields_ADMIN_ENABLED));
        $config->setData(self::schema_fields_ADMIN_MANDATORY, $this->getDefaultValueForColumn(self::schema_fields_ADMIN_MANDATORY));
        $config->setData(self::schema_fields_ADMIN_WHITELIST, $this->getDefaultValueForColumn(self::schema_fields_ADMIN_WHITELIST));
    }

    private function decodeStoredValue(string $column, mixed $value): mixed
    {
        if ($column === self::schema_fields_ADMIN_WHITELIST) {
            if (\is_array($value)) {
                return $value;
            }

            $decoded = \json_decode((string) $value, true);
            return \is_array($decoded) ? $decoded : [];
        }

        if (\in_array(
            $column,
            [
                self::schema_fields_ADMIN_ENABLED,
                self::schema_fields_ADMIN_MANDATORY,
                self::schema_fields_CUSTOMER_ENABLED,
                self::schema_fields_CUSTOMER_MANDATORY,
            ],
            true
        )) {
            return (bool) $value;
        }

        return $value;
    }

    private function encodeStoredValue(string $column, mixed $value): mixed
    {
        if ($column === self::schema_fields_ADMIN_WHITELIST) {
            if (\is_string($value)) {
                $decoded = \json_decode($value, true);
                if (\is_array($decoded)) {
                    return \json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $whitelist = \is_array($value) ? array_values($value) : [];
            return \json_encode($whitelist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function getDefaultValueForColumn(string $column): mixed
    {
        return match ($column) {
            self::schema_fields_ADMIN_ENABLED,
            self::schema_fields_ADMIN_MANDATORY,
            self::schema_fields_CUSTOMER_ENABLED,
            self::schema_fields_CUSTOMER_MANDATORY => 0,
            self::schema_fields_ADMIN_WHITELIST => '[]',
            default => null,
        };
    }
}
