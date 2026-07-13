<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Server\Security\WorkerPolicyDecision;

/**
 * Transport-neutral Worker FPC lookup after the mandatory policy decision.
 *
 * The coordinator and immutable configuration are resolved once during Worker
 * bootstrap. A hot hit therefore avoids per-request ObjectManager/Env lookups;
 * only Dispatcher topology adds its required route hint to the wire response.
 */
final class WorkerFullPageCacheFastPath
{
    /**
     * Raw request markers that are authoritative before WlsRequest hydrates
     * their HTTP_* / WLS_* server-variable equivalents.
     *
     * @var list<string>
     */
    private const BYPASS_HEADERS = [
        'x-wls-fpc-bypass',
        'x-wls-internal-fpc-bypass',
        'x-wls-dynamic-warmup',
        'x-wls-internal-dynamic-warmup',
        'x-wls-dynamic-benchmark',
        'x-wls-fpc-prime',
        // Defensive aliases for control planes that serialize the canonical
        // server-variable marker back onto an internal HTTP request.
        'wls-fpc-bypass',
        'wls-internal-dynamic-warmup',
        'http-x-wls-fpc-bypass',
        'http-x-wls-dynamic-warmup',
        'http-x-wls-dynamic-benchmark',
    ];

    /** @var list<string> */
    private const BYPASS_INTERNAL_REQUEST_LABELS = [
        'dynamic-first-render',
        'dynamic-warmup',
        'backend-first-render',
        'homepage-fpc-prime',
    ];

    private readonly bool $processOnly;

    public function __construct(
        private readonly FullPageCacheCoordinator $coordinator,
        private readonly ?WlsRuntime $runtime = null,
        ?bool $sharedEnabled = null,
    ) {
        $sharedEnabled ??= (bool)Env::get('wls.worker.fpc_fastpath_shared_enabled', true);
        $this->processOnly = !$sharedEnabled;
    }

    /**
     * @return array{response:string,source:string,bytes:int}|null
     */
    public function lookup(WorkerPolicyDecision $decision, string $scheme): ?array
    {
        if (!$decision->fpcProcessCacheEnabled()
            || !\in_array($decision->method, ['GET', 'HEAD'], true)
        ) {
            return null;
        }

        $headers = $decision->headers;
        $host = \trim((string)($headers['host'] ?? ''));
        if ($host === '' || $this->isProtocolUpgrade($headers) || $this->mustBypass($headers)) {
            return null;
        }

        $scheme = \strtolower(\trim($scheme));
        $scheme = \in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
        $target = $decision->target !== '' ? $decision->target : '/';
        try {
            $targetParts = \parse_url($target);
        } catch (\ValueError) {
            return null;
        }

        if (!\is_array($targetParts)) {
            return null;
        }
        $absoluteTarget = !empty($targetParts['scheme']) || !empty($targetParts['host']);
        if ($absoluteTarget && !$this->absoluteTargetMatchesHost($targetParts, $host, $scheme)) {
            return null;
        }

        $requestPath = (string)($targetParts['path'] ?? '/');
        $requestPath = $requestPath !== '' ? $requestPath : '/';
        $requestUri = \str_starts_with($requestPath, '/') ? $requestPath : '/' . $requestPath;
        if (isset($targetParts['query']) && (string)$targetParts['query'] !== '') {
            $requestUri .= '?' . (string)$targetParts['query'];
        }
        $fullUri = $scheme . '://' . $host . $requestUri;

        try {
            $cached = $this->coordinator->getFormattedCachedResponseForFullUri(
                $fullUri,
                $decision->method,
                (string)($headers['accept'] ?? ''),
                (string)($headers['accept-encoding'] ?? ''),
                (string)($headers['cookie'] ?? ''),
                $decision->keepAlive(),
                $this->processOnly || !$decision->fpcSharedCacheEnabled(),
            );

            // READY warms the homepage under an exact locale/currency receipt.
            // Anonymous requests intentionally carry no cookies, so reuse that
            // already-validated identity instead of rebuilding a second FPC
            // variant or entering Router/Controller on every root request.
            if ($cached === null && $this->runtime instanceof WlsRuntime && $requestUri === '/') {
                $identity = $this->runtime->resolveHomepageFastPathIdentity(
                    $fullUri,
                    (string)($headers['cookie'] ?? ''),
                );
                if (\is_array($identity)) {
                    $cached = $this->coordinator->getFormattedCachedResponseForFullUri(
                        $identity['full_uri'],
                        $decision->method,
                        (string)($headers['accept'] ?? ''),
                        (string)($headers['accept-encoding'] ?? ''),
                        $identity['cookie_header'],
                        $decision->keepAlive(),
                        $this->processOnly || !$decision->fpcSharedCacheEnabled(),
                    );
                }
            }
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($cached) || !\is_string($cached['response'] ?? null) || $cached['response'] === '') {
            return null;
        }

        if (RouteHintService::isEnabled()) {
            $cached['response'] = RouteHintService::addHintToResponse(
                $cached['response'],
                RouteHintService::extractSniFromHeaders($headers),
            );
        }
        $this->runtime?->noteHomepageNaturalHit($decision->path);

        return [
            'response' => $cached['response'],
            'source' => (string)($cached['source'] ?? 'worker_fastpath'),
            'bytes' => (int)($cached['bytes'] ?? 0),
        ];
    }

