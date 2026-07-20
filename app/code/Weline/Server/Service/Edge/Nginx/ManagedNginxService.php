<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;
use Weline\Server\Service\Edge\EdgeAdapterInterface;
use Weline\Server\Service\Edge\EdgeAdapterResolver;

/**
 * Facade for per-project managed nginx lifecycle used by CLI and server:start/stop.
 */
final class ManagedNginxService
{
    public function __construct(
        private readonly ManagedNginxPaths $paths = new ManagedNginxPaths(),
        private readonly ManagedNginxInstaller $installer = new ManagedNginxInstaller(),
        private readonly ManagedNginxConfigWriter $configWriter = new ManagedNginxConfigWriter(),
        private readonly ManagedNginxProcessManager $processManager = new ManagedNginxProcessManager(),
        private readonly ManagedNginxPortAllocator $portAllocator = new ManagedNginxPortAllocator(),
    ) {
    }

    public function isEdgeNginxManaged(): bool
    {
        $adapter = (new EdgeAdapterResolver())->resolve();
        return $adapter->name() === EdgeAdapterInterface::NAME_NGINX && $this->paths->managedEnabled();
    }

    public function paths(): ManagedNginxPaths
    {
        return $this->paths;
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>}
     */
    public function install(bool $force = false): array
    {
        return $this->installer->ensureInstalled($force);
    }

    /**
     * Write conf for upstream WLS port and start nginx.
     *
     * @param list<string> $serverNames
     * @return array{ok:bool,message:string,details?:array<string,mixed>}
     */
    public function prepareAndStart(int $upstreamPort, string $upstreamHost = '127.0.0.1', array $serverNames = []): array
    {
        if (!$this->isEdgeNginxManaged()) {
            return ['ok' => true, 'message' => 'managed nginx skipped (edge adapter not nginx or managed=false)'];
        }
        if (!$this->paths->isInstalled()) {
            $installed = $this->install(false);
            if (!($installed['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'managed nginx missing and auto-install failed: '
                        . (string)($installed['message'] ?? 'unknown error')
                        . ' (platforms: Darwin/Linux source build, Windows zip)',
                ];
            }
        }
        try {
            $written = $this->configWriter->write($upstreamPort, $upstreamHost, $serverNames);
            $status = $this->processManager->status();
            if ($status['running']) {
                $reloaded = $this->processManager->reload();
                if (!($reloaded['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'message' => 'managed nginx conf written but reload failed: '
                            . (string)($reloaded['message'] ?? 'unknown'),
                        'details' => [
                            'listen_http' => $written['http'],
                            'listen_https' => $written['https'],
                            'upstream' => $written['upstream'],
                            'conf' => $written['conf'],
                            'pid' => $status['pid'] ?? null,
                            'ssl' => $written['ssl'] ?? false,
                        ],
                    ];
                }
                return [
                    'ok' => true,
                    'message' => 'managed nginx conf reloaded',
                    'details' => [
                        'listen_http' => $written['http'],
                        'listen_https' => $written['https'],
                        'upstream' => $written['upstream'],
                        'conf' => $written['conf'],
                        'pid' => $status['pid'] ?? null,
                        'ssl' => $written['ssl'] ?? false,
                    ],
                ];
            }
            $started = $this->processManager->start();
            return [
                'ok' => (bool)$started['ok'],
                'message' => (string)$started['message'],
                'details' => [
                    'listen_http' => $written['http'],
                    'listen_https' => $written['https'],
                    'upstream' => $written['upstream'],
                    'conf' => $written['conf'],
                    'pid' => $started['pid'] ?? null,
                    'ssl' => $written['ssl'] ?? false,
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function stop(): array
    {
        if (!$this->paths->managedEnabled()) {
            return ['ok' => true, 'message' => 'managed nginx disabled'];
        }
        return $this->processManager->stop();
    }

    /**
     * @return array{ok:bool,message:string,exit_code?:int|null}
     */
    public function reload(): array
    {
        return $this->processManager->reload();
    }

    /**
     * @return array<string,mixed>
     */
    public function doctorSnapshot(): array
    {
        $ports = $this->portAllocator->allocate();
        $status = $this->processManager->status();
        $hostBinary = $this->paths->detectHostNginxBinary();
        return [
            'managed' => $this->paths->managedEnabled(),
            'managed_mode' => $this->paths->managedMode(),
            'host_nginx_detected' => $hostBinary !== null,
            'host_nginx_binary' => $hostBinary,
            'auto_start' => $this->paths->autoStartEnabled(),
            'installed' => $this->paths->isInstalled(),
            'binary' => $this->paths->binary(),
            'install_root' => $this->paths->installRoot(),
            'runtime_root' => $this->paths->runtimeRoot(),
            'conf' => $this->paths->confFile(),
            'listen_http' => $ports['http'],
            'listen_https' => $ports['https'],
            'port_source' => $ports['source'],
            'project_offset' => $ports['offset'],
            'running' => $status['running'],
            'pid' => $status['pid'],
            'edge_cache' => $this->paths->edgeCacheEnabled(),
            'edge_cache_ttl_sec' => $this->paths->edgeCacheTtlSec(),
            'gzip' => $this->paths->gzipEnabled(),
            'upstream_keepalive' => $this->paths->upstreamKeepalive(),
            'worker_connections' => $this->paths->workerConnections(),
            'manifest' => $this->readManifest(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readManifest(): ?array
    {
        $file = $this->paths->manifestFile();
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)\file_get_contents($file), true);
        return \is_array($decoded) ? $decoded : null;
    }

    public static function fromEnv(): self
    {
        $env = Env::getInstance()->getConfig();
        $nginxCfg = \is_array($env) && \is_array($env['wls']['edge']['nginx'] ?? null)
            ? $env['wls']['edge']['nginx']
            : [];
        $paths = new ManagedNginxPaths(null, $nginxCfg);
        return new self(
            $paths,
            new ManagedNginxInstaller($paths),
            new ManagedNginxConfigWriter($paths),
            new ManagedNginxProcessManager($paths),
            new ManagedNginxPortAllocator($paths),
        );
    }
}
