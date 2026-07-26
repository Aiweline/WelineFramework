<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;

class VisitorTrackingConfig
{
    public const MODULE = 'Weline_Visitor';

    private const KEY_PIXEL_ENABLED = 'visitor/tracking/pixel_enabled';
    private const KEY_GA4_ENABLED = 'visitor/tracking/ga4_enabled';
    private const KEY_GA4_MEASUREMENT_ID = 'visitor/tracking/ga4_measurement_id';
    private const KEY_GA4_ENABLE_IN_DEV = 'visitor/tracking/ga4_enable_in_dev';
    private const KEY_GA4_AUTO_TRACK_VISITOR_EVENTS = 'visitor/tracking/ga4_auto_track_visitor_events';
    private const KEY_GA4_CTA_EVENT_NAME = 'visitor/tracking/ga4_cta_event_name';
    private const KEY_GA4_DEBUG_MODE = 'visitor/tracking/ga4_debug_mode';
    private const KEY_GTM_ENABLED = 'visitor/tracking/gtm_enabled';
    private const KEY_GTM_CONTAINER_ID = 'visitor/tracking/gtm_container_id';
    private const KEY_HOT_BUFFER_ENABLED = 'visitor/tracking/hot_buffer_enabled';
    private const KEY_HOT_BUFFER_FLUSH_INTERVAL = 'visitor/tracking/hot_buffer_flush_interval';
    private const KEY_HOT_BUFFER_BATCH_SIZE = 'visitor/tracking/hot_buffer_batch_size';
    private const KEY_HOT_BUFFER_TTL = 'visitor/tracking/hot_buffer_ttl';
    private const KEY_CONSENT_MODE_ENABLED = 'visitor/tracking/consent_mode_enabled';
    private const KEY_STICKY_UTM_TTL_HOURS = 'visitor/tracking/sticky_utm_ttl_hours';
    private const KEY_STICKY_LINKER_ENABLED = 'visitor/tracking/sticky_linker_enabled';
    private const KEY_STICKY_FORM_MERGE_ENABLED = 'visitor/tracking/sticky_form_merge_enabled';
    private const KEY_ATTRIBUTION_BACKFILL_DONE = 'visitor/tracking/attribution_backfill_done';
    private const KEY_LEGACY_CRON_SOURCE_ENABLED = 'visitor/tracking/legacy_cron_source_enabled';
    private const KEY_RETENTION_HOT_DAYS = 'visitor/tracking/retention_hot_days';
    private const KEY_RETENTION_WARM_DAYS = 'visitor/tracking/retention_warm_days';
    private const KEY_COLD_ARCHIVE_ENABLED = 'visitor/tracking/cold_archive_enabled';
    private const KEY_EXCLUDE_LOCAL_FORWARDING = 'visitor/tracking/exclude_local_forwarding';
    private const KEY_EXCLUDED_HOSTS = 'visitor/tracking/excluded_hosts';
    private const KEY_EXCLUDED_PATH_PREFIXES = 'visitor/tracking/excluded_path_prefixes';
    private const KEY_EXCLUDED_QUERY_KEYS = 'visitor/tracking/excluded_query_keys';
    private const KEY_EXCLUDED_REFERRER_HOSTS = 'visitor/tracking/excluded_referrer_hosts';
    private const KEY_EXCLUDED_USER_AGENT_KEYWORDS = 'visitor/tracking/excluded_user_agent_keywords';
    private const KEY_CUSTOM_FORWARDER_ENABLED = 'visitor/tracking/custom_forwarder_js_enabled';
    private const KEY_CUSTOM_FORWARDER_JS = 'visitor/tracking/custom_forwarder_js';

    public const CONFIG_KEY_ATTRIBUTION_BACKFILL_DONE = self::KEY_ATTRIBUTION_BACKFILL_DONE;
    public const CONFIG_KEY_LEGACY_CRON_SOURCE_ENABLED = self::KEY_LEGACY_CRON_SOURCE_ENABLED;
    public const CONFIG_KEY_RETENTION_HOT_DAYS = self::KEY_RETENTION_HOT_DAYS;
    public const CONFIG_KEY_RETENTION_WARM_DAYS = self::KEY_RETENTION_WARM_DAYS;
    public const CONFIG_KEY_COLD_ARCHIVE_ENABLED = self::KEY_COLD_ARCHIVE_ENABLED;

