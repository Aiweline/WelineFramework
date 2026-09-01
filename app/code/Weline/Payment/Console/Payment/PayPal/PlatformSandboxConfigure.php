<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\PayPal;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PayPalPlatformCredentialService;
use Weline\Payment\Service\PaymentRedirectUriCatalog;

/**
 * 一次性写入 Weline 平台级 PayPal Sandbox REST App 凭据。
 *
 * 商户后台「沙箱一键授权」依赖这里的平台 App；配置完成后商户无需手填 Client ID/Secret。
 */
final class PlatformSandboxConfigure extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var PayPalPlatformCredentialService $credentials */
        $credentials = ObjectManager::getInstance(PayPalPlatformCredentialService::class);

        if ($this->hasHelpFlag($args)) {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        if ($this->hasStatusFlag($args)) {
            $ready = $credentials->hasPlatformSandboxCredentials();
            $platform = $credentials->getPlatformSandboxCredentials();
            $bundled = $credentials->loadBundledSandboxCredentials();
            $bundledReady = $credentials->isSandboxCredentialPairReady($bundled);
            /** @var PaymentRedirectUriCatalog $redirectCatalog */
            $redirectCatalog = ObjectManager::getInstance(PaymentRedirectUriCatalog::class);
            $printing->printing(json_encode([
                'ready' => $ready,
                'client_id_prefix' => $ready ? substr($platform['client_id'], 0, 8) . '...' : '',
                'local_file' => $credentials->getLocalCredentialsFilePath(),
                'bundled_file' => $credentials->getBundledCredentialsFilePath(),
                'bundled_ready' => $bundledReady,
                'use_bundled_fallback' => $credentials->shouldUseBundledSandboxCredentials(),
                'sandbox_redirect_uris' => $redirectCatalog->suggestedSandboxRedirectUris(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', $ready ? 'success' : 'warning');

            return $ready ? 'ok' : 'missing';
        }

        $clientId = trim((string) ($this->optionValue($args, 'client-id') ?? ''));
        $clientSecret = trim((string) ($this->optionValue($args, 'client-secret') ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            $printing->error((string) __(
                '请提供 --client-id 与 --client-secret。凭据来自 https://developer.paypal.com/dashboard/applications/sandbox 的 Sandbox REST App。'
            ));
            $printing->note((string) __(
                '示例：php bin/w payment:paypal:platform-sandbox-configure --client-id=AXxxx --client-secret=EYxxx'
            ));

            return 'failed';
        }

        try {
            $path = $credentials->writeLocalPlatformSandboxCredentials($clientId, $clientSecret);
        } catch (\Throwable $exception) {
            $printing->error($exception->getMessage());

            return 'failed';
        }

        $printing->success((string) __('平台 PayPal 沙箱凭据已写入：%{1}', [$path]));
        $printing->note((string) __(
            '现在回到配置中心，可在 Global 或 Website（含默认站点）点击「沙箱一键授权」跳转 PayPal Sandbox 登录。'
        ));

        return 'ok';
    }

    public function tip(): string
    {
        return (string) __('写入 Weline 平台级 PayPal Sandbox REST App 凭据（商户一键授权前置，一次性配置）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'payment:paypal:platform-sandbox-configure',
            $this->tip(),
            [
                '--client-id=' => (string) __('PayPal Developer Sandbox REST App Client ID'),
                '--client-secret=' => (string) __('PayPal Developer Sandbox REST App Secret'),
                '--status' => (string) __('仅检查平台沙箱凭据是否已就绪'),
                '-h, --help' => (string) __('显示本帮助'),
            ],
            [],
            [
                'php bin/w payment:paypal:platform-sandbox-configure --status',
                'php bin/w payment:paypal:platform-sandbox-configure --client-id=AXxxx --client-secret=EYxxx',
            ],
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasHelpFlag(array $args): bool
    {
        foreach ($args as $arg) {
            $candidate = strtolower(trim((string) $arg));
            if (in_array($candidate, ['help', '-h', '--help'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasStatusFlag(array $args): bool
    {
        foreach ($args as $arg) {
            if (in_array(strtolower(trim((string) $arg)), ['--status', 'status'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, $prefix)) {
                return trim(substr($arg, strlen($prefix)));
            }
        }

        return null;
    }
}
