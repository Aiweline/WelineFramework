<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicOrigin;
use Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityResolver;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateRuntimeOptions;
use Weline\Server\Service\SharedStateRuntimeScope;

/** Shared argv identity for normal and maintenance Workers. */
final class WorkerRuntimeArgumentBuilder
{
    /**
     * Return the non-secret, generation-bound capability fact a gateway
     * backend must attest. Values come exclusively from the launch snapshot
     * frozen before Master starts; request headers can never supply them.
     *
     * @return string[]
     */
    public static function gatewayBackendCapability(ServiceContext $context): array
    {
        $gateway = $context->getConfig('wls.gateway', []);
        $resolver = new GatewayBackendCapabilityResolver();
        $capability = $resolver->capabilityFromLaunchSnapshot([
            'gateway' => \is_array($gateway) ? $gateway : [],
        ]);
        $identity = $resolver->instanceIdentityState($capability);
        $mode = (string)$identity['session_capability'];
        $evidenceDigest = $mode === 'isolated'
            ? \str_repeat('0', 64)
            : (string)($identity['session_capability_evidence_digest'] ?? '');

        if (!\in_array($mode, ['isolated', 'stateless', 'shared_session'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $evidenceDigest) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway backend capability launch arguments are invalid.'
            );
        }

        return [
            '--gateway-session-capability=' . $mode,
            '--gateway-session-capability-evidence-digest=' . $evidenceDigest,
        ];
    }

    /** @return string[] */
    public static function sharedState(ServiceContext $context): array
    {
        $runtime = SharedStateRuntimeOptions::fromCliArgs([], $context->instanceName, $context->envConfig);
        $session = $runtime->getSession();
        $memory = $runtime->getMemory();

        $sessionHost = \trim((string)($session['host'] ?? '127.0.0.1'));
        if ($sessionHost === '') {
            $sessionHost = '127.0.0.1';
        }
        $sessionPort = (int)($session['port'] ?? (19970 + MasterProcess::getProjectPortOffset()));
        if ($sessionPort <= 0) {
            $sessionPort = 19970 + MasterProcess::getProjectPortOffset();
        }
        $defaultSessionToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('session_server', $sessionPort);
        $sessionToken = \trim((string)($session['token_file_name'] ?? $defaultSessionToken));
        if ($sessionToken === '') {
            $sessionToken = $defaultSessionToken;
        }

        $memoryHost = \trim((string)($memory['host'] ?? '127.0.0.1'));
        if ($memoryHost === '') {
            $memoryHost = '127.0.0.1';
        }
        $memoryPort = (int)($memory['port'] ?? (19971 + MasterProcess::getProjectPortOffset()));
        if ($memoryPort <= 0) {
            $memoryPort = 19971 + MasterProcess::getProjectPortOffset();
        }
        $defaultMemoryToken = SharedStateRuntimeScope::defaultTokenFileNameForRole('memory_server', $memoryPort);
        $memoryToken = \trim((string)($memory['token_file_name'] ?? $defaultMemoryToken));
        if ($memoryToken === '') {
            $memoryToken = $defaultMemoryToken;
        }

        return [
            '--session-host=' . $sessionHost,
            '--session-port=' . $sessionPort,
            '--session-token-file-name=' . $sessionToken,
            '--memory-host=' . $memoryHost,
            '--memory-port=' . $memoryPort,
            '--memory-token-file-name=' . $memoryToken,
        ];
    }

