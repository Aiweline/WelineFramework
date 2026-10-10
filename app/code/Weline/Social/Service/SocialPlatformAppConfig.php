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

    public function scopeForWebsite(int $websiteId, string $websiteCode = ''): string
    {
        $websiteId = \max(0, $websiteId);
        $code = \strtolower(\trim($websiteCode));
        if ($websiteId === 0) {
            $code = 'default';
        } elseif ($code === '' || $code === 'default') {
            $code = 'id-' . $websiteId;
        }

        return $this->configReader->normalizeScope($code . '.default.default');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function scopeFromAccountContext(array $context): ?string
    {
        if (!\array_key_exists('website_id', $context)
            && \trim((string)($context['scope_code'] ?? $context['website_code'] ?? '')) === ''
            && \trim((string)($context['scope_type'] ?? '')) === ''
        ) {
            return null;
        }

        $websiteId = (int)($context['website_id'] ?? $context['scope_id'] ?? 0);
        $websiteCode = \trim((string)($context['scope_code'] ?? $context['website_code'] ?? ''));

        return $this->scopeForWebsite($websiteId, $websiteCode);
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

    /**
     * Website-scoped App credentials with explicit global fill for empty fields.
     *
     * @return array<string, string>
     */
    public function getPlatformAppForWebsite(string $platformCode, int $websiteId, string $websiteCode = ''): array
    {
        $websiteScope = $this->scopeForWebsite($websiteId, $websiteCode);
        $globalScope = $this->configReader->normalizeScope('default.default.default');
        $websiteApp = $this->getPlatformApp($platformCode, $websiteScope);
        if ($websiteScope === $globalScope) {
            return $websiteApp;
        }

        $globalApp = $this->getPlatformApp($platformCode, $globalScope);
        foreach ($globalApp as $field => $value) {
            $field = (string)$field;
            if (\trim((string)($websiteApp[$field] ?? '')) === '' && \trim((string)$value) !== '') {
                $websiteApp[$field] = \trim((string)$value);
            }
        }

        if (($websiteApp['graph_version'] ?? '') === '') {
            $websiteApp['graph_version'] = 'v21.0';
        }

        return $websiteApp;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function getPlatformAppFromContext(string $platformCode, array $context = []): array
    {
        if (\is_array($context['app_config'] ?? null) && $context['app_config'] !== []) {
            $app = [];
            foreach ($context['app_config'] as $field => $value) {
                $app[(string)$field] = \trim((string)$value);
            }
            if (($app['graph_version'] ?? '') === '') {
                $app['graph_version'] = 'v21.0';
            }

            return $app;
        }

        $scope = $this->scopeFromAccountContext($context);
        if ($scope === null) {
            return $this->getPlatformApp($platformCode);
        }

        $websiteId = (int)($context['website_id'] ?? $context['scope_id'] ?? 0);
        $websiteCode = \trim((string)($context['scope_code'] ?? $context['website_code'] ?? ''));

        return $this->getPlatformAppForWebsite($platformCode, $websiteId, $websiteCode);
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
