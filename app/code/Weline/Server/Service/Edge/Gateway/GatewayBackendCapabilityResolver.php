<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;
use Weline\Server\Session\Client\SessionClient;

/**
 * Produces fail-closed, non-secret proof for multi-instance routing safety.
 */
final class GatewayBackendCapabilityResolver
{
    public const LAUNCH_SNAPSHOT_SCHEMA = 'wls-backend-capability-launch/1';

    /**
     * @param (\Closure(): array<string,mixed>)|null $configProvider
     * @param (\Closure(array<string,mixed>): bool)|null $healthProbe
     */
    public function __construct(
        private readonly ?\Closure $configProvider = null,
        private readonly ?\Closure $healthProbe = null,
    ) {
    }

    /**
     * @param array<string,mixed> $endpoint
     * @return array{
     *   mode:'isolated'|'stateless'|'shared_session',
     *   evidence:array<string,mixed>,
     *   evidence_digest:string
     * }
     */
    public function resolve(array $endpoint): array
    {
        $endpointGateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $declaredMode = \strtolower(\trim((string)(
            $endpointGateway['backend_capability'] ?? ''
        )));
        if ($declaredMode === 'stateless') {
            $instanceGeneration = (int)($endpointGateway['instance_generation'] ?? 0);
            $declarationGeneration = (int)(
                $endpointGateway['backend_capability_generation'] ?? 0
            );
            $validDeclaration = \hash_equals(
                    'runtime_config',
                    (string)($endpointGateway['backend_capability_source'] ?? ''),
                )
                && $instanceGeneration > 0
                && $declarationGeneration === $instanceGeneration;
            $evidence = [
                'schema' => 'wls-stateless-capability/1',
                'runtime_source' => 'project_endpoint',
                'runtime_declared' => $validDeclaration,
                'instance_generation' => $instanceGeneration,
                'reason' => $validDeclaration
                    ? 'declared_stateless_runtime'
                    : 'stateless_runtime_declaration_invalid',
            ];
            return $this->result($validDeclaration ? 'stateless' : 'isolated', $evidence);
        }

        $config = $this->loadConfig();
        $sessionConfig = \is_array($config['session'] ?? null) ? $config['session'] : [];
        $configuredStorage = \strtolower(\trim((string)($sessionConfig['default'] ?? 'file')));
        if ($configuredStorage === '') {
            $configuredStorage = 'file';
        }
        $wlsManaged = ($sessionConfig['wls_managed'] ?? true) !== false;
        $effectiveStorage = $configuredStorage === 'file' && $wlsManaged
            ? 'wls'
            : $configuredStorage;
        $evidence = [
            'schema' => 'wls-session-capability/1',
            'storage' => $effectiveStorage,
            'runtime_source' => 'project_shared_state',
            'runtime_registered' => false,
            'runtime_shared_service' => false,
            'host' => '',
            'port' => 0,
            'token_scope_digest' => '',
            'probe' => 'not_attempted',
            'reason' => 'session_storage_not_shared',
        ];
        if ($effectiveStorage !== 'wls') {
            return $this->result('isolated', $evidence);
        }

        $sharedState = \is_array($endpoint['shared_state'] ?? null)
            ? $endpoint['shared_state']
            : [];
        $runtime = \is_array($sharedState['session'] ?? null)
            ? $sharedState['session']
            : [];
        $evidence['runtime_registered'] = ($runtime['registered'] ?? false) === true;
        $evidence['runtime_shared_service'] = ($runtime['shared_service'] ?? false) === true;
        if (!$evidence['runtime_registered']) {
            $evidence['reason'] = 'session_runtime_not_registered';
            return $this->result('isolated', $evidence);
        }
        if (!$evidence['runtime_shared_service']
            || !\hash_equals('session_server', (string)($runtime['role'] ?? ''))
        ) {
            $evidence['reason'] = 'session_runtime_identity_invalid';
            return $this->result('isolated', $evidence);
        }

        $host = \strtolower(\trim((string)($runtime['host'] ?? '')));
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        $evidence['host'] = $host;
        if (!\in_array($host, ['127.0.0.1', '::1'], true)) {
            $evidence['reason'] = 'session_runtime_not_loopback';
            return $this->result('isolated', $evidence);
        }
        $port = (int)($runtime['port'] ?? 0);
        $evidence['port'] = $port;
        if ($port < 1 || $port > 65535) {
            $evidence['reason'] = 'session_runtime_endpoint_invalid';
            return $this->result('isolated', $evidence);
        }
        $tokenFileName = \trim((string)($runtime['token_file_name'] ?? ''));
        if ($tokenFileName === ''
            || \strlen($tokenFileName) > 128
            || \str_contains($tokenFileName, '/')
            || \str_contains($tokenFileName, '\\')
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $tokenFileName) !== 1
        ) {
            $evidence['reason'] = 'session_runtime_token_scope_invalid';
            return $this->result('isolated', $evidence);
        }
        $evidence['token_scope_digest'] = \hash('sha256', $tokenFileName);

        $probeRuntime = [
            'host' => $host,
            'port' => $port,
            'token_file_name' => $tokenFileName,
        ];
        try {
            $healthy = $this->healthProbe !== null
                ? (bool)($this->healthProbe)($probeRuntime)
                : $this->probeSessionRuntime($probeRuntime);
        } catch (\Throwable) {
            $healthy = false;
        }
        if (!$healthy) {
            $evidence['probe'] = 'unhealthy';
            $evidence['reason'] = 'session_runtime_unhealthy';
            return $this->result('isolated', $evidence);
        }

