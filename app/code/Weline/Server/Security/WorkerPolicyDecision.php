<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\Http\ConnectionSemantics;
use Weline\Framework\Runtime\Policy\RequestEnvelope;

/**
 * Immutable result of the mandatory Worker policy stages.
 *
 * The decision deliberately carries the already parsed request metadata so
 * Static/FPC and the framework runtime use the same canonical inputs.
 */
final readonly class WorkerPolicyDecision
{
    public const CACHE_STATIC_PROCESS_L1 = 1;

    public const CACHE_FPC_PROCESS_L1 = 2;

    public const CACHE_FPC_SHARED_L2 = 4;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public bool $allowed,
        public string $clientIp,
        public string $method,
        public string $protocol,
        public string $target,
        public string $path,
        public array $headers,
        public string $body,
        public ?string $response,
        public string $reason,
        public string $policyDigest,
        public bool $trustedProxy,
        public int $cachePolicyFlags,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function allow(
        string $clientIp,
        string $method,
        string $protocol,
        string $target,
        string $path,
        array $headers,
        string $body,
        string $policyDigest,
        bool $trustedProxy,
        int $cachePolicyFlags,
    ): self {
        return new self(
            true,
            $clientIp,
            $method,
            $protocol,
            $target,
            $path,
            $headers,
            $body,
            null,
            '',
            $policyDigest,
            $trustedProxy,
            $cachePolicyFlags,
        );
    }

    /** @param array<string, string> $headers */
    public static function deny(
        string $clientIp,
        string $method,
        string $protocol,
        string $target,
        string $path,
        array $headers,
        string $body,
        string $response,
        string $reason,
        string $policyDigest,
        bool $trustedProxy,
    ): self {
        return new self(
            false,
            $clientIp,
            $method,
            $protocol,
            $target,
            $path,
            $headers,
            $body,
            $response,
            $reason,
            $policyDigest,
            $trustedProxy,
            0,
        );
    }

    /** @return array<string, string|int> */
    public function requestServerInfo(): array
    {
        $serverInfo = [
            'REMOTE_ADDR' => $this->clientIp,
            'WLS_CANONICAL_REMOTE_ADDR' => $this->clientIp,
            'WLS_TRUST_FORWARDED_HEADERS' => $this->trustedProxy ? '1' : '0',
            'WLS_POLICY_DIGEST' => $this->policyDigest,
            'WLS_CACHE_POLICY_FLAGS' => $this->cachePolicyFlags,
        ];

        // The Framework FPC pipeline already treats this server marker as a
        // request-scoped lookup/build/publish bypass. A disabled or incomplete
        // policy therefore cannot fall through from the Worker fast path and
        // accidentally hit or publish Shared L2.
        if (!$this->fpcPipelineEnabled()) {
            $serverInfo['WLS_FPC_BYPASS'] = '1';
        }

        return $serverInfo;
    }

    public function staticProcessCacheEnabled(): bool
    {
        return ($this->cachePolicyFlags & self::CACHE_STATIC_PROCESS_L1) !== 0;
    }

    public function fpcProcessCacheEnabled(): bool
    {
        return ($this->cachePolicyFlags & self::CACHE_FPC_PROCESS_L1) !== 0;
    }

    public function fpcSharedCacheEnabled(): bool
    {
        return ($this->cachePolicyFlags & self::CACHE_FPC_SHARED_L2) !== 0;
    }

    public function fpcCacheEnabled(): bool
    {
        return $this->fpcProcessCacheEnabled() || $this->fpcSharedCacheEnabled();
    }

    public function fpcPipelineEnabled(): bool
    {
        // The current Framework pipeline builds Shared L2 and promotes it into
        // Process L1 as one transaction. Keep it disabled unless the immutable
        // bundle explicitly authorizes both layers.
        return $this->fpcProcessCacheEnabled() && $this->fpcSharedCacheEnabled();
    }

    public function keepAlive(): bool
    {
        return ConnectionSemantics::shouldKeepAlive(
            $this->protocol,
            (string)($this->headers['connection'] ?? ''),
        );
    }

    /**
     * Framework-owned immutable request snapshot for direct WlsRequest hydration.
     */
    public function requestEnvelope(): RequestEnvelope
    {
        return new RequestEnvelope(
            peerIp: $this->clientIp,
            method: $this->method,
            path: $this->path,
            host: (string)($this->headers['host'] ?? ''),
            headers: $this->headers,
            body: $this->body,
            attributes: [
                'target' => $this->target,
                'protocol' => $this->protocol,
                'trusted_proxy' => $this->trustedProxy,
                'policy_digest' => $this->policyDigest,
                'cache_policy_flags' => $this->cachePolicyFlags,
            ],
        );
    }
}