    /** @return string[] */
    public static function protocolPolicy(ServiceContext $context, bool $includeHttp3Activation = true): array
    {
        $httpConfig = $context->getConfig('wls.http', []);
        $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
            ->resolve($context->envConfig)
            ->name();
        $selection = \Weline\Server\Service\Runtime\HttpProtocolSelection::fromConfig(
            [
                'edge' => ['adapter' => $edgeAdapter],
                'http' => \is_array($httpConfig) ? $httpConfig : [],
            ],
            $context->sslEnabled,
        );
        $http3Config = $context->getConfig('wls.http3', []);
        $http3Config = \is_array($http3Config) ? $http3Config : [];
        $http3Enabled = $includeHttp3Activation && (bool)($http3Config['enabled'] ?? false);
        $http3RuntimeVerified = $http3Enabled && (bool)($http3Config['runtime_verified'] ?? false);
        $snapshot = [
            'schema_version' => 1,
            'instance_name' => $context->instanceName,
            'edge_adapter' => $edgeAdapter,
            'http_protocol_selection' => $selection->toArray(),
            'http3' => [
                'enabled' => $http3Enabled,
                'runtime_verified' => $http3RuntimeVerified,
                'reason' => $includeHttp3Activation
                    ? (string)($http3Config['reason'] ?? '')
                    : 'HTTP/3 is disabled for the maintenance Worker.',
                'native_digest' => (string)($http3Config['native_digest'] ?? ''),
                'fingerprint' => (string)($http3Config['fingerprint'] ?? ''),
            ],
        ];
        $json = \json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $encoded = \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');

        return [
            '--wls-http-policy=' . $encoded,
            '--wls-http-policy-sha256=' . \hash('sha256', $json),
        ];
    }

    /** @return string[] */
    public static function gatewayFallbackProtocolPolicy(ServiceContext $context): array
    {
        $httpConfig = $context->getConfig('wls.http', []);
        $httpConfig = \is_array($httpConfig) ? $httpConfig : [];
        unset($httpConfig['alt_svc']);
        $httpConfig['alt_svc'] = false;
        $protocols = (array)($httpConfig['protocols'] ?? []);
        $normalizedProtocols = [];
        foreach ($protocols !== [] ? $protocols : ['h2', 'h1'] as $protocol) {
            $normalized = match (\strtolower(\trim((string)$protocol))) {
                'h2', 'http2', 'http/2', 'http/2.0', '2', '2.0' => 'h2',
                'h1', 'http1', 'http1.1', 'http/1', 'http/1.1', '1', '1.1' => 'h1',
                'h3', 'http3', 'http/3', 'http/3.0', '3', '3.0' => '',
                default => throw new \InvalidArgumentException(
                    'Unsupported gateway fallback HTTP protocol "' . (string)$protocol . '".'
                ),
            };
            if ($normalized !== '' && !\in_array($normalized, $normalizedProtocols, true)) {
                $normalizedProtocols[] = $normalized;
            }
        }
        $httpConfig['protocols'] = $normalizedProtocols;
        if ($httpConfig['protocols'] === []) {
            $httpConfig['protocols'] = ['h2', 'h1'];
        }
        if (!\in_array('h1', $httpConfig['protocols'], true)) {
            $httpConfig['protocols'][] = 'h1';
        }
        $preferred = \strtolower(\trim((string)($httpConfig['preferred'] ?? 'h2')));
        $preferred = match ($preferred) {
            'h2', 'http2', 'http/2', 'http/2.0', '2', '2.0' => 'h2',
            'h1', 'http1', 'http1.1', 'http/1', 'http/1.1', '1', '1.1' => 'h1',
            default => '',
        };
        $httpConfig['preferred'] = \in_array($preferred, $httpConfig['protocols'], true)
            ? $preferred
            : (\in_array('h2', $httpConfig['protocols'], true) ? 'h2' : 'h1');
        $selection = \Weline\Server\Service\Runtime\HttpProtocolSelection::fromConfig(
            [
                'edge' => ['adapter' => 'wls'],
                'http' => $httpConfig,
            ],
            true,
        );
        $snapshot = [
            'schema_version' => 1,
            'instance_name' => $context->instanceName,
            'edge_adapter' => 'wls',
            'http_protocol_selection' => $selection->toArray(),
            'http3' => [
                'enabled' => false,
                'runtime_verified' => false,
                'reason' => 'WLS gateway fallback supports TLS HTTP/2 and HTTP/1.1 only.',
                'native_digest' => '',
                'fingerprint' => '',
            ],
        ];
        $json = \json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return [
            '--wls-http-policy='
                . \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '='),
            '--wls-http-policy-sha256=' . \hash('sha256', $json),
        ];
    }

    public static function publicOrigin(ServiceContext $context): string
    {
        $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
            ->resolve($context->envConfig)
            ->name();
        $origin = (string)$context->getConfig('wls.public_origin', '');
        return $edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
            ? \Weline\Server\Service\Edge\PureWlsPublicOrigin::normalize($origin)
            : ManagedNginxPublicOrigin::normalize($origin);
    }
}
