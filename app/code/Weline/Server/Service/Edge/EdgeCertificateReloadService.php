<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\App\Env;

/**
 * Whitelisted Nginx (or compatible) reload after WLS writes PEM under app/etc/ssl/.
 */
final class EdgeCertificateReloadService
{
    public const LAST_RESULT_RELATIVE = 'server/ssl_edge_reload_last.json';

    /**
     * @return array{
     *   attempted:bool,
     *   ok:bool,
     *   skipped:bool,
     *   reason:string,
     *   command:string,
     *   exit_code:int|null,
     *   stdout_tail:string,
     *   at:string
     * }
     */
    public function reloadAfterCertificateUpdate(string $domain = ''): array
    {
        $command = $this->configuredCommand();
        $timeout = $this->configuredTimeoutSec();
        $at = \date('c');

        if ($command === '') {
            $result = [
                'attempted' => false,
                'ok' => false,
                'skipped' => true,
                'reason' => 'wls.edge.reload_command is empty; PEM updated but edge was not reloaded',
                'command' => '',
                'exit_code' => null,
                'stdout_tail' => '',
                'at' => $at,
                'domain' => $domain,
            ];
            $this->persistLastResult($result);
            if (\function_exists('w_msg')) {
                w_msg(
                    'wls_edge_reload_unconfigured',
                    'warning',
                    __('WLS 边缘证书已更新但未配置 reload'),
                    __('证书 PEM 已写入，但 wls.edge.reload_command 为空，Nginx 可能仍使用旧证书。请配置 nginx -s reload 或 systemctl reload nginx。'),
                    ['domain' => $domain]
                );
            }
            return $result;
        }

        if (!$this->isCommandAllowed($command)) {
            $result = [
                'attempted' => false,
                'ok' => false,
                'skipped' => true,
                'reason' => 'reload command rejected by whitelist',
                'command' => $command,
                'exit_code' => null,
                'stdout_tail' => '',
                'at' => $at,
                'domain' => $domain,
            ];
            $this->persistLastResult($result);
            if (\function_exists('w_msg')) {
                w_msg(
                    'wls_edge_reload_rejected',
                    'error',
                    __('WLS 边缘 reload 命令被拒绝'),
                    __('配置的 wls.edge.reload_command 不在白名单内，已跳过执行。'),
                    ['command' => $command, 'domain' => $domain]
                );
            }
            return $result;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $result = [
                'attempted' => false,
                'ok' => false,
                'skipped' => true,
                'reason' => 'Windows edge reload is not auto-executed; configure and run reload manually if needed',
                'command' => $command,
                'exit_code' => null,
                'stdout_tail' => '',
                'at' => $at,
                'domain' => $domain,
            ];
            $this->persistLastResult($result);
            return $result;
        }

        $output = [];
        $exitCode = 1;
        @\exec($command . ' 2>&1', $output, $exitCode);
        $combined = \trim(\implode("\n", $output));
        $tail = \function_exists('mb_substr') ? \mb_substr($combined, -2000) : \substr($combined, -2000);

        $ok = $exitCode === 0;
        $result = [
            'attempted' => true,
            'ok' => $ok,
            'skipped' => false,
            'reason' => $ok ? 'edge reload succeeded' : 'edge reload failed',
            'command' => $command,
            'exit_code' => $exitCode,
            'stdout_tail' => $tail,
            'at' => $at,
            'domain' => $domain,
            'timeout_sec' => $timeout,
        ];
        $this->persistLastResult($result);
        if (!$ok && \function_exists('w_msg')) {
            w_msg(
                'wls_edge_reload_failed',
                'error',
                __('WLS 边缘 reload 失败'),
                __('证书 PEM 已更新，但边缘 reload 命令失败（exit=%{1}）。Nginx 可能仍使用旧证书。', [$exitCode]),
                ['command' => $command, 'domain' => $domain, 'exit_code' => $exitCode]
            );
        }

        return $result;
    }

    public function configuredCommand(): string
    {
        $env = Env::getInstance()->getConfig();
        if (!\is_array($env)) {
            return '';
        }
        $command = $env['wls']['edge']['reload_command'] ?? '';
        return \trim((string)$command);
    }

    public function configuredTimeoutSec(): int
    {
        $env = Env::getInstance()->getConfig();
        if (!\is_array($env)) {
            return 30;
        }
        $timeout = (int)($env['wls']['edge']['reload_timeout_sec'] ?? 30);
        return $timeout > 0 ? $timeout : 30;
    }

    public function isCommandAllowed(string $command): bool
    {
        $command = \trim($command);
        if ($command === '') {
            return false;
        }
        if (\preg_match('/[|><`$;&\n\r]/', $command) === 1) {
            return false;
        }
        if ($command === 'nginx -s reload' || $command === 'systemctl reload nginx') {
            return true;
        }
        // Absolute-path nginx binary: /usr/sbin/nginx -s reload
        if (\preg_match('#^/[^\s]+/nginx -s reload$#', $command) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function persistLastResult(array $result): void
    {
        $path = Env::VAR_DIR . self::LAST_RESULT_RELATIVE;
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents(
            $path,
            \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readLastResult(): ?array
    {
        $path = Env::VAR_DIR . self::LAST_RESULT_RELATIVE;
        if (!\is_file($path)) {
            return null;
        }
        $decoded = \json_decode((string)\file_get_contents($path), true);
        return \is_array($decoded) ? $decoded : null;
    }
}
