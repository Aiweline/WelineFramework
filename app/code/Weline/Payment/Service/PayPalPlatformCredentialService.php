<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Weline 平台级 PayPal 沙箱 REST 应用凭据。
 *
 * 沙箱一键授权会使用这里的平台 App，自动写入各站点 scope 配置，
 * 商户无需自行去 PayPal Developer Dashboard 创建 REST App。
 */
final class PayPalPlatformCredentialService
{
    public const MODULE = PayPalOAuthService::MODULE;
    public const AREA = PayPalOAuthService::AREA;
    private const CONFIG_PREFIX = 'payment/method/paypal/';
    private const SOURCE_PLATFORM = 'platform';

    public function __construct(
        private readonly ConfigStore $store,
    ) {
    }

    /**
     * 沙箱一键授权前自动写入平台 REST App 凭据（若当前 scope 尚未配置）。
     *
     * @return array{client_id:string,client_secret:string,source:string,provisioned:bool,pkce:bool}
     */
    public function ensureSandboxProvisioned(?string $scope = null): array
    {
        (new PayPalSandboxBootstrapService($this))->bootstrapIfNeeded();

        $existingId = $this->readScopedConfig('sandbox_client_id', $scope);
        $existingSecret = $this->readScopedConfig('sandbox_client_secret', $scope);
        if ($existingId !== '' && ($existingSecret !== '' || $this->supportsSandboxPkceOnly($existingId, $existingSecret))) {
            return [
                'client_id' => $existingId,
                'client_secret' => $existingSecret,
                'source' => $this->readScopedConfig('sandbox_credentials_source', $scope) ?: 'custom',
                'provisioned' => false,
                'pkce' => $existingSecret === '',
            ];
        }

        $platform = $this->getPlatformSandboxCredentials();
        if (!$this->isSandboxCredentialPairReady($platform)) {
            throw new \RuntimeException($this->sandboxNotReadyMessage());
        }

        $this->writeScopedConfig('sandbox_client_id', $platform['client_id'], $scope, false);
        if ($platform['client_secret'] !== '') {
            $this->writeScopedConfig('sandbox_client_secret', $platform['client_secret'], $scope, true);
        }
        $this->writeScopedConfig('sandbox_credentials_source', self::SOURCE_PLATFORM, $scope, false);
        $this->writeScopedConfig('sandbox_credentials_provisioned_at', date('Y-m-d H:i:s'), $scope, false);

        return [
            'client_id' => $platform['client_id'],
            'client_secret' => $platform['client_secret'],
            'source' => self::SOURCE_PLATFORM,
            'provisioned' => true,
            'pkce' => $platform['client_secret'] === '',
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string}
     */
    public function getPlatformSandboxCredentials(): array
    {
        $fromEnv = Env::get('payment.paypal.platform.sandbox', []);
        if (\is_array($fromEnv)) {
            $clientId = trim((string) ($fromEnv['client_id'] ?? ''));
            $clientSecret = trim((string) ($fromEnv['client_secret'] ?? ''));
            if ($this->isSandboxCredentialPairReady(['client_id' => $clientId, 'client_secret' => $clientSecret])) {
                return ['client_id' => $clientId, 'client_secret' => $clientSecret];
            }
        }

        $localFile = $this->getLocalCredentialsFilePath();
        if (is_file($localFile)) {
            $data = require $localFile;
            if (\is_array($data)) {
                $clientId = trim((string) ($data['client_id'] ?? ''));
                $clientSecret = trim((string) ($data['client_secret'] ?? ''));
                if ($this->isSandboxCredentialPairReady(['client_id' => $clientId, 'client_secret' => $clientSecret])) {
                    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
                }
            }
        }

        $clientId = trim((string) getenv('WELINE_PAYPAL_SANDBOX_CLIENT_ID'));
        $clientSecret = trim((string) getenv('WELINE_PAYPAL_SANDBOX_CLIENT_SECRET'));
        if ($this->isSandboxCredentialPairReady(['client_id' => $clientId, 'client_secret' => $clientSecret])) {
            return ['client_id' => $clientId, 'client_secret' => $clientSecret];
        }

        $platformFile = dirname(__DIR__) . '/etc/platform/paypal.sandbox.php';
        if (is_file($platformFile)) {
            $data = require $platformFile;
            if (\is_array($data)) {
                $clientId = trim((string) ($data['client_id'] ?? ''));
                $clientSecret = trim((string) ($data['client_secret'] ?? ''));
                if ($this->isSandboxCredentialPairReady(['client_id' => $clientId, 'client_secret' => $clientSecret])) {
                    return ['client_id' => $clientId, 'client_secret' => $clientSecret];
                }
            }
        }

        if ($this->shouldUseBundledSandboxCredentials()) {
            $bundled = $this->loadBundledSandboxCredentials();
            if ($this->isSandboxCredentialPairReady($bundled)) {
                return $bundled;
            }
        }

        return ['client_id' => '', 'client_secret' => ''];
    }

    /**
     * @param array{client_id?:string,client_secret?:string} $credentials
     */
    public function isSandboxCredentialPairReady(array $credentials): bool
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        if ($clientId === '') {
            return false;
        }

        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));

        return $clientSecret !== '' || $this->supportsSandboxPkceOnly($clientId, $clientSecret);
    }

    public function supportsSandboxPkceOnly(string $clientId, string $clientSecret = ''): bool
    {
        return trim($clientId) !== '' && trim($clientSecret) === '';
    }

    public function getBundledCredentialsFilePath(): string
    {
        return dirname(__DIR__) . '/etc/platform/paypal.sandbox.bundled.php';
    }

    /**
     * @return array{client_id:string,client_secret:string}
     */
    public function loadBundledSandboxCredentials(): array
    {
        $bundledFile = $this->getBundledCredentialsFilePath();
        if (!is_file($bundledFile)) {
            return ['client_id' => '', 'client_secret' => ''];
        }

        $data = require $bundledFile;
        if (!\is_array($data)) {
            return ['client_id' => '', 'client_secret' => ''];
        }

        return [
            'client_id' => trim((string) ($data['client_id'] ?? '')),
            'client_secret' => trim((string) ($data['client_secret'] ?? '')),
        ];
    }

    public function shouldUseBundledSandboxCredentials(): bool
    {
        $systemEnv = strtolower(trim((string) Env::get('system.env', '')));
        $deploy = strtolower(trim((string) Env::get('deploy', '')));
        if ($deploy === '') {
            $deploy = strtolower(trim((string) Env::get('system.deploy', '')));
        }

        if (\in_array($systemEnv, ['local', 'dev', 'development'], true)) {
            return true;
        }

        return \in_array($deploy, ['dev', 'development', 'local'], true);
    }

    private function sandboxNotReadyMessage(): string
    {
        return (string) __(
            '暂时无法连接 PayPal 沙箱，请稍后重试。若问题持续，请联系站点管理员。'
        );
    }

    public function hasPlatformSandboxCredentials(): bool
    {
        return $this->isSandboxCredentialPairReady($this->getPlatformSandboxCredentials());
    }

    public function getLocalCredentialsFilePath(): string
    {
        return dirname(__DIR__, 4) . '/etc/payment/paypal.platform.sandbox.php';
    }

    /**
     * 写入本地平台沙箱凭据文件（勿提交 Git）。
     */
    public function writeLocalPlatformSandboxCredentials(string $clientId, string $clientSecret): string
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if (!$this->isSandboxCredentialPairReady(['client_id' => $clientId, 'client_secret' => $clientSecret])) {
            throw new \InvalidArgumentException((string) __('PayPal 沙箱 Client ID 不能为空。'));
        }

        $target = $this->getLocalCredentialsFilePath();
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException((string) __('无法创建目录：%{1}', [$directory]));
        }

        $contents = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * 本地 PayPal Sandbox 平台 REST App 凭据（勿提交 Git）。
 * 由框架 bundled 凭据或 payment:paypal:platform-sandbox-configure 生成。
 */
