<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicOrigin;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateRuntimeOptions;
use Weline\Server\Service\SharedStateRuntimeScope;

/** Shared argv identity for normal and maintenance Workers. */
final class WorkerRuntimeArgumentBuilder
{
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
        $selection = \Weline\Server\Service\Runtime\HttpProtocolSelection::fromConfig(
            ['http' => \is_array($httpConfig) ? $httpConfig : []],
            $context->sslEnabled,
        );
        $edgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
            ->resolve($context->envConfig)
            ->name();
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

    public static function publicOrigin(ServiceContext $context): string
    {
        return ManagedNginxPublicOrigin::normalize(
            (string)$context->getConfig('wls.public_origin', ''),
        );
    }
}
