<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

#[Table(comment: 'System config table')]
#[Index(name: 'idx_system_config_identity', columns: ['module', 'area', 'key', 'scope', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_system_config_module_scope', columns: ['module', 'area', 'scope', 'locale'])]
#[Index(name: 'idx_key_module_area', columns: ['key', 'module', 'area'])]
#[Index(name: 'idx_module', columns: ['module'])]
class SystemConfig extends \Weline\Framework\Database\Model
{
    private const CACHE_HIT_FLAG = '__system_config_cache_hit';

    public const SCOPE_GLOBAL = 'default.default.default';
    public const LOCALE_DEFAULT = 'default';

    public const VALUE_TYPE_STRING = 'string';
    public const VALUE_TYPE_INT = 'int';
    public const VALUE_TYPE_FLOAT = 'float';
    public const VALUE_TYPE_BOOL = 'bool';
    public const VALUE_TYPE_JSON = 'json';
    public const VALUE_TYPE_NULL = 'null';

    public const schema_table = 'system_config';
    /** @var list<string> */
    public const schema_primary_keys = ['key', 'module', 'area', 'scope', 'locale'];

    #[Col('varchar', 120, nullable: false, comment: 'Config key')]
    public const schema_fields_KEY = 'key';

    #[Col('text', comment: 'Config value')]
    public const schema_fields_VALUE = 'v';

    #[Col('varchar', 120, nullable: false, comment: 'Module')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 120, nullable: false, default: 'frontend', comment: 'Area')]
    public const schema_fields_AREA = 'area';

