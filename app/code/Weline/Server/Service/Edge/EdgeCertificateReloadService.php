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

    public function __construct()
    {
        throw new \RuntimeException(
            'External Nginx reload commands are retired; use the project-managed Nginx lifecycle.'
        );
    }

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
        throw new \RuntimeException(
            'External Nginx reload commands are retired; use the WLS 2.0 certificate publication and exact Worker acknowledgement path.'
        );
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
