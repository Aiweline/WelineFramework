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

    private function __construct()
    {
    }
}
