<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\SystemConfig\Api\ConfigReader;

class SocialPlatformAppConfig
{
    public const MODULE = 'Weline_Social';

    public function __construct(
        private readonly ConfigReader $configReader
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getPlatformApp(string $platformCode, ?string $scope = null): array
    {
        $platformCode = \strtolower(\trim($platformCode));
        $prefix = 'social/platform/' . $platformCode . '/';
        $map = $this->configReader->getConfigMapByModule(
            self::MODULE,
            ConfigReader::area_BACKEND,
            $scope,
            ConfigReader::LOCALE_DEFAULT
        );

        $app = [];
        foreach ($map as $key => $value) {
            $key = (string)$key;
            if (!\str_starts_with($key, $prefix)) {
                continue;
            }
            $field = \substr($key, \strlen($prefix));
            if ($field === '') {
                continue;
            }
            $app[$field] = \trim((string)$value);
        }

        // Meta family shared app (Instagram can fall back to Facebook app).
        if ($platformCode === 'instagram' && ($app['app_id'] ?? '') === '') {
            $facebook = $this->getPlatformApp('facebook', $scope);
            foreach (['app_id', 'app_secret', 'graph_version'] as $field) {
                if (($app[$field] ?? '') === '' && ($facebook[$field] ?? '') !== '') {
                    $app[$field] = $facebook[$field];
                }
            }
        }

        if (($app['graph_version'] ?? '') === '') {
            $app['graph_version'] = 'v21.0';
        }

        return $app;
    }

    public function get(string $platformCode, string $field, string $default = '', ?string $scope = null): string
    {
        $app = $this->getPlatformApp($platformCode, $scope);
        $value = \trim((string)($app[$field] ?? ''));

        return $value !== '' ? $value : $default;
    }

    public function hasRequired(string $platformCode, array $fields, ?string $scope = null): bool
    {
        $app = $this->getPlatformApp($platformCode, $scope);
        foreach ($fields as $field) {
            if (\trim((string)($app[(string)$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
