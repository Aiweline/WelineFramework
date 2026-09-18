<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Setup\Service\SetupSourceFingerprint;

/**
 * 静态错误页 publish 输入指纹（稳定、与 DictionaryCacheNamespace 进程代次解耦）。
 *
 * v2 前缀：站×语×主题 id/name×generated/language 文件×模板文件。
 */
final class StaticErrorPagePublishFingerprint
{
    public function __construct(
        private readonly SetupSourceFingerprint $fingerprints = new SetupSourceFingerprint(),
    ) {
    }

    public function fpKey404(string $websiteCode, string $lang): string
    {
        return 'static404:' . $websiteCode . ':' . $lang;
    }

    public function fpKeyMaintenance(string $websiteCode, string $lang): string
    {
        return 'static_maint:' . $websiteCode . ':' . $lang;
    }

    public function localeDictionaryToken(string $lang): string
    {
        $path = \rtrim((string)BP, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'language'
            . DIRECTORY_SEPARATOR . $lang . '.php';

        return $this->fingerprints->fingerprintFile($path);
    }

    /**
     * @param object|null $theme WelineTheme 或 duck-typed getId/getData
     */
    public function normalizeThemeToken(?object $theme): string
    {
        if ($theme === null || !\method_exists($theme, 'getId') || !(int)$theme->getId()) {
            return '0';
        }
        $name = '';
        $path = '';
        if (\method_exists($theme, 'getData')) {
            $name = \trim((string)$theme->getData('name'));
            $path = \trim((string)($theme->getData('path') ?? ''));
        }
        if ($path !== '' && \str_starts_with($path, (string)BP)) {
            $path = \ltrim(\str_replace('\\', '/', \substr($path, \strlen((string)BP))), '/');
        }

        return (string)(int)$theme->getId() . ':' . ($name !== '' ? $name : $path);
    }

    public function storefront404InputHash(
        string $websiteCode,
        string $lang,
        int $websiteId,
        string $themeToken,
        string $brandToken = '',
    ): string {
        return \hash(
            'sha256',
            // 404v6: strip client widgetTranslations / multi-locale bags from snapshots.
            '404v6|'
            . $websiteCode . '|'
            . $lang . '|'
            . $websiteId . '|'
            . $themeToken . '|'
            . $this->localeDictionaryToken($lang) . '|'
            . $this->notFoundLayoutToken() . '|'
            . $brandToken
        );
    }

    public function maintenanceInputHash(
        string $websiteCode,
        string $lang,
        int $websiteId,
        int $retryAfter,
        string $brandToken = '',
    ): string {
        return \hash(
            'sha256',
            'maintv3|'
            . $websiteCode . '|'
            . $lang . '|'
            . $websiteId . '|'
            . $retryAfter . '|'
            . $this->localeDictionaryToken($lang) . '|'
            . $this->maintenanceTemplateToken() . '|'
            . $brandToken
        );
    }

    public function shouldSkipTarget(string $fpKey, string $inputHash, string $primaryPath, ?string $secondaryPath = null): bool
    {
        if ($inputHash === '' || !$this->fingerprints->matches($fpKey, $inputHash)) {
            return false;
        }
        if (!\is_file($primaryPath)) {
            return false;
        }
        if ($secondaryPath !== null && !\is_file($secondaryPath)) {
            return false;
        }

        return true;
    }

    public function rememberTarget(string $fpKey, string $inputHash): void
    {
        if ($inputHash !== '') {
            $this->fingerprints->mergeUpdates([$fpKey => $inputHash]);
        }
    }

    /**
     * @param array<string, string> $updates
     */
    public function rememberMany(array $updates): void
    {
        if ($updates !== []) {
            $this->fingerprints->mergeUpdates($updates);
        }
    }

    private function notFoundLayoutToken(): string
    {
        $path = \rtrim((string)BP, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'code'
            . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Theme'
            . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'theme'
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'layouts'
            . DIRECTORY_SEPARATOR . 'not_found'
            . DIRECTORY_SEPARATOR . 'default.phtml';

        return $this->fingerprints->fingerprintFile($path);
    }

    private function maintenanceTemplateToken(): string
    {
        $paths = [
            BP . 'app/code/Weline/Maintenance/view/templates/maintenance.phtml',
            BP . 'app/code/Weline/Maintenance/view/templates/maintenance_api.json',
            BP . 'app/code/Weline/Maintenance/Service/MaintenanceContactEmailResolver.php',
        ];
        $parts = [];
        foreach ($paths as $path) {
            $parts[] = $this->fingerprints->fingerprintFile($path);
        }

        return \hash('sha256', \implode('|', $parts));
    }
}
