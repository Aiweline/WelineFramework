<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

class SystemConfigQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SystemConfigTemplateService $templateService,
        private readonly SystemConfigCenterService $configCenterService
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
            'getTemplates' => $this->getTemplates($params),
            'getTemplateMeta' => $this->getTemplateMeta($params),
            'getModules' => $this->getModules($params),
            'getTree' => $this->getTree($params),
            'saveTemplateConfig' => $this->saveTemplateConfig($params),
            'precheckTemplateConfigRollback' => $this->precheckTemplateConfigRollback($params),
            'rollbackTemplateConfigVersion' => $this->rollbackTemplateConfigVersion($params),
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
        $returnType = strtolower(trim((string)($params['return_type'] ?? 'value')));

        if ($key === '' || $module === '') {
            return in_array($returnType, ['field', 'array'], true) ? [] : ($params['default'] ?? null);
        }

        if (in_array($returnType, ['field', 'array'], true)) {
            return $this->configCenterService->getFieldObject(
                module: $module,
                area: $area,
                key: $key,
                code: isset($params['code']) ? (string)$params['code'] : null,
                scope: isset($params['scope']) ? (string)$params['scope'] : null,
                locale: isset($params['locale']) ? (string)$params['locale'] : null,
                default: $params['default'] ?? null
            );
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
        $returnType = strtolower(trim((string)($params['return_type'] ?? 'map')));

        if ($module === '') {
            return [];
        }

        if ($returnType === 'fields') {
            return $this->configCenterService->getFieldObjects(
                module: $module,
                area: $area,
                code: isset($params['code']) ? (string)$params['code'] : null,
                scope: isset($params['scope']) ? (string)$params['scope'] : null,
                locale: isset($params['locale']) ? (string)$params['locale'] : null
            );
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTemplates(array $params): array
    {
        return $this->templateService->getTemplates(
            module: isset($params['module']) ? (string)$params['module'] : null,
            area: isset($params['area']) ? (string)$params['area'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    private function getTemplateMeta(array $params): ?array
    {
        return $this->templateService->getTemplateMeta(
            module: (string)($params['module'] ?? ''),
            area: (string)($params['area'] ?? SystemConfig::area_BACKEND),
            code: (string)($params['code'] ?? ''),
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getModules(array $params): array
    {
        return $this->templateService->getModules(
            area: isset($params['area']) ? (string)$params['area'] : null,
            search: isset($params['search']) ? (string)$params['search'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );
    }

    private function getTree(array $params): array
    {
        $tree = $this->templateService->getTree(
            module: isset($params['module']) ? (string)$params['module'] : null,
            area: isset($params['area']) ? (string)$params['area'] : null,
            search: isset($params['search']) ? (string)$params['search'] : null,
            forceReload: (bool)($params['force_reload'] ?? false)
        );

        if (!empty($params['with_values'])) {
            return $this->configCenterService->enrichTreeWithValues(
                $tree,
                isset($params['scope']) ? (string)$params['scope'] : null,
                isset($params['locale']) ? (string)$params['locale'] : null
            );
        }

        return $tree;
    }

    private function saveTemplateConfig(array $params): array
    {
        return $this->configCenterService->saveTemplateConfig(
            module: (string)($params['module'] ?? ''),
            area: (string)($params['area'] ?? SystemConfig::area_BACKEND),
            code: (string)($params['code'] ?? ''),
            values: is_array($params['values'] ?? null) ? $params['values'] : [],
            inheritKeys: array_values(array_map('strval', (array)($params['inherit_keys'] ?? []))),
            baseVersions: is_array($params['base_versions'] ?? null) ? $params['base_versions'] : [],
            scope: isset($params['scope']) ? (string)$params['scope'] : null,
            locale: isset($params['locale']) ? (string)$params['locale'] : null,
            options: $this->extractSaveOptions($params)
        );
    }

    private function precheckTemplateConfigRollback(array $params): array
    {
        return $this->configCenterService->precheckTemplateConfigRollback(
            (int)($params['version_id'] ?? 0),
            $this->extractRollbackContext($params)
        );
    }

    private function rollbackTemplateConfigVersion(array $params): array
    {
        return $this->configCenterService->rollbackTemplateConfigVersion(
            (int)($params['version_id'] ?? 0),
            array_merge($this->extractRollbackContext($params), $this->extractSaveOptions($params))
        );
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

    private function extractRollbackContext(array $params): array
    {
        $allowed = ['module', 'area', 'code', 'scope', 'locale'];
        $context = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $context[$key] = $params[$key];
            }
        }

        return $context;
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
                    'description' => __('Get a resolved config value, or a field object when return_type=field.'),
                    'params' => $this->getConfigParams(),
                ],
                [
                    'name' => 'resolveConfig',
                    'description' => __('Get a resolved config value with source and fallback metadata.'),
                    'params' => $this->commonReadParams(true),
                ],
                [
                    'name' => 'getConfigs',
                    'description' => __('Get resolved config values for a module, or field objects when return_type=fields.'),
                    'params' => $this->getConfigsParams(),
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
                    'name' => 'getTemplates',
                    'description' => __('List SystemConfig templates registered through Extends.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplateMeta',
                    'description' => __('Parse one SystemConfig PHTML template without executing it.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => true],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getModules',
                    'description' => __('List modules that provide SystemConfig templates.'),
                    'params' => [
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'search', 'type' => 'string', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTree',
                    'description' => __('Build the SystemConfig template tree by module and area.'),
                    'params' => [
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'search', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'with_values', 'type' => 'bool', 'required' => false],
                        ['name' => 'force_reload', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'saveTemplateConfig',
                    'description' => __('Save a template-backed config batch after field whitelist validation.'),
                    'params' => array_merge($this->commonModuleParams(), [
                        ['name' => 'code', 'type' => 'string', 'required' => true],
                        ['name' => 'values', 'type' => 'object', 'required' => false],
                        ['name' => 'inherit_keys', 'type' => 'array', 'required' => false],
                        ['name' => 'base_versions', 'type' => 'object', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                    ]),
                ],
                [
                    'name' => 'precheckTemplateConfigRollback',
                    'description' => __('Precheck whether a template-backed config version can rollback without mutation.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'rollbackTemplateConfigVersion',
                    'description' => __('Rollback a template-backed config version after rollback precheck.'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'module', 'type' => 'string', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'code', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_id', 'type' => 'string', 'required' => false],
                        ['name' => 'actor_name', 'type' => 'string', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
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

    private function getConfigParams(): array
    {
        $params = $this->commonReadParams(true);
        $params[] = [
            'name' => 'return_type',
            'type' => 'string',
            'required' => false,
            'description' => __('value|field|array; default value keeps compatibility.'),
        ];
        $params[] = [
            'name' => 'code',
            'type' => 'string',
            'required' => false,
            'description' => __('Limit field metadata lookup to one template code when return_type=field.'),
        ];

        return $params;
    }

    private function getConfigsParams(): array
    {
        $params = $this->commonModuleParams();
        $params[] = [
            'name' => 'return_type',
            'type' => 'string',
            'required' => false,
            'description' => __('map|fields; default map keeps compatibility.'),
        ];
        $params[] = [
            'name' => 'code',
            'type' => 'string',
            'required' => false,
            'description' => __('Limit field metadata lookup to one template code when return_type=fields.'),
        ];

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
