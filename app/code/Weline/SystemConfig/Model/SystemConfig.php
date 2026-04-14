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
#[Index(name: 'idx_key_module_area', columns: ['key', 'module', 'area'])]
#[Index(name: 'idx_module', columns: ['module'])]
class SystemConfig extends \Weline\Framework\Database\Model
{
    private const CACHE_HIT_FLAG = '__system_config_cache_hit';

    public const schema_table = 'system_config';
    /** @var list<string> */
    public const schema_primary_keys = ['key', 'module', 'area'];

    #[Col('varchar', 120, nullable: false, comment: 'Config key')]
    public const schema_fields_KEY = 'key';

    #[Col('text', comment: 'Config value')]
    public const schema_fields_VALUE = 'v';

    #[Col('varchar', 120, nullable: false, comment: 'Module')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 120, nullable: false, default: 'frontend', comment: 'Area')]
    public const schema_fields_AREA = 'area';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    public array $_index_sort_keys = ['key', 'module', 'area'];
    public array $_unit_primary_keys = ['key', 'module', 'area'];

    /** @var array<string, array<string, array<int, array<string, mixed>>>> */
    public static array $configs = [];

    public function __init()
    {
        parent::__init();
        if (!isset($this->_cache)) {
            $this->_cache = w_cache('system_config');
        }
    }

    public function getConfigByModule(string $module, string $area = self::area_FRONTEND): ?array
    {
        $requestCacheKey = $this->buildRequestCacheKey('module_rows', $module, $area);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $cacheEntry = $this->readCacheEnvelope($this->buildModuleRowsCacheKey($module, $area));
        if ($cacheEntry['hit']) {
            RequestContext::set($requestCacheKey, $cacheEntry['value']);
            return $cacheEntry['value'];
        }

        $rows = $this->loadConfigRowsByModule($module, $area);
        self::$configs[$area][$module] = $rows;
        RequestContext::set($requestCacheKey, $rows);
        $this->writeCacheEnvelope($this->buildModuleRowsCacheKey($module, $area), $rows);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigMapByModule(string $module, string $area = self::area_FRONTEND): array
    {
        $requestCacheKey = $this->buildRequestCacheKey('module_map', $module, $area);
        if (RequestContext::has($requestCacheKey)) {
            return (array) RequestContext::get($requestCacheKey);
        }

        $cacheEntry = $this->readCacheEnvelope($this->buildModuleMapCacheKey($module, $area));
        if ($cacheEntry['hit']) {
            $cachedMap = is_array($cacheEntry['value']) ? $cacheEntry['value'] : [];
            RequestContext::set($requestCacheKey, $cachedMap);
            return $cachedMap;
        }

        $rows = $this->getConfigByModule($module, $area) ?? [];
        $configMap = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowKey = (string) ($row[self::schema_fields_KEY] ?? '');
            if ($rowKey === '') {
                continue;
            }
            $configMap[$rowKey] = $row[self::schema_fields_VALUE] ?? null;
        }

        RequestContext::set($requestCacheKey, $configMap);
        $this->writeCacheEnvelope($this->buildModuleMapCacheKey($module, $area), $configMap);

        return $configMap;
    }

    public function getConfig(string $key, string $module, string $area): mixed
    {
        $requestCacheKey = $this->buildRequestCacheKey('resolved', $module, $area, $key);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        if (!str_contains($key, '.')) {
            $result = $this->getOrLoadRawConfigValue($key, $module, $area);
            RequestContext::set($requestCacheKey, $result);
            return $this->dispatchConfigGetEvent($key, $module, $area, $result);
        }

        $keys = explode('.', $key);
        $rootKey = (string) array_shift($keys);
        $configValue = $this->getOrLoadRawConfigValue($rootKey, $module, $area);
        $result = $this->extractNestedConfigValue($configValue, $rootKey, $keys);
        RequestContext::set($requestCacheKey, $result);

        return $this->dispatchConfigGetEvent($key, $module, $area, $result);
    }

    /**
     * @throws Exception
     */
    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $beforeEventData = [
                'key' => $key,
                'value' => $value,
                'module' => $module,
                'area' => $area,
            ];
            $eventsManager->dispatch('Weline_SystemConfig::domain::config_set_before', $beforeEventData);
            $value = (string) ($beforeEventData['value'] ?? $value);

            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            $existing = $config->clear()->reset()
                ->where([
                    [self::schema_fields_KEY, $key],
                    [self::schema_fields_MODULE, $module],
                    [self::schema_fields_AREA, $area],
                ])
                ->find()
                ->fetch();
            $oldValue = $existing[self::schema_fields_VALUE] ?? null;

