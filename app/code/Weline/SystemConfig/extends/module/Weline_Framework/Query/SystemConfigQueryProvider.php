<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;

class SystemConfigQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SystemConfig $systemConfig
    ) {
    }

    public function getProviderName(): string
    {
        return 'system_config';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getConfig' => $this->getConfig($params),
            'resolveConfig' => $this->resolveConfig($params),
            'getConfigs' => $this->getConfigs($params),
            'getFallbacks' => $this->getFallbacks($params),
            'setConfig' => $this->setConfig($params),
            'setScopedConfig' => $this->setScopedConfig($params),
            'deleteScopedConfig' => $this->deleteScopedConfig($params),
            'saveScopeConfig' => $this->saveScopeConfig($params),
            'rollbackScopeConfigVersion' => $this->rollbackScopeConfigVersion($params),
            'getConfigVersions' => $this->getConfigVersions($params),
            'getConfigVersionDetail' => $this->getConfigVersionDetail($params),
            default => throw new \InvalidArgumentException(
                (string)__('SystemConfig query provider does not support: %{1}', $operation)
            ),
        };
    }

    private function getConfig(array $params): mixed
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return $params['default'] ?? null;
        }

        return $this->systemConfig->getConfig(
            key: $key,
            module: $module,
            area: $area,
            default: $params['default'] ?? null,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null
        );
    }

    private function resolveConfig(array $params): array
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return ['found' => false, 'value' => $params['default'] ?? null, 'source' => null];
        }

        return $this->systemConfig->resolveConfig(
            key: $key,
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            default: $params['default'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfigs(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($module === '') {
            return [];
        }

        return $this->systemConfig->getConfigMapByModule(
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null
        );
    }

    private function getFallbacks(array $params): array
    {
        $scope = isset($params['scope']) ? (string)$params['scope'] : null;
        $locale = isset($params['locale']) ? (string)$params['locale'] : null;

        return [
            'scope' => $this->systemConfig->normalizeScope($scope),
            'locale' => $this->systemConfig->normalizeLocale($locale),
            'fallback_scopes' => $this->systemConfig->getFallbackScopes($scope),
        ];
    }

    private function setConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $value = (string)($params['value'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        return $this->systemConfig->setConfig($key, $value, $module, $area);
    }

    private function setScopedConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        return $this->systemConfig->setScopedConfig(
            key: $key,
            value: $params['value'] ?? null,
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $this->extractSaveOptions($params)
        );
    }

    private function deleteScopedConfig(array $params): bool
    {
        $key = (string)($params['key'] ?? '');
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);

        if ($key === '' || $module === '') {
            return false;
        }

        return $this->systemConfig->deleteScopedConfig(
            key: $key,
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $this->extractSaveOptions($params)
        );
    }

    private function saveScopeConfig(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        $values = is_array($params['values'] ?? null) ? $params['values'] : [];

        if ($module === '') {
            return ['success' => false, 'status' => 'invalid_module'];
        }

        return $this->systemConfig->saveScopeConfig(
            module: $module,
            area: $area,
            values: $values,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $this->extractSaveOptions($params)
        );
    }

    private function rollbackScopeConfigVersion(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['success' => false, 'status' => 'invalid_version'];
        }

        return $this->systemConfig->rollbackScopeConfigVersion($versionId, $this->extractSaveOptions($params));
    }

    private function getConfigVersions(array $params): array
    {
        $module = (string)($params['module'] ?? '');
        $area = (string)($params['area'] ?? SystemConfig::area_BACKEND);
        if ($module === '') {
            return [];
        }

        return $this->systemConfig->getConfigVersions(
            module: $module,
            area: $area,
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            limit: (int)($params['limit'] ?? 50)
        );
    }

    private function getConfigVersionDetail(array $params): ?array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return null;
        }

        $detail = $this->systemConfig->getConfigVersionDetail($versionId);
        if ($detail === null) {
            return null;
        }

        $changes = is_array($detail['changes'] ?? null) ? $detail['changes'] : [];
        foreach ($changes as $index => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (is_array($change['old_row'] ?? null)) {
                $changes[$index]['old_row'] = $this->systemConfig->maskSensitiveRow($change['old_row']);
            }
            if (is_array($change['new_row'] ?? null)) {
                $changes[$index]['new_row'] = $this->systemConfig->maskSensitiveRow($change['new_row']);
            }
        }
        $detail['changes'] = $changes;

        return $detail;
    }

    private function extractSaveOptions(array $params): array
    {
        $allowed = [
            'inherit_keys',
            'base_versions',
            'actor_id',
            'actor_name',
            'reason',
            'metadata',
            'field_metadata',
            'value_type',
            'is_sensitive',
            'operation',
            'parent_version_id',
        ];
        $options = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $options[$key] = $params[$key];
            }
        }

        return $options;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'system_config',
            'name' => __('System config query'),
            'description' => __('Provides scope-aware system config read, write, version, and rollback operations.'),
            'module' => 'Weline_SystemConfig',
            'operations' => [
                [
                    'name' => 'getConfig',
                    'description' => __('Get a resolved config value.'),
                    'params' => $this->commonReadParams(true),
                ],
                [
                    'name' => 'resolveConfig',
                    'description' => __('Get a resolved config value with source and fallback metadata.'),
                    'params' => $this->commonReadParams(true),
                ],
                [
                    'name' => 'getConfigs',
                    'description' => __('Get resolved config values for a module.'),
                    'params' => $this->commonModuleParams(),
                ],
                [
                    'name' => 'getFallbacks',
                    'description' => __('Preview normalized scope and fallback scopes.'),
                    'params' => [
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'setConfig',
                    'description' => __('Set a global compatibility config value.'),
                    'params' => [
                        ['name' => 'key', 'type' => 'string', 'required' => true],
                        ['name' => 'value', 'type' => 'string', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'setScopedConfig',
                    'description' => __('Set one scoped config value and create a version batch.'),
                    'params' => $this->commonWriteParams(),
                ],
                [
                    'name' => 'deleteScopedConfig',
                    'description' => __('Delete one scoped value so resolution inherits from fallback scopes.'),
                    'params' => $this->commonWriteParams(false),
                ],
                [
                    'name' => 'saveScopeConfig',
                    'description' => __('Save a scoped config batch and return its version id.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'values', 'type' => 'object', 'required' => true],
                        ['name' => 'inherit_keys', 'type' => 'array', 'required' => false],
                        ['name' => 'base_versions', 'type' => 'object', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                    ]),
                ],
                [
                    'name' => 'rollbackScopeConfigVersion',
                    'description' => __('Rollback a scoped config version batch.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'actor_id', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_name', 'type' => 'string', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getConfigVersions',
                    'description' => __('List scoped config version batches.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ]),
                ],
                [
                    'name' => 'getConfigVersionDetail',
                    'description' => __('Get one scoped config version detail with sensitive values masked.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    private function commonReadParams(bool $includeKey = false): array
    {
        $params = $this->commonModuleParams();
        if ($includeKey) {
            array_unshift($params, ['name' => 'key', 'type' => 'string', 'required' => true]);
        }
        $params[] = ['name' => 'default', 'type' => 'mixed', 'required' => false];

        return $params;
    }

    private function commonModuleParams(): array
    {
        return [
            ['name' => 'module', 'type' => 'string', 'required' => true],
            ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('backend|frontend')],
            ['name' => 'scope', 'type' => 'string', 'required' => false],
            ['name' => 'locale', 'type' => 'string', 'required' => false],
        ];
    }

    private function commonWriteParams(bool $includeValue = true): array
    {
        $params = [
            ['name' => 'key', 'type' => 'string', 'required' => true],
            ['name' => 'module', 'type' => 'string', 'required' => true],
            ['name' => 'area', 'type' => 'string', 'required' => false],
            ['name' => 'scope', 'type' => 'string', 'required' => false],
            ['name' => 'locale', 'type' => 'string', 'required' => false],
            ['name' => 'base_versions', 'type' => 'object', 'required' => false],
            ['name' => 'reason', 'type' => 'string', 'required' => false],
        ];
        if ($includeValue) {
            array_splice($params, 1, 0, [['name' => 'value', 'type' => 'mixed', 'required' => true]]);
        }

        return $params;
    }
}