        $evidence['probe'] = 'healthy';
        $evidence['reason'] = 'authenticated_session_runtime';
        return $this->result('shared_session', $evidence);
    }

    /**
     * @param array<string,mixed> $capability
     * @return array<string,mixed>
     */
    public function instanceIdentityState(array $capability): array
    {
        $this->assertCapabilityObservation($capability);
        $mode = (string)$capability['mode'];
        if ($mode === 'isolated') {
            return ['session_capability' => 'isolated'];
        }
        $evidence = $capability['evidence'];
        $digest = (string)$capability['evidence_digest'];
        return [
            'session_capability' => $mode,
            'session_capability_evidence' => $evidence,
            'session_capability_evidence_digest' => $digest,
        ];
    }

    /**
     * Freeze the exact non-secret capability fact that every backend child of
     * this instance generation must attest. Runtime probes performed later by
     * the registration agent must never silently replace this launch fact.
     *
     * @param array<string,mixed> $capability
     * @return array<string,mixed>
     */
    public function createLaunchSnapshot(
        array $capability,
        int $instanceGeneration,
        string $launchId,
    ): array {
        $this->assertCapabilityObservation($capability);
        $launchId = \strtolower(\trim($launchId));
        if ($instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Gateway backend capability launch binding is invalid.'
            );
        }

        return [
            'schema' => self::LAUNCH_SNAPSHOT_SCHEMA,
            'instance_generation' => $instanceGeneration,
            'launch_id' => $launchId,
            'mode' => (string)$capability['mode'],
            'evidence' => $capability['evidence'],
            'evidence_digest' => (string)$capability['evidence_digest'],
        ];
    }

    /**
     * Read only the generation-bound launch snapshot persisted before Master
     * spawned its children. This intentionally performs no live capability
     * probe: a live probe could create a claim no running child can attest.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    public function capabilityFromLaunchSnapshot(array $endpoint): array
    {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $snapshot = \is_array($gateway['backend_capability_launch'] ?? null)
            ? $gateway['backend_capability_launch']
            : [];
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        if (!\hash_equals(
                self::LAUNCH_SNAPSHOT_SCHEMA,
                (string)($snapshot['schema'] ?? ''),
            )
            || $instanceGeneration < 1
            || (int)($snapshot['instance_generation'] ?? 0) !== $instanceGeneration
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals($launchId, (string)($snapshot['launch_id'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Gateway backend capability launch snapshot is missing or stale; restart the instance.'
            );
        }
        $capability = [
            'mode' => $snapshot['mode'] ?? null,
            'evidence' => $snapshot['evidence'] ?? null,
            'evidence_digest' => $snapshot['evidence_digest'] ?? null,
        ];
        $this->assertCapabilityObservation($capability);
        return $capability;
    }

    /**
     * Project generation records only the stable capability protocol policy.
     * Every runtime observation and proof remains fenced by instance digest.
     *
     * @param array<string,mixed> $capability
     * @return array{policy:'runtime_attested'}
     */
    public function projectDesiredState(array $capability): array
    {
        $mode = (string)($capability['mode'] ?? '');
        if (!\in_array($mode, ['isolated', 'stateless', 'shared_session'], true)) {
            throw new \InvalidArgumentException(
                'Gateway backend capability project state is invalid.'
            );
        }
        // Capability is an instance lease observation. Keeping only the stable
        // protocol policy prevents mixed instances from alternately advancing
        // the project desired generation. Full proof remains in instance digest.
        return ['policy' => 'runtime_attested'];
    }

    /** @return array<string,mixed> */
    private function loadConfig(): array
    {
        try {
            $config = $this->configProvider !== null
                ? ($this->configProvider)()
                : Env::getInstance()->getConfig();
        } catch (\Throwable) {
            return [];
        }
        return \is_array($config) ? $config : [];
    }

    /** @param array{host:string,port:int,token_file_name:string} $runtime */
    private function probeSessionRuntime(array $runtime): bool
    {
        $client = new SessionClient(
            $runtime['host'],
            $runtime['port'],
            [
                'token_file_name' => $runtime['token_file_name'],
                'connect_timeout' => 0.5,
                'timeout' => 0.75,
                'acquire_timeout' => 0.5,
                'pool_min_idle' => 0,
                'pool_size' => 1,
                'log_connect_fail' => false,
                'idle_timeout' => 10.0,
                'pool_health_ping_idle' => false,
            ],
        );
        try {
            return $client->healthCheck() && $client->isAuthenticated();
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param 'isolated'|'stateless'|'shared_session' $mode
     * @param array<string,mixed> $evidence
     * @return array{mode:'isolated'|'stateless'|'shared_session',evidence:array<string,mixed>,evidence_digest:string}
     */
    private function result(string $mode, array $evidence): array
    {
        return [
            'mode' => $mode,
            'evidence' => $evidence,
            'evidence_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            ),
        ];
    }

    /** @param array<string,mixed> $capability */
    private function assertCapabilityObservation(array $capability): void
    {
        $mode = (string)($capability['mode'] ?? '');
        $evidence = \is_array($capability['evidence'] ?? null)
            ? $capability['evidence']
            : [];
        $digest = \strtolower(\trim((string)($capability['evidence_digest'] ?? '')));
        if (!\in_array($mode, ['isolated', 'stateless', 'shared_session'], true)
            || $evidence === []
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($evidence)),
            )
        ) {
            throw new \InvalidArgumentException(
                'Gateway backend capability observation is invalid.'
            );
        }
    }
}