            if ($existing && isset($existing[self::schema_fields_KEY])) {
                $config->clear()->reset()
                    ->where([
                        [self::schema_fields_KEY, $key],
                        [self::schema_fields_MODULE, $module],
                        [self::schema_fields_AREA, $area],
                    ])
                    ->update([self::schema_fields_VALUE => $value])
                    ->fetch();
            } else {
                $config->clear()->reset()
                    ->insert([
                        self::schema_fields_KEY => $key,
                        self::schema_fields_VALUE => $value,
                        self::schema_fields_MODULE => $module,
                        self::schema_fields_AREA => $area,
                    ], [], '', true)
                    ->fetch();
            }

            $this->invalidateConfigCaches($key, $module, $area);
            $this->writeCacheEnvelope($this->buildSingleCacheKey($key, $module, $area), $value);
            RequestContext::set($this->buildRequestCacheKey('raw', $module, $area, $key), $value);
            RequestContext::set($this->buildRequestCacheKey('resolved', $module, $area, $key), $value);

            $afterEventData = [
                'key' => $key,
                'value' => $value,
                'module' => $module,
                'area' => $area,
                'old_value' => $oldValue,
            ];
            $eventsManager->dispatch('Weline_SystemConfig::domain::config_set_after', $afterEventData);

            return true;
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadConfigRowsByModule(string $module, string $area): array
    {
        return $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();
    }

    protected function loadSingleConfigValue(string $key, string $module, string $area): mixed
    {
        $configRows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();

        $configValue = is_array($configRows) ? ($configRows[0] ?? null) : null;

        if (is_array($configValue) && array_key_exists(self::schema_fields_VALUE, $configValue)) {
            return $configValue[self::schema_fields_VALUE];
        }

        return null;
    }

    protected function dispatchConfigGetEvent(string $key, string $module, string $area, mixed $value): mixed
    {
        $eventData = [
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'value' => $value,
        ];

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_SystemConfig::domain::config_get', $eventData);

        return $eventData['value'] ?? $value;
    }

    private function getOrLoadRawConfigValue(string $key, string $module, string $area): mixed
    {
        $requestCacheKey = $this->buildRequestCacheKey('raw', $module, $area, $key);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $cacheEntry = $this->readCacheEnvelope($this->buildSingleCacheKey($key, $module, $area));
        if ($cacheEntry['hit']) {
            $cachedValue = $cacheEntry['value'];
            if ($cachedValue !== null) {
                RequestContext::set($requestCacheKey, $cachedValue);
                return $cachedValue;
            }

            // Guard against stale null cache: a config key may be created by another request/worker later.
            // Re-check DB once before trusting cached null.
            $dbValue = $this->loadSingleConfigValue($key, $module, $area);
            if ($dbValue !== null) {
                RequestContext::set($requestCacheKey, $dbValue);
                $this->writeCacheEnvelope($this->buildSingleCacheKey($key, $module, $area), $dbValue);
                return $dbValue;
            }

            RequestContext::set($requestCacheKey, null);
            return null;
        }

        $value = $this->loadSingleConfigValue($key, $module, $area);
        RequestContext::set($requestCacheKey, $value);
        $this->writeCacheEnvelope($this->buildSingleCacheKey($key, $module, $area), $value);

        return $value;
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

    private function invalidateConfigCaches(string $key, string $module, string $area): void
    {
        unset(self::$configs[$area][$module]);

        RequestContext::remove($this->buildRequestCacheKey('raw', $module, $area, $key));
        RequestContext::remove($this->buildRequestCacheKey('resolved', $module, $area, $key));
        RequestContext::remove($this->buildRequestCacheKey('module_rows', $module, $area));
        RequestContext::remove($this->buildRequestCacheKey('module_map', $module, $area));

        $this->_cache?->delete($this->buildSingleCacheKey($key, $module, $area));
        $this->_cache?->delete($this->buildModuleRowsCacheKey($module, $area));
        $this->_cache?->delete($this->buildModuleMapCacheKey($module, $area));
    }

    private function buildSingleCacheKey(string $key, string $module, string $area): string
    {
        return 'system_config_cache_' . $key . '_' . $area . '_' . $module;
    }

    private function buildModuleRowsCacheKey(string $module, string $area): string
    {
        return 'system_config_rows_' . $area . '_' . $module;
    }

    private function buildModuleMapCacheKey(string $module, string $area): string
    {
        return 'system_config_map_' . $area . '_' . $module;
    }

    private function buildRequestCacheKey(string $type, string $module, string $area, ?string $key = null): string
    {
        return implode(':', array_filter([
            'system_config',
            $type,
            $area,
            $module,
            $key,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));
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
}
