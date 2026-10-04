<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Setup\Service\SetupSourceFingerprint;

/**
 * 维护页 publish 输入指纹（稳定、与 DictionaryCacheNamespace 进程代次解耦）。
 *
 * 404 发布重新渲染完整依赖，再按最终内容判断是否写盘；不使用此处输入指纹。
 */
final class StaticErrorPagePublishFingerprint
{
    public function __construct(
        private readonly SetupSourceFingerprint $fingerprints = new SetupSourceFingerprint(),
    ) {
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