    /** G10 默认：热明细保留天数（与 PixelQueryRouter::DEFAULT_HOT_RETENTION_DAYS 对齐）。 */
    public const DEFAULT_RETENTION_HOT_DAYS = 365;
    /** G10 默认：温聚合可查天数。 */
    public const DEFAULT_RETENTION_WARM_DAYS = 1095;

    public function __construct(
        private ?SystemConfig $systemConfig = null,
        private ?\Weline\SystemConfig\Api\ConfigStore $configStore = null,
        private ?EventDictionaryService $eventDictionary = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeConfig(?string $scope = null, ?string $locale = null): array
    {
        $map = $this->readConfigMap($scope, $locale);
        $measurementId = $this->normalizeMeasurementId((string)($map[self::KEY_GA4_MEASUREMENT_ID] ?? ''));
        $ga4SwitchEnabled = $this->toBool($map[self::KEY_GA4_ENABLED] ?? false, false);
        $containerId = $this->normalizeGtmContainerId((string)($map[self::KEY_GTM_CONTAINER_ID] ?? ''));
        $gtmSwitchEnabled = $this->toBool($map[self::KEY_GTM_ENABLED] ?? false, false);
        $gtmConfigured = $gtmSwitchEnabled && $containerId !== '';
        // Mutual exclusion: GTM wins → disable GA4 direct gtag forwarder.
        $ga4Enabled = $ga4SwitchEnabled && $measurementId !== '' && !$gtmConfigured;
        $dictionary = $this->dictionary()->toRuntimeFragment();

        return [
            'module' => self::MODULE,
            'area' => SystemConfig::area_BACKEND,
            'scope' => $this->normalizeScope($scope),
            'dictVersion' => (string)($dictionary['version'] ?? ''),
            'eventDictionary' => $dictionary,
            'pixel' => [
                'enabled' => $this->toBool($map[self::KEY_PIXEL_ENABLED] ?? true, true),
            ],
            'hotBuffer' => [
                'enabled' => $this->toBool($map[self::KEY_HOT_BUFFER_ENABLED] ?? true, true),
                'flushInterval' => $this->boundedInt($map[self::KEY_HOT_BUFFER_FLUSH_INTERVAL] ?? 15, 15, 1, 300),
                'batchSize' => $this->boundedInt($map[self::KEY_HOT_BUFFER_BATCH_SIZE] ?? 500, 500, 1, 5000),
                'ttl' => $this->boundedInt($map[self::KEY_HOT_BUFFER_TTL] ?? 300, 300, 60, 3600),
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'consent' => [
                'enabled' => $this->toBool($map[self::KEY_CONSENT_MODE_ENABLED] ?? false, false),
                // A08 前端门闩读取；A08a 仅注入，不改变现有 JS 行为
                'marketingStorageKey' => 'ad_storage',
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'sticky' => [
                'ttlHours' => $this->boundedInt($map[self::KEY_STICKY_UTM_TTL_HOURS] ?? 24, 24, 1, 8760),
                'linkerEnabled' => $this->toBool($map[self::KEY_STICKY_LINKER_ENABLED] ?? true, true),
                'formMergeEnabled' => $this->toBool($map[self::KEY_STICKY_FORM_MERGE_ENABLED] ?? false, false),
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'attribution' => [
                'backfillDone' => $this->toBool($map[self::KEY_ATTRIBUTION_BACKFILL_DONE] ?? false, false),
                'legacyCronSourceEnabled' => $this->toBool($map[self::KEY_LEGACY_CRON_SOURCE_ENABLED] ?? false, false),
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'retention' => $this->buildRetentionRuntime($map),
            'trafficRules' => [
                'source' => 'Weline_Visitor SystemConfig',
                'excludeLocalForwarding' => $this->toBool($map[self::KEY_EXCLUDE_LOCAL_FORWARDING] ?? true, true),
                'excludedHosts' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_HOSTS] ?? ''), true),
                'excludedPathPrefixes' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_PATH_PREFIXES] ?? ''), false),
                'excludedQueryKeys' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_QUERY_KEYS] ?? ''), true),
                'excludedReferrerHosts' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_REFERRER_HOSTS] ?? ''), true),
                'excludedUserAgentKeywords' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_USER_AGENT_KEYWORDS] ?? ''), true),
            ],
            'gtm' => [
                'enabled' => $gtmConfigured,
                'configured' => $containerId !== '',
                'containerId' => $containerId,
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'ga4' => [
                'enabled' => $ga4Enabled,
                'configured' => $measurementId !== '',
                'measurementId' => $measurementId,
                'enableInDev' => $this->toBool($map[self::KEY_GA4_ENABLE_IN_DEV] ?? false, false),
                'autoTrackVisitorEvents' => $this->toBool($map[self::KEY_GA4_AUTO_TRACK_VISITOR_EVENTS] ?? true, true),
                'ctaEventName' => $this->normalizeEventName((string)($map[self::KEY_GA4_CTA_EVENT_NAME] ?? 'cta_click')),
                'debugMode' => $this->toBool($map[self::KEY_GA4_DEBUG_MODE] ?? false, false),
                'disabledByGtm' => $gtmConfigured,
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'forwarders' => [
                'eventBus' => [
                    'enabled' => true,
                    'contractVersion' => 'weline-visitor-event/v1',
                ],
                'gtm' => [
                    'enabled' => $gtmConfigured,
                    'configured' => $containerId !== '',
                    'containerId' => $containerId,
                ],
                'ga4' => [
                    'enabled' => $ga4Enabled,
                    'configured' => $measurementId !== '',
                    'measurementId' => $measurementId,
                    'autoTrackVisitorEvents' => $this->toBool($map[self::KEY_GA4_AUTO_TRACK_VISITOR_EVENTS] ?? true, true),
                ],
                'custom' => [
                    'enabled' => $this->toBool($map[self::KEY_CUSTOM_FORWARDER_ENABLED] ?? false, false),
                    'script' => $this->normalizeCustomForwarderScript((string)($map[self::KEY_CUSTOM_FORWARDER_JS] ?? '')),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfigMap(?string $scope, ?string $locale): array
    {
        try {
            return $this->getSystemConfig()->getConfigMapByModule(
                self::MODULE,
                SystemConfig::area_BACKEND,
                $scope,
                $locale
            );
        } catch (\Throwable $throwable) {
            if (defined('DEV') && DEV) {
                w_log_error('读取 Visitor 统计配置失败: ' . $throwable->getMessage());
            }
            return [];
        }
    }

    private function normalizeScope(?string $scope): string
    {
        try {
            return $this->getSystemConfig()->normalizeScope($scope);
        } catch (\Throwable) {
            return SystemConfig::SCOPE_GLOBAL;
        }
    }

    private function normalizeMeasurementId(string $measurementId): string
    {
        $measurementId = strtoupper(trim($measurementId));
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $measurementId) ? $measurementId : '';
    }

    private function normalizeGtmContainerId(string $containerId): string
    {
        $containerId = strtoupper(trim($containerId));
        return preg_match('/^GTM-[A-Z0-9]{4,12}$/', $containerId) ? $containerId : '';
    }

    private function dictionary(): EventDictionaryService
    {
        if (!$this->eventDictionary) {
            $this->eventDictionary = ObjectManager::getInstance(EventDictionaryService::class);
        }
        return $this->eventDictionary;
    }

    private function normalizeEventName(string $eventName): string
    {
        $eventName = strtolower(trim($eventName));
        $eventName = preg_replace('/[^a-z0-9_]+/', '_', $eventName) ?: '';
        $eventName = trim($eventName, '_');
        return $eventName !== '' ? substr($eventName, 0, 40) : 'cta_click';
    }

    private function normalizeCustomForwarderScript(string $script): string
    {
        $script = trim($script);
        if ($script === '') {
            return '';
        }

        return mb_substr($script, 0, 20000);
    }

    /**
     * @return string[]
     */
    private function normalizeList(string $value, bool $lowercase): array
    {
        if (trim($value) === '') {
            return [];
        }

        $items = preg_split('/[\r\n,]+/', $value) ?: [];
        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if ($lowercase) {
                $item = strtolower($item);
            }
            $normalized[$item] = mb_substr($item, 0, 160);
            if (\count($normalized) >= 100) {
                break;
            }
        }

        return array_values($normalized);
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        if (\is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || !\is_numeric($value)) {
            return $default;
        }

        return \max($min, \min($max, (int)$value));
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', ''], true)) {
                return false;
            }
        }
        return $default;
    }

    public function isAttributionBackfillDone(?string $scope = null, ?string $locale = null): bool
    {
        $map = $this->readConfigMap($scope, $locale);

        return $this->toBool($map[self::KEY_ATTRIBUTION_BACKFILL_DONE] ?? false, false);
    }

    /**
     * B13：旧 Cron 依据 PixelSource 反写 source 的兼容开关，默认关闭。
     */
    public function isLegacyCronSourceEnabled(?string $scope = null, ?string $locale = null): bool
    {
        $map = $this->readConfigMap($scope, $locale);

        return $this->toBool($map[self::KEY_LEGACY_CRON_SOURCE_ENABLED] ?? false, false);
    }

    /**
     * G10：热明细保留天数（Retention / 冷迁默认 before）。
     */
    public function getHotRetentionDays(?string $scope = null, ?string $locale = null): int
    {
        $map = $this->readConfigMap($scope, $locale);

        return $this->normalizeHotRetentionDays($map[self::KEY_RETENTION_HOT_DAYS] ?? self::DEFAULT_RETENTION_HOT_DAYS);
    }

    /**
     * G10：温聚合可查天数（须 ≥ 热保留）。
     */
    public function getWarmRetentionDays(?string $scope = null, ?string $locale = null): int
    {
        $map = $this->readConfigMap($scope, $locale);
        $hot = $this->normalizeHotRetentionDays($map[self::KEY_RETENTION_HOT_DAYS] ?? self::DEFAULT_RETENTION_HOT_DAYS);

        return $this->normalizeWarmRetentionDays(
            $map[self::KEY_RETENTION_WARM_DAYS] ?? self::DEFAULT_RETENTION_WARM_DAYS,
            $hot
        );
    }

    /**
     * G10：是否启用冷归档（关闭后不得迁冷/删热）。
     */
    public function isColdArchiveEnabled(?string $scope = null, ?string $locale = null): bool
    {
        $map = $this->readConfigMap($scope, $locale);

        return $this->toBool($map[self::KEY_COLD_ARCHIVE_ENABLED] ?? true, true);
    }

    /**
     * @param array<string, mixed> $map
     * @return array{
     *   hotDays: int,
     *   warmDays: int,
     *   coldArchiveEnabled: bool,
     *   source: string
     * }
     */
    private function buildRetentionRuntime(array $map): array
    {
        $hot = $this->normalizeHotRetentionDays($map[self::KEY_RETENTION_HOT_DAYS] ?? self::DEFAULT_RETENTION_HOT_DAYS);

        return [
            'hotDays' => $hot,
            'warmDays' => $this->normalizeWarmRetentionDays(
                $map[self::KEY_RETENTION_WARM_DAYS] ?? self::DEFAULT_RETENTION_WARM_DAYS,
                $hot
            ),
            'coldArchiveEnabled' => $this->toBool($map[self::KEY_COLD_ARCHIVE_ENABLED] ?? true, true),
            'source' => 'Weline_Visitor SystemConfig',
        ];
    }

    private function normalizeHotRetentionDays(mixed $value): int
    {
        // 最短 7 天（覆盖热短窗）；最长 10 年
        return $this->boundedInt($value, self::DEFAULT_RETENTION_HOT_DAYS, 7, 3650);
    }

    private function normalizeWarmRetentionDays(mixed $value, int $hotDays): int
    {
        $warm = $this->boundedInt($value, self::DEFAULT_RETENTION_WARM_DAYS, $hotDays, 7300);

        return max($hotDays, $warm);
    }

    public function setAttributionBackfillDone(bool $done, ?string $scope = null): bool
    {
        try {
            return $this->getConfigStore()->setScopedConfig(
                self::KEY_ATTRIBUTION_BACKFILL_DONE,
                $done ? '1' : '0',
                self::MODULE,
                SystemConfig::area_BACKEND,
                $this->normalizeScope($scope),
                SystemConfig::LOCALE_DEFAULT
            );
        } catch (\Throwable $throwable) {
            if (defined('DEV') && DEV) {
                w_log_error('写入 attribution_backfill_done 失败: ' . $throwable->getMessage());
            }

            return false;
        }
    }

    private function getSystemConfig(): SystemConfig
    {
        if (!$this->systemConfig) {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }

    private function getConfigStore(): \Weline\SystemConfig\Api\ConfigStore
    {
        if (!$this->configStore) {
            $this->configStore = ObjectManager::getInstance(\Weline\SystemConfig\Api\ConfigStore::class);
        }

        return $this->configStore;
    }
}
