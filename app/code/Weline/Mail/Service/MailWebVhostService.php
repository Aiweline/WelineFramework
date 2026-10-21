<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class MailWebVhostService
{
    private MailWebVhostRenderer $renderer;

    /** @var callable(string):array */
    private $certResolver;

    /** @var callable(string):array */
    private $reload;

    private string $confDir;

    private string $nginxConf;

    public function __construct(
        ?MailWebVhostRenderer $renderer = null,
        mixed $certResolver = null,
        mixed $reload = null,
        ?string $confDir = null,
        ?string $nginxConf = null
    ) {
        $this->renderer = $renderer ?? new MailWebVhostRenderer();
        $this->certResolver = $certResolver ?? static fn (string $hostname): array => (array) w_query(
            'server',
            'resolveManagedCertificate',
            ['hostname' => $hostname]
        );
        $this->reload = $reload ?? static function (string $confDir): array {
            $service = ManagedNginxService::fromEnv();
            if ($service->runtimeRunningSnapshot()['running'] === false) {
                return ['ok' => true, 'message' => 'nginx not running'];
            }

            return $service->reload();
        };
        $this->confDir = $confDir ?? ManagedNginxService::fromEnv()->paths()->confDir() . DIRECTORY_SEPARATOR . 'conf.d';
        $this->nginxConf = $nginxConf ?? dirname($this->confDir) . DIRECTORY_SEPARATOR . 'nginx.conf';
    }

    public function ensureForHostname(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $hostname) || !str_contains($hostname, '.')) {
            return ['status' => 'error', 'message' => 'hostname invalid'];
        }

        try {
            $certificate = (array) ($this->certResolver)($hostname);
        } catch (\Throwable $throwable) {
            return ['status' => 'error', 'message' => $throwable->getMessage()];
        }

        $certPath = (string) ($certificate['cert_path'] ?? '');
        $keyPath = (string) ($certificate['key_path'] ?? '');
        if (($certificate['valid'] ?? false) !== true
            || ($certificate['covers_hostname'] ?? false) !== true
            || $certPath === ''
            || $keyPath === ''
            || !is_readable($certPath)
            || !is_readable($keyPath)
        ) {
            return ['status' => 'needs_cert', 'message' => 'certificate not ready'];
        }

        if (!is_dir($this->confDir) && !mkdir($this->confDir, 0775, true)) {
            return ['status' => 'error', 'message' => 'write failed'];
        }

        $port = 443;
        if (is_readable($this->nginxConf)) {
            $port = $this->renderer->listenPortFromNginxConf((string) file_get_contents($this->nginxConf));
        }

        $content = $this->renderer->render($hostname, $certPath, $keyPath, $port);
        $file = $this->confDir . '/' . $this->renderer->fileName($hostname);
        if (is_file($file) && file_get_contents($file) === $content) {
            return ['status' => 'unchanged', 'path' => $file];
        }

        $previous = is_file($file) ? (string) file_get_contents($file) : null;
        $tmpFile = $file . '.tmp';
        if (file_put_contents($tmpFile, $content) === false || !rename($tmpFile, $file)) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }

            return ['status' => 'error', 'message' => 'write failed'];
        }

        $reload = (array) ($this->reload)($this->confDir);
        if (($reload['ok'] ?? false) !== true) {
            if ($previous === null) {
                @unlink($file);
            } else {
                @file_put_contents($file, $previous);
            }
            ($this->reload)($this->confDir);

            return ['status' => 'error', 'message' => (string) ($reload['message'] ?? 'reload failed')];
        }

        return ['status' => 'ready', 'path' => $file, 'reloaded' => true];
    }

    public function removeForHostname(string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $hostname) || !str_contains($hostname, '.')) {
            return ['status' => 'error', 'message' => 'hostname invalid'];
        }

        $file = $this->confDir . '/' . $this->renderer->fileName($hostname);
        if (!is_file($file)) {
            return ['status' => 'unchanged'];
        }

        if (!@unlink($file)) {
            return ['status' => 'error', 'message' => 'remove failed'];
        }

        $reload = (array) ($this->reload)($this->confDir);
        if (($reload['ok'] ?? false) !== true) {
            return ['status' => 'error', 'message' => (string) ($reload['message'] ?? 'reload failed')];
        }

        return ['status' => 'removed', 'path' => $file];
    }
}
