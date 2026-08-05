<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

final class ProviderTimeoutPolicy
{
    public const DEFAULT_REQUEST_TIMEOUT = 900;
    public const DEFAULT_IMAGE_GENERATION_TIMEOUT = 900;
    public const MIN_CONFIGURED_IMAGE_GENERATION_TIMEOUT = 120;
    public const EXECUTION_TIME_BUFFER = 10;
    public const DEFAULT_CONNECT_TIMEOUT = 60;
    public const DEFAULT_LOW_SPEED_TIME = 120;
    public const DEFAULT_STREAM_LOW_SPEED_TIME = 900;

    public static function resolveRequestTimeout(array $params, array $config): int
    {
        if (!empty($params['disable_ai_timeout']) || (PHP_SAPI === 'cli' && !empty($params['disable_cli_timeout']))) {
            return 0;
        }

        if (array_key_exists('timeout', $params)) {
            return max(0, (int)$params['timeout']);
        }

        if (array_key_exists('timeout', $config)) {
            return max(0, (int)$config['timeout']);
        }

        return self::DEFAULT_REQUEST_TIMEOUT;
    }

    public static function resolveStreamTimeout(array $params, array $config): int
    {
        if (empty($params['enforce_timeout_in_stream'])) {
            return 0;
        }

        return self::resolveRequestTimeout($params, $config);
    }

    public static function resolveImageGenerationTimeout(array $params, array $config): int
    {
        if (!empty($params['disable_ai_timeout']) || (PHP_SAPI === 'cli' && !empty($params['disable_cli_timeout']))) {
            return 0;
        }

        foreach (['image_timeout', 'timeout'] as $key) {
            if (array_key_exists($key, $params)) {
                return max(0, (int)$params[$key]);
            }
        }

        foreach (['image_timeout', 'timeout'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $timeout = (int)$config[$key];
            if ($timeout <= 0) {
                return 0;
            }

            return max(self::MIN_CONFIGURED_IMAGE_GENERATION_TIMEOUT, $timeout);
        }

        return self::DEFAULT_IMAGE_GENERATION_TIMEOUT;
    }

    public static function resolveExecutionTimeLimit(int $timeout, ?string $currentLimit = null): ?int
    {
        if (\trim((string)($currentLimit ?? @\ini_get('max_execution_time'))) === '0') {
            return null;
        }

        $timeout = \max(0, $timeout);

        return $timeout > 0
            ? $timeout + self::EXECUTION_TIME_BUFFER
            : 0;
    }

    public static function resolveLowSpeedTime(int $timeout): int
    {
        $timeout = \max(0, $timeout);
        if ($timeout <= 0) {
            return self::DEFAULT_LOW_SPEED_TIME;
        }

        return \min(self::DEFAULT_LOW_SPEED_TIME, \max(30, (int)\ceil($timeout / 4)));
    }

    /**
     * Long structured generations may legitimately return no response bytes
     * while the provider is still producing the first JSON token. Callers can
     * opt into a longer low-speed window without weakening the default policy
     * for every OpenAI-compatible request.
     *
     * @param array<string,mixed> $params
     */
    public static function resolveRequestLowSpeedTime(array $params, int $timeout): int
    {
        if (!\array_key_exists('low_speed_time', $params)) {
            return self::resolveLowSpeedTime($timeout);
        }

        $requested = \max(0, (int)$params['low_speed_time']);
        if ($requested === 0) {
            return 0;
        }

        $requested = \max(30, $requested);
        $timeout = \max(0, $timeout);

        return $timeout > 0 ? \min($requested, $timeout) : $requested;
    }

    public static function resolveStreamLowSpeedTime(array $params, int $timeout): int
    {
        if (!empty($params['disable_ai_timeout']) || (PHP_SAPI === 'cli' && !empty($params['disable_cli_timeout']))) {
            return 0;
        }

        $timeout = \max(0, $timeout);
        if (\array_key_exists('low_speed_time', $params)) {
            return self::resolveRequestLowSpeedTime($params, $timeout);
        }
        if ($timeout > 0) {
            return self::resolveLowSpeedTime($timeout);
        }

        return self::DEFAULT_STREAM_LOW_SPEED_TIME;
    }

    private function __construct()
    {
    }
}
