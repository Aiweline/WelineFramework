<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SslCertificateService;

/**
 * Builds a complete project-owned desired-state registration.
 */
final class GatewayRegistrationBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(string $instanceName): array
    {
        /** @var ServerInstanceManager $instances */
        $instances = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $instances->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            throw new \RuntimeException('WLS instance endpoint is missing: ' . $instanceName);
        }
        $port = (int)($endpoint['main_port'] ?? $endpoint['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('WLS instance endpoint has no valid backend port.');
        }
        $host = \trim((string)($endpoint['host'] ?? '127.0.0.1'));
        if (!\in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new \RuntimeException('Gateway backend must be a loopback WLS endpoint.');
        }

        $projectRoot = \realpath((string)BP);
        if (!\is_string($projectRoot) || $projectRoot === '') {
            throw new \RuntimeException('Unable to resolve canonical project root.');
        }
        $projectUuid = $this->projectUuid();
        $certificateMap = [];
        try {
            /** @var SslCertificateService $certificates */
            $certificates = ObjectManager::getInstance(SslCertificateService::class);
            $certificateMap = $certificates->getCertificateMap();
        } catch (\Throwable) {
            // The endpoint certificate remains a valid file-mode source when
            // storage is unavailable during early recovery.
        }

        $publicHost = \trim((string)($endpoint['public_host'] ?? ''));
        $endpointCert = \trim((string)($endpoint['ssl_cert'] ?? ''));
        $endpointKey = \trim((string)($endpoint['ssl_key'] ?? ''));
        $gatewayCertificate = \is_array($endpoint['gateway']['certificate_source'] ?? null)
            ? $endpoint['gateway']['certificate_source']
            : [];
        if ($endpointCert === '') {
            $endpointCert = \trim((string)($gatewayCertificate['cert_path'] ?? ''));
        }
        if ($endpointKey === '') {
            $endpointKey = \trim((string)($gatewayCertificate['key_path'] ?? ''));
        }
        if ($publicHost === '') {
            $publicHost = \trim((string)($gatewayCertificate['domain'] ?? ''));
        }
        if ($publicHost !== '' && $endpointCert !== '' && $endpointKey !== '') {
            $certificateMap[$publicHost] ??= [
                'cert' => $endpointCert,
                'key' => $endpointKey,
                'chain' => '',
                'cert_type' => \str_starts_with($publicHost, '*.') ? 'wildcard' : 'exact',
                'force_https' => 1,
            ];
        }
        if ($certificateMap === []) {
            throw new \RuntimeException('No project-owned certificate is available for gateway registration.');
        }

        $backendIdentity = [
            'project_uuid' => $projectUuid,
            'instance_id' => $instanceName,
            'generation' => \max(
                1,
                (int)($endpoint['started_timestamp'] ?? 0),
                (int)($endpoint['startup_event_seq'] ?? 0),
            ),
            'endpoint_file' => $this->endpointFile($instanceName),
            'master_pid' => (int)($endpoint['master_pid'] ?? 0),
        ];
        $backendIdentity['digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($backendIdentity),
        );

        $routes = [];
        foreach ($certificateMap as $domain => $material) {
            if (!\is_string($domain) || !\is_array($material)) {
                continue;
            }
            $domain = \strtolower(\rtrim(\trim($domain), '.'));
            $cert = \trim((string)($material['cert'] ?? ''));
            $key = \trim((string)($material['key'] ?? ''));
            if ($domain === '' || $cert === '' || $key === '') {
                continue;
            }
            $certificateGeneration = $this->certificateGeneration($cert, $key);
            $routes[] = [
                'route_id' => \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
                'domain' => $domain,
                'backends' => [[
                    'host' => $host === 'localhost' ? '127.0.0.1' : $host,
                    'port' => $port,
                    'weight' => 1,
                ]],
                'backend_identity' => $backendIdentity,
                'certificate' => [
                    'cert_path' => $cert,
                    'key_path' => $key,
                    'chain_path' => \trim((string)($material['chain'] ?? '')),
                    'source_digest' => $certificateGeneration,
                    'generation' => $certificateGeneration,
                ],
                'force_https' => (bool)($material['force_https'] ?? true),
            ];
        }
        if ($routes === []) {
            throw new \RuntimeException('No valid project route can be built for gateway registration.');
        }
        \usort($routes, static fn (array $a, array $b): int => (string)$a['domain'] <=> (string)$b['domain']);

        $desired = [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'instance_id' => $instanceName,
            'peer_identity' => [
                'pid' => \getmypid(),
                'uid' => \function_exists('posix_geteuid') ? \posix_geteuid() : null,
                'gid' => \function_exists('posix_getegid') ? \posix_getegid() : null,
                'os_family' => \PHP_OS_FAMILY,
            ],
            'gateway_epoch' => '',
            'routes' => $routes,
            'certificate_roots' => $this->certificateRoots($projectRoot),
        ];
        $digest = \hash('sha256', GatewayClient::canonicalJson($desired));
        [$generation, $idempotencyKey] = $this->resolveGeneration($digest);
        $desired['project_generation'] = $generation;
        $desired['request_digest'] = $digest;
        $desired['idempotency_key'] = $idempotencyKey;
        return $desired;
    }

    public function projectUuid(): string
    {
        $hex = \substr(\hash('sha256', 'wls-edge/2:' . MasterProcess::getProjectIdentityHash()), 0, 32);
        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-5'
            . \substr($hex, 13, 3) . '-a' . \substr($hex, 17, 3) . '-'
            . \substr($hex, 20, 12);
    }

    private function endpointFile(string $instanceName): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances'
            . DIRECTORY_SEPARATOR . $instanceName . '.json';
    }

    /**
     * @return list<string>
     */
    private function certificateRoots(string $projectRoot): array
    {
        $roots = [$projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'ssl'];
        $env = Env::getInstance()->getConfig();
        $configured = \is_array($env)
            ? ($env['wls']['gateway']['certificate_roots'] ?? [])
            : [];
        foreach ((array)$configured as $root) {
            $root = \trim((string)$root);
            if ($root === '') {
                continue;
            }
            if (!\str_starts_with($root, '/') && \preg_match('/^[A-Za-z]:[\\\\\\/]/', $root) !== 1) {
                $root = $projectRoot . DIRECTORY_SEPARATOR . $root;
            }
            $real = \realpath($root);
            if (\is_string($real) && $real !== '') {
                $roots[] = $real;
            }
        }
        return \array_values(\array_unique($roots));
    }

    private function certificateGeneration(string $cert, string $key): string
    {
        if (!\is_file($cert) || !\is_file($key)) {
            return '';
        }
        $certHash = @\hash_file('sha256', $cert);
        $keyHash = @\hash_file('sha256', $key);
        if (!\is_string($certHash) || !\is_string($keyHash)) {
            return '';
        }
        return \hash('sha256', $certHash . ':' . $keyHash);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function resolveGeneration(string $digest): array
    {
        $dir = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'gateway-v2';
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Unable to create project gateway state directory.');
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'desired-generation.json';
        $lock = @\fopen($file . '.lock', 'c');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock project gateway generation.');
        }
        try {
            $raw = @\file_get_contents($file);
            $current = \is_string($raw) ? \json_decode($raw, true) : null;
            $generation = \max(0, (int)(\is_array($current) ? ($current['generation'] ?? 0) : 0));
            if (!\is_array($current) || !\hash_equals((string)($current['digest'] ?? ''), $digest)) {
                $generation++;
            }
            $idempotencyKey = \substr(\hash('sha256', $this->projectUuid() . ':' . $generation . ':' . $digest), 0, 40);
            $state = [
                'generation' => $generation,
                'digest' => $digest,
                'idempotency_key' => $idempotencyKey,
                'updated_at' => \gmdate(DATE_ATOM),
            ];
            $encoded = \json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!\is_string($encoded)
                || @\file_put_contents($file . '.tmp', $encoded, LOCK_EX) === false
                || !@\rename($file . '.tmp', $file)
            ) {
                @\unlink($file . '.tmp');
                throw new \RuntimeException('Unable to publish project gateway generation.');
            }
            @\chmod($file, 0600);
            return [$generation, $idempotencyKey];
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }
}
