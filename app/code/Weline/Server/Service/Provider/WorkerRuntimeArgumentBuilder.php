<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Server\Service\Contract\ServiceContext;
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

    public static function publicOrigin(ServiceContext $context): string
    {
        $scheme = $context->sslEnabled ? 'https' : 'http';
        $rawHost = \trim((string)($context->publicHost ?: $context->host ?: '127.0.0.1'));
        $rawIpv6 = \trim($rawHost, '[]');
        if (!\str_contains($rawHost, '://')
            && \filter_var($rawIpv6, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
        ) {
            $parts = ['host' => $rawIpv6];
        } else {
            $candidate = \str_contains($rawHost, '://') ? $rawHost : $scheme . '://' . $rawHost;
            try {
                $parts = \parse_url($candidate);
            } catch (\ValueError) {
                $parts = [];
            }
        }
        if (!\is_array($parts)) {
            $parts = [];
        }

        $host = \trim((string)($parts['host'] ?? ''));
        if ($host === '') {
            $host = '127.0.0.1';
        }
        $authority = \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
            ? '[' . \trim($host, '[]') . ']'
            : $host;
        $port = isset($parts['port']) ? (int)$parts['port'] : $context->mainPort;
        $defaultPort = $context->sslEnabled ? 443 : 80;
        if ($port > 0 && $port <= 65535 && $port !== $defaultPort) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }
}