    /** @param array<string, string> $headers */
    private function isProtocolUpgrade(array $headers): bool
    {
        if (\trim((string)($headers['upgrade'] ?? '')) !== '') {
            return true;
        }

        foreach (\explode(',', \strtolower((string)($headers['connection'] ?? ''))) as $token) {
            if (\trim($token) === 'upgrade') {
                return true;
            }
        }

        return \stripos((string)($headers['accept'] ?? ''), 'text/event-stream') !== false;
    }

    /** @param array<string, string> $headers */
    private function mustBypass(array $headers): bool
    {
        foreach (self::BYPASS_HEADERS as $name) {
            if ($this->truthy((string)($headers[$name] ?? ''))) {
                return true;
            }
        }

        $internalLabel = \strtolower(\trim((string)($headers['x-wls-internal-request'] ?? '')));
        if (\in_array($internalLabel, self::BYPASS_INTERNAL_REQUEST_LABELS, true)) {
            return true;
        }

        foreach (\explode(',', \strtolower((string)($headers['cache-control'] ?? ''))) as $directive) {
            $directive = \trim($directive);
            if ($directive === 'no-cache'
                || \str_starts_with($directive, 'no-cache=')
                || $directive === 'no-store'
                || \str_starts_with($directive, 'no-store=')
                || \preg_match('/^max-age\s*=\s*0+$/', $directive) === 1
            ) {
                return true;
            }
        }

        foreach (\explode(',', \strtolower((string)($headers['pragma'] ?? ''))) as $directive) {
            if (\trim($directive) === 'no-cache') {
                return true;
            }
        }

        return false;
    }

    private function truthy(string $value): bool
    {
        foreach (\explode(',', \strtolower($value)) as $candidate) {
            if (\in_array(\trim($candidate), ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $targetParts */
    private function absoluteTargetMatchesHost(array $targetParts, string $host, string $scheme): bool
    {
        $targetScheme = \strtolower((string)($targetParts['scheme'] ?? ''));
        if ($targetScheme !== '' && $targetScheme !== $scheme) {
            return false;
        }

        try {
            $hostParts = \parse_url($scheme . '://' . $host);
        } catch (\ValueError) {
            return false;
        }
        if (!\is_array($hostParts)) {
            return false;
        }

        $targetHost = \strtolower(\rtrim((string)($targetParts['host'] ?? ''), '.'));
        $headerHost = \strtolower(\rtrim((string)($hostParts['host'] ?? ''), '.'));
        if ($targetHost === '' || $headerHost === '' || $targetHost !== $headerHost) {
            return false;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $targetPort = (int)($targetParts['port'] ?? $defaultPort);
        $headerPort = (int)($hostParts['port'] ?? $defaultPort);

        return $targetPort === $headerPort;
    }
}
