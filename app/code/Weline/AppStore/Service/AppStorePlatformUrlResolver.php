<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use Weline\Framework\App\Env;

class AppStorePlatformUrlResolver
{
    public const DEFAULT_PLATFORM_URL = 'https://app.aiweline.com';
    public const LOCAL_PLATFORM_URL = 'https://app.weline.test:9523';

    public function __construct(
        private readonly ?string $envFile = null,
        private readonly ?string $deployCurrentFile = null
    ) {
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    public function resolve(): array
    {
        if ($this->hasExplicitLocalDeployMode()) {
            return $this->resolveLocalPlatformUrl();
        }

        $deployed = $this->readProductionDeployPlatformUrl();
        if ($deployed['platform_url'] !== '') {
            return $deployed;
        }

        return [
            'platform_url' => self::DEFAULT_PLATFORM_URL,
            'source' => 'default:production',
            'environment' => 'production',
        ];
    }

    public function resolveUrl(): string
    {
        return $this->resolve()['platform_url'];
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function resolveLocalPlatformUrl(): array
    {
        $envPlatformUrl = getenv('WELINE_APPSTORE_PLATFORM_URL');
        $normalizedEnvPlatformUrl = is_string($envPlatformUrl) ? $this->normalizeLocalMarketplacePlatformUrl($envPlatformUrl) : '';
        if ($normalizedEnvPlatformUrl !== '') {
            return [
                'platform_url' => $normalizedEnvPlatformUrl,
                'source' => 'env:WELINE_APPSTORE_PLATFORM_URL',
                'environment' => 'local',
            ];
        }

        try {
            $platformUrl = Env::get('appstore.platform_url');
            $normalizedConfigPlatformUrl = is_string($platformUrl) ? $this->normalizeLocalMarketplacePlatformUrl($platformUrl) : '';
            if ($normalizedConfigPlatformUrl !== '') {
                return [
                    'platform_url' => $normalizedConfigPlatformUrl,
                    'source' => 'config:appstore.platform_url',
                    'environment' => 'local',
                ];
            }
        } catch (\Throwable) {
            // Keep marketplace endpoint resolution available before full framework bootstrap.
        }

        return [
            'platform_url' => self::LOCAL_PLATFORM_URL,
            'source' => 'local_default',
            'environment' => 'local',
        ];
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    private function readProductionDeployPlatformUrl(): array
    {
        $currentFile = $this->deployCurrentFile ?? (BP . 'var' . DS . 'deploy' . DS . 'current.json');
        if (!is_file($currentFile) || !is_readable($currentFile)) {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $json = ltrim((string)file_get_contents($currentFile), "\xEF\xBB\xBF");
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $environment = strtolower(trim((string)($payload['appstore_environment'] ?? '')));
        if ($environment !== 'production') {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        $platformUrl = $this->normalizeMarketplacePlatformUrl((string)($payload['appstore_platform_url'] ?? ''));
        $platformUrlSource = trim((string)($payload['appstore_platform_url_source'] ?? ''));
        if ($platformUrl !== self::DEFAULT_PLATFORM_URL || $platformUrlSource !== 'production_default') {
            return ['platform_url' => '', 'source' => '', 'environment' => ''];
        }

        return [
            'platform_url' => $platformUrl,
            'source' => 'deploy:var/deploy/current.json',
            'environment' => 'production',
        ];
    }

    private function normalizeMarketplacePlatformUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || $this->isOfficialWebsitePlatformUrl($url)) {
            return '';
        }

        return $url;
    }

    private function normalizeLocalMarketplacePlatformUrl(string $url): string
    {
        $url = $this->normalizeMarketplacePlatformUrl($url);
        if ($url !== self::LOCAL_PLATFORM_URL) {
            return '';
        }

        return $url;
    }

    private function isOfficialWebsitePlatformUrl(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return str_starts_with($host, 'www.')
            && (str_ends_with($host, 'weline.test') || str_ends_with($host, 'aiweline.com'));
    }

    private function hasExplicitLocalDeployMode(): bool
    {
        if ($this->envFile !== null) {
            $envFile = $this->envFile;
        } elseif (defined('APP_ETC_PATH')) {
            $envFile = APP_ETC_PATH . 'env.php';
        } elseif (defined('BP')) {
            $envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        } else {
            return false;
        }

        if (!is_file($envFile)) {
            return false;
        }

        try {
            $config = include $envFile;
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($config)) {
            return false;
        }

        $system = is_array($config['system'] ?? null) ? $config['system'] : [];
        foreach ([$system['deploy'] ?? null, $config['deploy'] ?? null] as $mode) {
            $mode = strtolower(trim((string)$mode));
            if ($mode === 'dev' || $mode === 'local') {
                return true;
            }
        }

        return false;
    }
}
