<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\App\Env;

/**
 * 店面/Worker 观测响应头闸门：生产默认瘦身，开发/显式配置才放出调试头。
 *
 * 生产对外建议只保留：业务安全头、Set-Cookie（会话契约，非观测头）、
 * x-weline-request-id、x-weline-fpc / x-wls-fpc-status。
 */
final class ResponseObservabilityPolicy
{
    private static ?bool $identity = null;
    private static ?bool $performanceBreakdown = null;
    private static ?bool $dynamicObservability = null;
    private static ?bool $poweredBy = null;

    public static function clearCache(): void
    {
        self::$identity = null;
        self::$performanceBreakdown = null;
        self::$dynamicObservability = null;
        self::$poweredBy = null;
    }

    /** Worker-Id/Port/PID、Memory、Uptime、Request-Count、Instance */
    public static function identityHeadersEnabled(): bool
    {
        return self::$identity ??= self::resolveTriState(
            'wls.debug.identity_headers',
            self::isDeployDev() || self::isVerboseRuntime(),
        );
    }

    /**
     * 详细 X-WLS-Performance-*（含 UrlParser 分阶段）以及
     * X-WLS-Process-Time / Server-Timing / X-WLS-SSL-Engine。
     * 优先 wls.performance.response_headers_enabled；未配置时随 verbose。
     */
    public static function performanceBreakdownEnabled(): bool
    {
        if (self::$performanceBreakdown !== null) {
            return self::$performanceBreakdown;
        }

        $wls = Env::getInstance()->getConfig('wls') ?? [];
        $performance = \is_array($wls['performance'] ?? null) ? $wls['performance'] : [];
        if (\array_key_exists('response_headers_enabled', $performance)) {
            return self::$performanceBreakdown = (bool)$performance['response_headers_enabled'];
        }

        return self::$performanceBreakdown = self::isVerboseRuntime();
    }

    /**
     * Process-Time / Server-Timing：与 performanceBreakdown 同闸（生产默认关）。
     */
    public static function processTimingHeadersEnabled(): bool
    {
        return self::performanceBreakdownEnabled();
    }

    /**
     * First-Render / Warmup / Controller-Cache 等动态观测头。
     * 默认：仅 deploy=dev；可用 wls.worker.dynamic_observability_headers_enabled 显式开关。
     */
    public static function dynamicObservabilityEnabled(): bool
    {
        return self::$dynamicObservability ??= self::resolveTriState(
            'wls.worker.dynamic_observability_headers_enabled',
            self::isDeployDev(),
        );
    }

    /** X-Powered-By：生产默认不暴露栈版本 */
    public static function poweredByHeaderEnabled(): bool
    {
        return self::$poweredBy ??= self::resolveTriState(
            'wls.debug.powered_by_header',
            self::isDeployDev(),
        );
    }

    public static function isDeployDev(): bool
    {
        if (\defined('DEV') && DEV) {
            return true;
        }
        try {
            $deploy = \strtolower(\trim((string)(Env::getInstance()->getConfig('deploy') ?? '')));

            return $deploy === 'dev' || $deploy === 'development';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isVerboseRuntime(): bool
    {
        if (\defined('WLS_VERBOSE_LOG')) {
            return (bool)WLS_VERBOSE_LOG;
        }
        try {
            $wls = Env::getInstance()->getConfig('wls') ?? [];
            $log = \is_array($wls['log'] ?? null) ? $wls['log'] : [];

            return (bool)($log['verbose'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * env 显式 true/false 覆盖；缺省用 $defaultWhenUnset。
     * 兼容历史字符串 '0'/'1'/'true'/'false'。
     */
    private static function resolveTriState(string $envKey, bool $defaultWhenUnset): bool
    {
        try {
            $raw = Env::get($envKey, null);
        } catch (\Throwable) {
            $raw = null;
        }
        if ($raw === null || $raw === '') {
            return $defaultWhenUnset;
        }
        if (\is_bool($raw)) {
            return $raw;
        }
        $normalized = \strtolower(\trim((string)$raw));

        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