    #[Col('varchar', 191, nullable: false, default: self::SCOPE_GLOBAL, comment: 'Config scope: website.store.extra')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('varchar', 32, nullable: false, default: self::LOCALE_DEFAULT, comment: 'Locale code or default')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('varchar', 16, nullable: false, default: self::VALUE_TYPE_STRING, comment: 'Serialized value type')]
    public const schema_fields_VALUE_TYPE = 'value_type';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Whether value is sensitive')]
    public const schema_fields_IS_SENSITIVE = 'is_sensitive';

    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Whether config row is active')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Optimistic row version')]
    public const schema_fields_VERSION = 'version';

    #[Col('text', nullable: true, comment: 'Config metadata JSON')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('varchar', 96, nullable: true, comment: 'Updated by actor')]
    public const schema_fields_UPDATED_BY = 'updated_by';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    public array $_index_sort_keys = ['module', 'area', 'scope', 'locale', 'key'];
    public array $_unit_primary_keys = ['key', 'module', 'area', 'scope', 'locale'];

    /** @var array<string, array<string, array<int, array<string, mixed>>>> */
    public static array $configs = [];

    public function __init()
    {
        parent::__init();
        if (!isset($this->_cache)) {
            $this->_cache = w_cache('system_config');
        }
    }

    public function normalizeScope(?string $scope = null): string
    {
        $scope = trim((string)($scope ?? ''));
        if ($scope === '') {
            $scope = trim((string)RequestContext::get('system_config.scope', ''));
        }
        if ($scope === '') {
            $scope = trim((string)RequestContext::get('scope', ''));
        }
        if ($scope === '') {
            $websiteCode = trim(RequestContext::getWelineWebsiteCode());
            $scope = $websiteCode !== '' ? $websiteCode : self::SCOPE_GLOBAL;
        }

        $segments = array_values(array_filter(
            array_map('trim', explode('.', strtolower($scope))),
            static fn(string $segment): bool => $segment !== ''
        ));
        $segments = array_slice($segments, 0, 3);
        while (count($segments) < 3) {
            $segments[] = 'default';
        }

        return implode('.', $segments);
    }

    public function normalizeLocale(?string $locale = null): string
    {
        $locale = trim((string)($locale ?? ''));
        if ($locale === '') {
            $locale = trim((string)RequestContext::get('system_config.locale', ''));
        }
        if ($locale === '') {
            $locale = trim(RequestContext::getWelineUserLang());
        }

        return $locale !== '' ? $locale : self::LOCALE_DEFAULT;
    }

    /**
     * @return list<string>
     */
    public function getFallbackScopes(?string $scope = null): array
    {
        $normalized = $this->normalizeScope($scope);
        [$website, $store, $extra] = explode('.', $normalized) + ['default', 'default', 'default'];

        return array_values(array_unique([
            implode('.', [$website, $store, $extra]),
            implode('.', [$website, $store, 'default']),
            implode('.', [$website, 'default', 'default']),
            self::SCOPE_GLOBAL,
        ]));
    }

    public function getConfigByModule(
        string $module,
        string $area = self::area_FRONTEND,
        ?string $scope = null,
        ?string $locale = null
    ): ?array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('module_rows', $module, $area, null, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $cacheEntry = $this->readCacheEnvelope($this->buildModuleRowsCacheKey($module, $area, $scope, $locale));
        if ($cacheEntry['hit']) {
            RequestContext::set($requestCacheKey, $cacheEntry['value']);
            return $cacheEntry['value'];
        }

        $rowsByKey = [];
        foreach ($this->getFallbackScopes($scope) as $fallbackScope) {
            foreach ($this->getFallbackLocales($locale) as $fallbackLocale) {
                foreach ($this->loadConfigRowsByModuleIfAvailable($module, $area, $fallbackScope, $fallbackLocale) as $row) {
                    $rowKey = (string)($row[self::schema_fields_KEY] ?? '');
                    if ($rowKey === '' || isset($rowsByKey[$rowKey])) {
                        continue;
                    }
                    if (array_key_exists(self::schema_fields_IS_ACTIVE, $row) && (int)$row[self::schema_fields_IS_ACTIVE] === 0) {
                        continue;
                    }
                    $rowsByKey[$rowKey] = $this->normalizeRow($row, $fallbackScope, $fallbackLocale);
                }
            }
        }

        $rows = array_values($rowsByKey);
        self::$configs[$area][$module] = $rows;
        RequestContext::set($requestCacheKey, $rows);
        $this->writeCacheEnvelope($this->buildModuleRowsCacheKey($module, $area, $scope, $locale), $rows);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigMapByModule(
        string $module,
        string $area = self::area_FRONTEND,
        ?string $scope = null,
        ?string $locale = null
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('module_map', $module, $area, null, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return (array)RequestContext::get($requestCacheKey);
        }

        $cacheEntry = $this->readCacheEnvelope($this->buildModuleMapCacheKey($module, $area, $scope, $locale));
        if ($cacheEntry['hit']) {
            $cachedMap = is_array($cacheEntry['value']) ? $cacheEntry['value'] : [];
            RequestContext::set($requestCacheKey, $cachedMap);
            return $cachedMap;
        }

        $rows = $this->getConfigByModule($module, $area, $scope, $locale) ?? [];
        $configMap = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowKey = (string)($row[self::schema_fields_KEY] ?? '');
            if ($rowKey === '') {
                continue;
            }
            $configMap[$rowKey] = $this->unserializeValue(
                $row[self::schema_fields_VALUE] ?? null,
                (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
            );
        }

        RequestContext::set($requestCacheKey, $configMap);
        $this->writeCacheEnvelope($this->buildModuleMapCacheKey($module, $area, $scope, $locale), $configMap);

        return $configMap;
    }

    public function getConfig(
        string $key,
        string $module,
        string $area,
        mixed $default = null,
        ?string $scope = null,
        ?string $locale = null
    ): mixed {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('resolved', $module, $area, $key, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $row = $this->loadResolvedConfigRow($key, $module, $area, $scope, $locale);
        if ($row !== null) {
            $result = $this->unserializeValue(
                $row[self::schema_fields_VALUE] ?? null,
                (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
            );
            RequestContext::set($requestCacheKey, $result);
            return $this->dispatchConfigGetEvent($key, $module, $area, $result, $scope, $locale);
        }

        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $rootKey = (string)array_shift($keys);
            $rootRow = $this->loadResolvedConfigRow($rootKey, $module, $area, $scope, $locale);
            if ($rootRow !== null) {
                $configValue = $this->unserializeValue(
                    $rootRow[self::schema_fields_VALUE] ?? null,
                    (string)($rootRow[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
                );
                $result = $this->extractNestedConfigValue($configValue, $rootKey, $keys);
                if ($result !== null) {
                    RequestContext::set($requestCacheKey, $result);
                    return $this->dispatchConfigGetEvent($key, $module, $area, $result, $scope, $locale);
                }
            }
        }

        RequestContext::set($requestCacheKey, $default);

        return $this->dispatchConfigGetEvent($key, $module, $area, $default, $scope, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $row = $this->loadResolvedConfigRow($key, $module, $area, $scope, $locale);
        if ($row === null) {
            return [
                'found' => false,
                'key' => $key,
                'module' => $module,
                'area' => $area,
                'requested_scope' => $scope,
                'requested_locale' => $locale,
                'fallback_scopes' => $this->getFallbackScopes($scope),
                'fallback_locales' => $this->getFallbackLocales($locale),
                'value' => $default,
                'source' => null,
            ];
        }

        $value = $this->unserializeValue(
            $row[self::schema_fields_VALUE] ?? null,
            (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
        );

        return [
            'found' => true,
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'requested_scope' => $scope,
            'requested_locale' => $locale,
            'fallback_scopes' => $this->getFallbackScopes($scope),
            'fallback_locales' => $this->getFallbackLocales($locale),
            'value' => $value,
            'source' => [
                'scope' => (string)($row[self::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL),
                'locale' => (string)($row[self::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT),
                'version' => (int)($row[self::schema_fields_VERSION] ?? 0),
                'value_type' => (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING),
                'is_sensitive' => (int)($row[self::schema_fields_IS_SENSITIVE] ?? 0) === 1,
                'metadata' => $this->decodeJson((string)($row[self::schema_fields_METADATA] ?? '')),
            ],
        ];
    }

    /**
     * @throws Exception
     */
    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        return $this->setScopedConfig($key, $value, $module, $area, self::SCOPE_GLOBAL, self::LOCALE_DEFAULT);
    }

    /**
     * @throws Exception
     */
    public function setScopedConfig(
        string $key,
        mixed $value,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): bool {
        $result = $this->saveScopeConfig($module, $area, [$key => $value], $scope, $locale, $options);

        return (bool)($result['success'] ?? false);
    }

    /**
     * @throws Exception
     */
    public function deleteScopedConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): bool {
        $options['inherit_keys'] = array_values(array_unique(array_merge(
            array_map('strval', (array)($options['inherit_keys'] ?? [])),
            [$key]
        )));
        $result = $this->saveScopeConfig($module, $area, [$key => null], $scope, $locale, $options);

        return (bool)($result['success'] ?? false);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws Exception
     */
    public function saveScopeConfig(
        string $module,
        string $area,
        array $values,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $inheritKeys = array_values(array_filter(array_map('strval', (array)($options['inherit_keys'] ?? []))));
        $baseVersions = is_array($options['base_versions'] ?? null) ? $options['base_versions'] : [];
        $actorId = (string)($options['actor_id'] ?? RequestContext::get('system_config.actor_id', ''));
        $actorName = (string)($options['actor_name'] ?? RequestContext::get('system_config.actor_name', ''));
        $reason = (string)($options['reason'] ?? '');
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $now = date('Y-m-d H:i:s');

        $changes = [];
        $rollbackStack = [];

        try {
            foreach ($values as $key => $value) {
                $key = (string)$key;
                if ($key === '') {
                    continue;
                }
                $oldRow = $this->loadExactConfigRow($key, $module, $area, $scope, $locale);
                $expectedVersion = $baseVersions[$key] ?? null;
                if ($expectedVersion !== null && (int)($oldRow[self::schema_fields_VERSION] ?? 0) !== (int)$expectedVersion) {
                    return [
                        'success' => false,
                        'status' => 'conflict',
                        'conflicts' => [[
                            'key' => $key,
                            'expected_version' => (int)$expectedVersion,
                            'current_version' => (int)($oldRow[self::schema_fields_VERSION] ?? 0),
                        ]],
                    ];
                }

                $rollbackStack[] = [
                    'key' => $key,
                    'old_row' => $oldRow,
                ];

                if (in_array($key, $inheritKeys, true)) {
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    $changes[] = $this->buildChangeRecord('inherit', $key, $module, $area, $scope, $locale, $oldRow, null);
                    continue;
                }

                $serialized = $this->serializeValue($value, (string)($options['value_type'] ?? ''));
                $newVersion = ((int)($oldRow[self::schema_fields_VERSION] ?? 0)) + 1;
                $row = [
                    self::schema_fields_KEY => $key,
                    self::schema_fields_MODULE => $module,
                    self::schema_fields_AREA => $area,
                    self::schema_fields_SCOPE => $scope,
                    self::schema_fields_LOCALE => $locale,
                    self::schema_fields_VALUE => $serialized['value'],
                    self::schema_fields_VALUE_TYPE => $serialized['type'],
                    self::schema_fields_IS_SENSITIVE => (int)($options['is_sensitive'] ?? ($oldRow[self::schema_fields_IS_SENSITIVE] ?? 0)),
                    self::schema_fields_IS_ACTIVE => 1,
                    self::schema_fields_VERSION => $newVersion,
                    self::schema_fields_METADATA => $this->encodeJson(is_array($options['field_metadata'][$key] ?? null) ? $options['field_metadata'][$key] : []),
                    self::schema_fields_UPDATED_AT => $now,
                    self::schema_fields_UPDATED_BY => $actorName !== '' ? $actorName : $actorId,
                ];
                $this->upsertConfigRow($row, $oldRow !== null);
                $changes[] = $this->buildChangeRecord($oldRow === null ? 'insert' : 'update', $key, $module, $area, $scope, $locale, $oldRow, $row);
            }

            $versionId = $this->recordVersionIfAvailable([
                SystemConfigVersion::schema_fields_MODULE => $module,
                SystemConfigVersion::schema_fields_AREA => $area,
                SystemConfigVersion::schema_fields_SCOPE => $scope,
                SystemConfigVersion::schema_fields_LOCALE => $locale,
                SystemConfigVersion::schema_fields_OPERATION => (string)($options['operation'] ?? 'save'),
                SystemConfigVersion::schema_fields_STATUS => SystemConfigVersion::STATUS_APPLIED,
                SystemConfigVersion::schema_fields_CHANGES_JSON => $this->encodeJson($changes),
                SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON => $this->encodeJson($inheritKeys),
                SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON => $this->encodeJson($baseVersions),
                SystemConfigVersion::schema_fields_ACTOR_ID => $actorId,
                SystemConfigVersion::schema_fields_ACTOR_NAME => $actorName,
                SystemConfigVersion::schema_fields_REASON => $reason,
                SystemConfigVersion::schema_fields_METADATA => $this->encodeJson($metadata),
                SystemConfigVersion::schema_fields_PARENT_VERSION_ID => (int)($options['parent_version_id'] ?? 0) ?: null,
                SystemConfigVersion::schema_fields_CREATED_AT => $now,
            ]);
        } catch (\Throwable $e) {
            $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
            throw new Exception((string)__('保存配置失败，已回滚本次批次。%{1}', $e->getMessage()));
        }

        $this->invalidateConfigCachesForModule($module, $area, $scope, $locale, array_keys($values));

        return [
            'success' => true,
            'status' => 'applied',
            'version_id' => $versionId,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    public function rollbackScopeConfigVersion(int $versionId, array $options = []): array
    {
        /** @var SystemConfigVersion $version */
        $version = ObjectManager::getInstance(SystemConfigVersion::class);
        $rows = $version->clear()->reset()
            ->where(SystemConfigVersion::schema_fields_ID, $versionId)
            ->select()
            ->fetchArray();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;

        if (!is_array($row) || empty($row[SystemConfigVersion::schema_fields_ID])) {
            return ['success' => false, 'status' => 'not_found', 'version_id' => $versionId];
        }

        $changes = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_CHANGES_JSON] ?? ''));
        if (!is_array($changes)) {
            $changes = [];
        }

        $module = (string)($row[SystemConfigVersion::schema_fields_MODULE] ?? '');
        $area = (string)($row[SystemConfigVersion::schema_fields_AREA] ?? '');
        $scope = $this->normalizeScope((string)($row[SystemConfigVersion::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL));
        $locale = $this->normalizeLocale((string)($row[SystemConfigVersion::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT));
        $now = date('Y-m-d H:i:s');
        $actorId = (string)($options['actor_id'] ?? RequestContext::get('system_config.actor_id', ''));
        $actorName = (string)($options['actor_name'] ?? RequestContext::get('system_config.actor_name', ''));
        $rollbackChanges = [];
        $conflicts = [];
        $rollbackStack = [];

        try {
            foreach (array_reverse($changes) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $key = (string)($change['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $oldRow = is_array($change['old_row'] ?? null) ? $change['old_row'] : null;
                $newRow = is_array($change['new_row'] ?? null) ? $change['new_row'] : null;
                $currentRow = $this->loadExactConfigRow($key, $module, $area, $scope, $locale);
                $expectedVersion = (int)($newRow[self::schema_fields_VERSION] ?? 0);
                if ($expectedVersion > 0 && (int)($currentRow[self::schema_fields_VERSION] ?? 0) !== $expectedVersion) {
                    $conflicts[] = [
                        'key' => $key,
                        'expected_version' => $expectedVersion,
                        'current_version' => (int)($currentRow[self::schema_fields_VERSION] ?? 0),
                    ];
                    continue;
                }

                $rollbackStack[] = ['key' => $key, 'old_row' => $currentRow];
                if ($oldRow === null) {
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    $rollbackChanges[] = $this->buildChangeRecord('rollback_delete', $key, $module, $area, $scope, $locale, $currentRow, null);
                    continue;
                }

                $oldRow[self::schema_fields_VERSION] = ((int)($currentRow[self::schema_fields_VERSION] ?? 0)) + 1;
                $oldRow[self::schema_fields_UPDATED_AT] = $now;
                $oldRow[self::schema_fields_UPDATED_BY] = $actorName !== '' ? $actorName : $actorId;
                $this->upsertConfigRow($oldRow, $currentRow !== null);
                $rollbackChanges[] = $this->buildChangeRecord('rollback_restore', $key, $module, $area, $scope, $locale, $currentRow, $oldRow);
            }

            if ($conflicts !== []) {
                $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
                return [
                    'success' => false,
                    'status' => 'conflict',
                    'version_id' => $versionId,
                    'conflicts' => $conflicts,
                ];
            }

            $rollbackVersionId = $this->recordVersionIfAvailable([
                SystemConfigVersion::schema_fields_MODULE => $module,
                SystemConfigVersion::schema_fields_AREA => $area,
                SystemConfigVersion::schema_fields_SCOPE => $scope,
                SystemConfigVersion::schema_fields_LOCALE => $locale,
                SystemConfigVersion::schema_fields_OPERATION => 'rollback',
                SystemConfigVersion::schema_fields_STATUS => SystemConfigVersion::STATUS_APPLIED,
                SystemConfigVersion::schema_fields_CHANGES_JSON => $this->encodeJson($rollbackChanges),
                SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON => $this->encodeJson([]),
                SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON => $this->encodeJson([]),
                SystemConfigVersion::schema_fields_ACTOR_ID => $actorId,
                SystemConfigVersion::schema_fields_ACTOR_NAME => $actorName,
                SystemConfigVersion::schema_fields_REASON => (string)($options['reason'] ?? __('回滚配置版本 %{1}', $versionId)),
                SystemConfigVersion::schema_fields_METADATA => $this->encodeJson(['rollback_of' => $versionId]),
                SystemConfigVersion::schema_fields_PARENT_VERSION_ID => $versionId,
                SystemConfigVersion::schema_fields_CREATED_AT => $now,
            ]);
        } catch (\Throwable $e) {
            $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
            throw new Exception((string)__('回滚配置失败，已恢复本次回滚前状态。%{1}', $e->getMessage()));
        }

        $this->invalidateConfigCachesForModule($module, $area, $scope, $locale, array_column($rollbackChanges, 'key'));

        return [
            'success' => true,
            'status' => 'applied',
            'version_id' => $versionId,
            'rollback_version_id' => $rollbackVersionId,
            'changes' => $rollbackChanges,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfigVersions(
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 50
    ): array {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $query = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_MODULE, $module)
                ->where(SystemConfigVersion::schema_fields_AREA, $area)
                ->where(SystemConfigVersion::schema_fields_SCOPE, $this->normalizeScope($scope))
                ->where(SystemConfigVersion::schema_fields_LOCALE, $this->normalizeLocale($locale))
                ->order(SystemConfigVersion::schema_fields_ID, 'DESC');
            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->select()->fetchArray();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfigVersionDetail(int $versionId): ?array
    {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $rows = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_ID, $versionId)
                ->select()
                ->fetchArray();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return null;
            }
            throw $e;
        }

        if (!is_array($row) || empty($row[SystemConfigVersion::schema_fields_ID])) {
            return null;
        }

        $row['changes'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_CHANGES_JSON] ?? ''));
        $row['inherit_keys'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON] ?? ''));
        $row['base_versions'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON] ?? ''));
        $row['metadata_data'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_METADATA] ?? ''));

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadConfigRowsByModule(string $module, string $area, string $scope, string $locale): array
    {
        return $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_SCOPE, $scope],
                [self::schema_fields_LOCALE, $locale],
            ])
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadSingleConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        $configRows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_SCOPE, $scope],
                [self::schema_fields_LOCALE, $locale],
            ])
            ->select()
            ->fetchArray();
        $configRow = is_array($configRows) ? ($configRows[0] ?? null) : null;

        return is_array($configRow) && array_key_exists(self::schema_fields_KEY, $configRow) ? $configRow : null;
    }

    protected function dispatchConfigGetEvent(
        string $key,
        string $module,
        string $area,
        mixed $value,
        string $scope,
        string $locale
    ): mixed {
        $eventData = [
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'value' => $value,
        ];

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_SystemConfig::domain::config_get', $eventData);

        return $eventData['value'] ?? $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadResolvedConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        foreach ($this->getFallbackScopes($scope) as $fallbackScope) {
            foreach ($this->getFallbackLocales($locale) as $fallbackLocale) {
                $row = $this->loadSingleConfigRowIfAvailable($key, $module, $area, $fallbackScope, $fallbackLocale);
                if ($row !== null) {
                    if (array_key_exists(self::schema_fields_IS_ACTIVE, $row) && (int)$row[self::schema_fields_IS_ACTIVE] === 0) {
                        continue;
                    }
                    return $this->normalizeRow($row, $fallbackScope, $fallbackLocale);
                }
            }
        }

        return null;
    }

    /**
     * setup:upgrade may read config before SchemaDiff creates or upgrades system_config.
     */
    private function loadConfigRowsByModuleIfAvailable(string $module, string $area, string $scope, string $locale): array
    {
        try {
            return $this->loadConfigRowsByModule($module, $area, $scope, $locale);
        } catch (\Throwable $e) {
            if ($this->isSystemConfigTableMissing($e)) {
                return [];
            }
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                return $this->loadLegacyConfigRowsByModule($module, $area, $scope, $locale);
            }
            throw $e;
        }
    }

    /**
     * setup:upgrade may read config before SchemaDiff creates or upgrades system_config.
     */
    private function loadSingleConfigRowIfAvailable(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        try {
            return $this->loadSingleConfigRow($key, $module, $area, $scope, $locale);
        } catch (\Throwable $e) {
            if ($this->isSystemConfigTableMissing($e)) {
                return null;
            }
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                return $this->loadLegacySingleConfigRow($key, $module, $area, $scope, $locale);
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadExactConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        return $this->loadSingleConfigRowIfAvailable($key, $module, $area, $scope, $locale);
    }

    private function isSystemConfigTableMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'system_config')) {
            return false;
        }

        return str_contains($message, 'no such table')
            || str_contains($message, 'base table or view not found')
            || str_contains($message, 'undefined table')
            || str_contains($message, 'does not exist');
    }

    private function isSystemConfigScopeSchemaMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $isColumnError = str_contains($message, 'unknown column')
            || str_contains($message, 'no such column')
            || str_contains($message, 'undefined column')
            || str_contains($message, 'column not found');
        if (!$isColumnError) {
            return false;
        }

        return str_contains($message, 'system_config')
            || str_contains($message, 'scope')
            || str_contains($message, 'locale')
            || str_contains($message, 'is_active')
            || str_contains($message, 'value_type');
    }

    private function isSystemConfigVersionTableMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'system_config_version')
            && (
                str_contains($message, 'no such table')
                || str_contains($message, 'base table or view not found')
                || str_contains($message, 'undefined table')
                || str_contains($message, 'does not exist')
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLegacyConfigRowsByModule(string $module, string $area, string $scope, string $locale): array
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return [];
        }

        $rows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeRow($row, $scope, $locale), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLegacySingleConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return null;
        }

        $configRows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();
        $configRow = is_array($configRows) ? ($configRows[0] ?? null) : null;

        return is_array($configRow) && array_key_exists(self::schema_fields_KEY, $configRow)
            ? $this->normalizeRow($configRow, $scope, $locale)
            : null;
    }

    private function extractNestedConfigValue(mixed $configValue, string $rootKey, array $keys): mixed
    {
        if (is_string($configValue)) {
            $configValue = json_decode($configValue, true);
        }

        if (!is_array($configValue)) {
            return null;
        }

        $result = $configValue[$rootKey] ?? null;
        foreach ($keys as $nestedKey) {
            if (!is_array($result) || !array_key_exists($nestedKey, $result)) {
                return null;
            }
            $result = $result[$nestedKey];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getFallbackLocales(string $locale): array
    {
        return array_values(array_unique([$this->normalizeLocale($locale), self::LOCALE_DEFAULT]));
    }

    /**
     * @return array{value: string, type: string}
     */
    private function serializeValue(mixed $value, string $preferredType = ''): array
    {
        $type = $preferredType !== '' ? $preferredType : match (true) {
            $value === null => self::VALUE_TYPE_NULL,
            is_bool($value) => self::VALUE_TYPE_BOOL,
            is_int($value) => self::VALUE_TYPE_INT,
            is_float($value) => self::VALUE_TYPE_FLOAT,
            is_array($value) || is_object($value) => self::VALUE_TYPE_JSON,
            default => self::VALUE_TYPE_STRING,
        };

        $serialized = match ($type) {
            self::VALUE_TYPE_NULL => '',
            self::VALUE_TYPE_BOOL => $value ? '1' : '0',
            self::VALUE_TYPE_INT => (string)(int)$value,
            self::VALUE_TYPE_FLOAT => (string)(float)$value,
            self::VALUE_TYPE_JSON => $this->encodeJson($value),
            default => (string)$value,
        };

        return ['value' => $serialized, 'type' => $type];
    }

    private function unserializeValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            self::VALUE_TYPE_NULL => null,
            self::VALUE_TYPE_BOOL => (string)$value === '1' || $value === true,
            self::VALUE_TYPE_INT => (int)$value,
            self::VALUE_TYPE_FLOAT => (float)$value,
            self::VALUE_TYPE_JSON => $this->decodeJson((string)$value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $scope, string $locale): array
    {
        $row[self::schema_fields_SCOPE] ??= $scope;
        $row[self::schema_fields_LOCALE] ??= $locale;
        $row[self::schema_fields_VALUE_TYPE] ??= self::VALUE_TYPE_STRING;
        $row[self::schema_fields_IS_SENSITIVE] ??= 0;
        $row[self::schema_fields_IS_ACTIVE] ??= 1;
        $row[self::schema_fields_VERSION] ??= 0;
        $row[self::schema_fields_METADATA] ??= null;

        return $row;
    }

    /**
     * @param array<string, mixed>|null $oldRow
     * @param array<string, mixed>|null $newRow
     * @return array<string, mixed>
     */
    private function buildChangeRecord(
        string $action,
        string $key,
        string $module,
        string $area,
        string $scope,
        string $locale,
        ?array $oldRow,
        ?array $newRow
    ): array {
        return [
            'action' => $action,
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'old_row' => $oldRow,
            'new_row' => $newRow,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    public function maskSensitiveRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if ((int)($row[self::schema_fields_IS_SENSITIVE] ?? 0) === 1) {
            $row[self::schema_fields_VALUE] = '***';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertConfigRow(array $row, bool $exists): void
    {
        $row = $this->filterConfigRowForWrite($row);
        $where = $this->identityWhere(
            (string)$row[self::schema_fields_KEY],
            (string)$row[self::schema_fields_MODULE],
            (string)$row[self::schema_fields_AREA],
            (string)$row[self::schema_fields_SCOPE],
            (string)$row[self::schema_fields_LOCALE]
        );

        try {
            if ($exists) {
                $this->updateConfigRow($row, $where);
                return;
            }

            $this->clear()->reset()->insert($row, [], '', true)->fetch();
        } catch (\Throwable $e) {
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                $this->legacyUpsertConfigRow($row, $exists);
                return;
            }
            if (!$exists && $this->isDuplicateIdentityError($e)) {
                $this->updateConfigRow($row, $where);
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{0: string, 1: mixed}> $where
     */
    private function updateConfigRow(array $row, array $where): void
    {
        $updateRow = $row;
        foreach ([
            self::schema_fields_KEY,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
            self::schema_fields_SCOPE,
            self::schema_fields_LOCALE,
            self::schema_fields_UPDATED_AT,
            self::schema_fields_CREATE_TIME,
            self::schema_fields_UPDATE_TIME,
        ] as $field) {
            unset($updateRow[$field]);
        }

        if ($updateRow === []) {
            return;
        }

        $this->clear()->reset()->where($where)->update($updateRow)->fetch();
    }

    private function isDuplicateIdentityError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key value')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'sqlstate[23505]');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterConfigRowForWrite(array $row): array
    {
        $allowed = [
            self::schema_fields_KEY,
            self::schema_fields_VALUE,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
            self::schema_fields_SCOPE,
            self::schema_fields_LOCALE,
            self::schema_fields_VALUE_TYPE,
            self::schema_fields_IS_SENSITIVE,
            self::schema_fields_IS_ACTIVE,
            self::schema_fields_VERSION,
            self::schema_fields_METADATA,
            self::schema_fields_UPDATED_AT,
            self::schema_fields_UPDATED_BY,
        ];

        return array_intersect_key($row, array_fill_keys($allowed, true));
    }

    private function deleteConfigRow(string $key, string $module, string $area, string $scope, string $locale): void
    {
        try {
            $this->clear()->reset()
                ->where($this->identityWhere($key, $module, $area, $scope, $locale))
                ->delete()
                ->fetch();
        } catch (\Throwable $e) {
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                $this->legacyDeleteConfigRow($key, $module, $area, $scope, $locale);
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function legacyUpsertConfigRow(array $row, bool $exists): void
    {
        if ((string)($row[self::schema_fields_SCOPE] ?? '') !== self::SCOPE_GLOBAL
            || (string)($row[self::schema_fields_LOCALE] ?? '') !== self::LOCALE_DEFAULT
        ) {
            throw new Exception((string)__('当前 system_config 表尚未升级，不能保存非全局 scope 配置。'));
        }

        $legacyRow = [
            self::schema_fields_KEY => $row[self::schema_fields_KEY],
            self::schema_fields_MODULE => $row[self::schema_fields_MODULE],
            self::schema_fields_AREA => $row[self::schema_fields_AREA],
            self::schema_fields_VALUE => $row[self::schema_fields_VALUE],
        ];
        $legacyWhere = [
            [self::schema_fields_KEY, $row[self::schema_fields_KEY]],
            [self::schema_fields_MODULE, $row[self::schema_fields_MODULE]],
            [self::schema_fields_AREA, $row[self::schema_fields_AREA]],
        ];

        if ($exists) {
            $this->clear()->reset()->where($legacyWhere)->update($legacyRow)->fetch();
            return;
        }

        $this->clear()->reset()->insert($legacyRow, [
            self::schema_fields_KEY,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
        ])->fetch();
    }

    private function legacyDeleteConfigRow(string $key, string $module, string $area, string $scope, string $locale): void
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return;
        }

        $this->clear()->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_AREA, $area],
            ])
            ->delete()
            ->fetch();
    }

    /**
     * @param array<int, array{key: string, old_row: ?array<string, mixed>}> $rollbackStack
     */
    private function restoreRollbackStack(string $module, string $area, string $scope, string $locale, array $rollbackStack): void
    {
        foreach (array_reverse($rollbackStack) as $item) {
            $key = (string)($item['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $oldRow = $item['old_row'] ?? null;
            try {
                if ($oldRow === null) {
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    continue;
                }
                $this->upsertConfigRow($oldRow, $this->loadExactConfigRow($key, $module, $area, $scope, $locale) !== null);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function identityWhere(string $key, string $module, string $area, string $scope, string $locale): array
    {
        return [
            [self::schema_fields_KEY, $key],
            [self::schema_fields_MODULE, $module],
            [self::schema_fields_AREA, $area],
            [self::schema_fields_SCOPE, $scope],
            [self::schema_fields_LOCALE, $locale],
        ];
    }

    /**
     * @param list<string> $keys
     */
    private function invalidateConfigCachesForModule(string $module, string $area, string $scope, string $locale, array $keys = []): void
    {
        unset(self::$configs[$area][$module]);

        foreach ($keys as $key) {
            $key = (string)$key;
            RequestContext::remove($this->buildRequestCacheKey('raw', $module, $area, $key, $scope, $locale));
            RequestContext::remove($this->buildRequestCacheKey('resolved', $module, $area, $key, $scope, $locale));
            foreach ($this->getFallbackScopes($scope) as $fallbackScope) {
                foreach ($this->getFallbackLocales($locale) as $fallbackLocale) {
                    $this->_cache?->delete($this->buildSingleCacheKey($key, $module, $area, $fallbackScope, $fallbackLocale));
                }
            }
        }

        RequestContext::remove($this->buildRequestCacheKey('module_rows', $module, $area, null, $scope, $locale));
        RequestContext::remove($this->buildRequestCacheKey('module_map', $module, $area, null, $scope, $locale));

        $this->_cache?->delete($this->buildModuleRowsCacheKey($module, $area, $scope, $locale));
        $this->_cache?->delete($this->buildModuleMapCacheKey($module, $area, $scope, $locale));
    }

    private function buildSingleCacheKey(
        string $key,
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        return 'system_config_cache_' . sha1(implode('|', [$area, $module, $key, $scope, $locale]));
    }

    private function buildModuleRowsCacheKey(
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        return 'system_config_rows_' . sha1(implode('|', [$area, $module, $scope, $locale]));
    }

    private function buildModuleMapCacheKey(
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        return 'system_config_map_' . sha1(implode('|', [$area, $module, $scope, $locale]));
    }

    private function buildRequestCacheKey(
        string $type,
        string $module,
        string $area,
        ?string $key = null,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        return implode(':', array_filter([
            'system_config',
            $type,
            $area,
            $module,
            $key,
            $scope,
            $locale,
        ], static fn(?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function readCacheEnvelope(string $cacheKey): array
    {
        $cached = $this->_cache?->get($cacheKey);
        if (!is_array($cached) || ($cached[self::CACHE_HIT_FLAG] ?? false) !== true || !array_key_exists('value', $cached)) {
            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $cached['value']];
    }

    private function writeCacheEnvelope(string $cacheKey, mixed $value): void
    {
        $this->_cache?->set($cacheKey, [
            self::CACHE_HIT_FLAG => true,
            'value' => $value,
        ]);
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'null';
    }

    private function decodeJson(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordVersionIfAvailable(array $data): ?int
    {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $version->clear()->reset()->insert($data, [], '', true)->fetch();
            $rows = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_MODULE, (string)($data[SystemConfigVersion::schema_fields_MODULE] ?? ''))
                ->where(SystemConfigVersion::schema_fields_AREA, (string)($data[SystemConfigVersion::schema_fields_AREA] ?? ''))
                ->where(SystemConfigVersion::schema_fields_SCOPE, (string)($data[SystemConfigVersion::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL))
                ->where(SystemConfigVersion::schema_fields_LOCALE, (string)($data[SystemConfigVersion::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT))
                ->order(SystemConfigVersion::schema_fields_ID, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;

            return is_array($row) && !empty($row[SystemConfigVersion::schema_fields_ID])
                ? (int)$row[SystemConfigVersion::schema_fields_ID]
                : null;
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return null;
            }
            throw $e;
        }
    }
}
