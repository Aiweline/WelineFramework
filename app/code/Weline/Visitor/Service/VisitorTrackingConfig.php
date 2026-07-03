<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

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
    private const KEY_HOT_BUFFER_ENABLED = 'visitor/tracking/hot_buffer_enabled';
    private const KEY_HOT_BUFFER_FLUSH_INTERVAL = 'visitor/tracking/hot_buffer_flush_interval';
    private const KEY_HOT_BUFFER_BATCH_SIZE = 'visitor/tracking/hot_buffer_batch_size';
    private const KEY_HOT_BUFFER_TTL = 'visitor/tracking/hot_buffer_ttl';
    private const KEY_CONSENT_MODE_ENABLED = 'visitor/tracking/consent_mode_enabled';
    private const KEY_EXCLUDE_LOCAL_FORWARDING = 'visitor/tracking/exclude_local_forwarding';
    private const KEY_EXCLUDED_HOSTS = 'visitor/tracking/excluded_hosts';
    private const KEY_EXCLUDED_PATH_PREFIXES = 'visitor/tracking/excluded_path_prefixes';
    private const KEY_EXCLUDED_QUERY_KEYS = 'visitor/tracking/excluded_query_keys';
    private const KEY_EXCLUDED_REFERRER_HOSTS = 'visitor/tracking/excluded_referrer_hosts';
    private const KEY_EXCLUDED_USER_AGENT_KEYWORDS = 'visitor/tracking/excluded_user_agent_keywords';
    private const KEY_CUSTOM_FORWARDER_ENABLED = 'visitor/tracking/custom_forwarder_js_enabled';
    private const KEY_CUSTOM_FORWARDER_JS = 'visitor/tracking/custom_forwarder_js';

    public function __construct(
        private ?SystemConfig $systemConfig = null
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

        return [
            'module' => self::MODULE,
            'area' => SystemConfig::area_BACKEND,
            'scope' => $this->normalizeScope($scope),
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
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'trafficRules' => [
                'source' => 'Weline_Visitor SystemConfig',
                'excludeLocalForwarding' => $this->toBool($map[self::KEY_EXCLUDE_LOCAL_FORWARDING] ?? true, true),
                'excludedHosts' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_HOSTS] ?? ''), true),
                'excludedPathPrefixes' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_PATH_PREFIXES] ?? ''), false),
                'excludedQueryKeys' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_QUERY_KEYS] ?? ''), true),
                'excludedReferrerHosts' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_REFERRER_HOSTS] ?? ''), true),
                'excludedUserAgentKeywords' => $this->normalizeList((string)($map[self::KEY_EXCLUDED_USER_AGENT_KEYWORDS] ?? ''), true),
            ],
            'ga4' => [
                'enabled' => $ga4SwitchEnabled && $measurementId !== '',
                'configured' => $measurementId !== '',
                'measurementId' => $measurementId,
                'enableInDev' => $this->toBool($map[self::KEY_GA4_ENABLE_IN_DEV] ?? false, false),
                'autoTrackVisitorEvents' => $this->toBool($map[self::KEY_GA4_AUTO_TRACK_VISITOR_EVENTS] ?? true, true),
                'ctaEventName' => $this->normalizeEventName((string)($map[self::KEY_GA4_CTA_EVENT_NAME] ?? 'cta_click')),
                'debugMode' => $this->toBool($map[self::KEY_GA4_DEBUG_MODE] ?? false, false),
                'source' => 'Weline_Visitor SystemConfig',
            ],
            'forwarders' => [
                'eventBus' => [
                    'enabled' => true,
                    'contractVersion' => 'weline-visitor-event/v1',
                ],
                'ga4' => [
                    'enabled' => $ga4SwitchEnabled && $measurementId !== '',
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

    private function getSystemConfig(): SystemConfig
    {
        if (!$this->systemConfig) {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }
}