return [
    'client_id' => '%s',
    'client_secret' => '%s',
];

PHP;
        $payload = sprintf(
            $contents,
            addcslashes($clientId, "'\\"),
            addcslashes($clientSecret, "'\\"),
        );
        if (file_put_contents($target, $payload) === false) {
            throw new \RuntimeException((string) __('写入失败：%{1}', [$target]));
        }

        return $target;
    }

    private function readScopedConfig(string $suffix, ?string $scope): string
    {
        // 平台/商户凭据与 UI 语言无关，固定读 default locale。
        $value = $this->store->getConfig(
            self::CONFIG_PREFIX . $suffix,
            self::MODULE,
            self::AREA,
            null,
            $scope,
            SystemConfig::LOCALE_DEFAULT
        );

        return trim((string) $value);
    }

    private function writeScopedConfig(string $suffix, string $value, ?string $scope, bool $sensitive): void
    {
        $this->store->setScopedConfig(
            self::CONFIG_PREFIX . $suffix,
            $value,
            self::MODULE,
            self::AREA,
            $scope,
            SystemConfig::LOCALE_DEFAULT,
            $sensitive
                ? ['is_sensitive' => true, 'reason' => 'paypal_platform_provision']
                : ['reason' => 'paypal_platform_provision'],
        );
    }
}
