<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Context;
use Weline\Framework\Controller\Extra\ExtraPolicyResolver;
use Weline\Framework\Controller\Extra\FpcPolicySnapshot;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Http\Fpc\FpcBypassEvaluator;
use Weline\Framework\Http\Fpc\FpcBypassFactsBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Server\Runtime\WlsRuntime;
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
        if ($host === '' || $this->isProtocolUpgrade($headers)) {
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

        if ($this->mustBypass($headers, $requestUri)) {
            return null;
        }

        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            return null;
        }
        // Keep cache lookup and its response decorators in one isolated
        // request Context. Scope comes only from the verified cache receipt.
        $previousContext = Context::getCurrent();
        $probeContext = new Context(['meta' => ['type' => 'fpc_probe', 'mode' => 'wls']]);
        Context::enter($probeContext);
        RequestContext::setId('fpc-probe-' . spl_object_id($probeContext));
        try {
            // Prefer READY's exact identity, then the receipt captured by a
            // natural anonymous homepage hit when startup skipped priming.
            // Both paths stay in Process L1; a miss returns to Framework.
            // (Anonymous `/` cannot rebuild variants in the probe Context while
            // legacy pre-router FPC is disabled — receipt identity is required.)
            if ($requestUri === '/') {
                $cookieHeader = (string)($headers['cookie'] ?? '');
                // Guest homepage receipt ignores cookies; still pass header for
                // cold-disk / formatted fallthrough identity helpers.
                // READY often stores https:// public origin while the Worker
                // transport is plain http://:port — try scheme peers so Process
                // L1 adopt is not false-missed as html-disk.
                $receipt = $this->resolveRootHomepageReceiptAcrossOriginPeers(
                    $fullUri,
                    $scheme,
                    $cookieHeader,
                );
                if (\is_array($receipt)) {
                    $this->prepareReceiptResponseContext($probeContext, $decision, $scheme, $host, $requestUri, $fullUri);
                    RequestContext::installScopeIdentity(ScopeIdentity::fromArray($receipt['scope_identity']));
                    RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
                    $cached = $this->coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                        $receipt,
                        $decision->keepAlive(),
                        $decision->method,
                        (string)($headers['accept'] ?? ''),
                        (string)($headers['accept-encoding'] ?? ''),
                        true,
                    );
                    if ($cached === null) {
                        $cached = $this->coordinator->getFormattedStaleCachedResponseForInternalReceipt(
                            $receipt,
                            $decision->keepAlive(),
                            $decision->method,
                            (string)($headers['accept'] ?? ''),
                            (string)($headers['accept-encoding'] ?? ''),
                            true,
                            $this->processOnly || !$decision->fpcSharedCacheEnabled(),
                        );
                    }
                } else {
                    $cached = null;
                }
                // Receipt miss: still allow URL-tree generated/html (same tree Nginx try_files).
                if ($cached === null) {
                    // Prime URI identity so Extra query_keys allowlist can retain
                    // variants and skip path-only html-disk (β pairing).
                    $this->prepareReceiptResponseContext($probeContext, $decision, $scheme, $host, $requestUri, $fullUri);
                    RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
                    $cached = $this->coordinator->getFormattedCachedResponseForFullUri(
                        $fullUri,
                        $decision->method,
                        (string)($headers['accept'] ?? ''),
                        (string)($headers['accept-encoding'] ?? ''),
                        (string)($headers['cookie'] ?? ''),
                        $decision->keepAlive(),
                        $this->processOnly || !$decision->fpcSharedCacheEnabled(),
                    );
                }
            } else {
                $localizedReceipt = $this->coordinator->resolveLocalizedHomepageProcessReceipt(
                    $fullUri,
                    (string)($headers['cookie'] ?? ''),
                );
                $cached = null;
                if (\is_array($localizedReceipt)) {
                    $this->prepareReceiptResponseContext($probeContext, $decision, $scheme, $host, $requestUri, $fullUri);
                    RequestContext::installScopeIdentity(ScopeIdentity::fromArray($localizedReceipt['scope_identity']));
                    RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
                    $cached = $this->coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                        $localizedReceipt,
                        $decision->keepAlive(),
                        $decision->method,
                        (string)($headers['accept'] ?? ''),
                        (string)($headers['accept-encoding'] ?? ''),
                        true,
                    );
                    if ($cached === null) {
                        $cached = $this->coordinator->getFormattedStaleCachedResponseForInternalReceipt(
                            $localizedReceipt,
                            $decision->keepAlive(),
                            $decision->method,
                            (string)($headers['accept'] ?? ''),
                            (string)($headers['accept-encoding'] ?? ''),
                            true,
                            $this->processOnly || !$decision->fpcSharedCacheEnabled(),
                        );
                    }
                }
                // Process receipt miss (language/currency path included): still
                // serve the URL-tree html-disk leaf. Do not enter the router.
                if ($cached === null) {
                    $this->prepareReceiptResponseContext($probeContext, $decision, $scheme, $host, $requestUri, $fullUri);
                    RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
                    $cached = $this->coordinator->getFormattedCachedResponseForFullUri(
                        $fullUri,
                        $decision->method,
                        (string)($headers['accept'] ?? ''),
                        (string)($headers['accept-encoding'] ?? ''),
                        (string)($headers['cookie'] ?? ''),
                        $decision->keepAlive(),
                        $this->processOnly || !$decision->fpcSharedCacheEnabled(),
                    );
                }
            }
        } catch (\Throwable) {
            return null;
        } finally {
            try {
                RequestContext::cleanup();
            } finally {
                Context::leave();
                if ($previousContext !== null) {
                    Context::enter($previousContext);
                }
            }
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

    private function prepareReceiptResponseContext(
        Context $context,
        WorkerPolicyDecision $decision,
        string $scheme,
        string $host,
        string $requestUri,
        string $fullUri,
    ): void {
        $context->set('input', [
            'method' => $decision->method,
            'scheme' => $scheme,
            'host' => $host,
            'uri' => $requestUri,
            'full_request_uri' => $fullUri,
            'server' => [
                'HTTP_HOST' => $host,
                'REQUEST_METHOD' => $decision->method,
                'REQUEST_SCHEME' => $scheme,
                'REQUEST_URI' => $requestUri,
                'QUERY_STRING' => (string)(\parse_url($requestUri, \PHP_URL_QUERY) ?: ''),
                'WELINE_ORIGIN_REQUEST_URI' => $requestUri,
                'ORIGIN_REQUEST_URI' => $requestUri,
                'HTTP_X_REQUESTED_WITH' => (string)($decision->headers['x-requested-with'] ?? ''),
            ],
        ]);
        $context->set('route.is_static', false);
        $context->set('route.is_media', false);
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

    /**
     * Serve-policy（internal label / no-store）仍归早路径。
     * 文档请求的 Authorization 不在这里旁路；接口与 BinQuery 由协调器按路径排除。
     * 身份/显式参数旁路一律走 Framework Evaluator + 侧车。
     *
     * @param array<string, string> $headers
     */
    private function mustBypass(array $headers, string $requestUri = ''): bool
    {
        $internalLabel = \strtolower(\trim((string)($headers['x-wls-internal-request'] ?? '')));
        if (\in_array($internalLabel, self::BYPASS_INTERNAL_REQUEST_LABELS, true)) {
            return true;
        }

        foreach (\explode(',', \strtolower((string)($headers['cache-control'] ?? ''))) as $directive) {
            $directive = \trim($directive);
            if ($directive === 'no-store' || \str_starts_with($directive, 'no-store=')) {
                return true;
            }
        }

        $query = [];
        $qPos = \strpos($requestUri, '?');
        if ($qPos !== false) {
            \parse_str(\substr($requestUri, $qPos + 1), $query);
        }

        // Allowlisted Extra query_keys no longer force bypass: Coordinator +
        // FpcHtmlDiskStore serve …/~q/{sha}/index.html.br (edge njs mirrors).
        // Path-only wrong HIT is prevented by URI priming before disk read.

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[\strtolower((string)$name)] = (string)$value;
        }

        return FpcBypassEvaluator::shouldBypass(FpcBypassFactsBuilder::build(
            $requestUri,
            $query,
            (string)($headers['cookie'] ?? ''),
            $normalizedHeaders,
            null,
        ));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestCarriesFpcQueryAllowlist(string $requestUri, array $query): bool
    {
        try {
            $path = (string)(\parse_url($requestUri, \PHP_URL_PATH) ?: '/');
            $path = FpcPolicySnapshot::normalizePath($path);
            $keys = $this->fpcQueryKeysForPath($path);
            if ($keys === []) {
                return false;
            }

            return FpcPolicySnapshot::requestCarriesAllowlistedQuery($keys, $query);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Same source as Nginx β map / Coordinator: compiled fpc_policy_snapshot.
     *
     * @return list<string>
     */
    private function fpcQueryKeysForPath(string $path): array
    {
        try {
            /** @var ExtraPolicyResolver $resolver */
            $resolver = ObjectManager::getInstance(ExtraPolicyResolver::class);

            return $resolver->queryKeysForPath($path, ScopeIdentity::website(0, 'default'));
        } catch (\Throwable) {
            // Probe / unit contexts may lack the resolver — read compiled snapshot.
        }

        $file = (\defined('BP') ? (string)BP : '') . '/generated/framework/fpc_policy_snapshot.php';
        if ($file === '/generated/framework/fpc_policy_snapshot.php' || !\is_file($file)) {
            return [];
        }
        try {
            $snapshot = CompiledPhpRegistry::load($file);
        } catch (\Throwable) {
            $snapshot = @include $file;
        }
        if (!\is_array($snapshot)) {
            return [];
        }
        $policy = FpcPolicySnapshot::resolve($snapshot, $path, ScopeIdentity::global());

        return FpcPolicySnapshot::queryKeysFromPolicy($policy);
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

    /**
     * Resolve anonymous `/` receipt across port peers and http↔https peers.
     *
     * TLS terminates at the edge; Worker often sees `http://host/` while READY
     * hydrated `https://host/`. Port peers alone are not enough — without the
     * scheme peer, Process L1 HIT falls through to html-disk despite Status
     * `source=process` adopt.
     *
     * @return array<string, mixed>|null
     */
    private function resolveRootHomepageReceiptAcrossOriginPeers(
        string $fullUri,
        string $scheme,
        string $cookieHeader,
    ): ?array {
        foreach ($this->rootHomepageReceiptCandidateUris($fullUri, $scheme) as $candidateUri) {
            $receipt = $this->runtime?->resolveHomepageFastPathReceipt(
                $candidateUri,
                $cookieHeader,
            ) ?? $this->coordinator->resolveRootHomepageProcessReceipt(
                $candidateUri,
                $cookieHeader,
            );
            if (\is_array($receipt)) {
                return $receipt;
            }
        }
        foreach ($this->rootHomepageReceiptCandidateUris($fullUri, $scheme) as $candidateUri) {
            $receipt = $this->coordinator->resolveRootHomepageStaleReceipt(
                $candidateUri,
                $cookieHeader,
            );
            if (\is_array($receipt)) {
                return $receipt;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function rootHomepageReceiptCandidateUris(string $fullUri, string $scheme): array
    {
        $scheme = \strtolower(\trim($scheme));
        $scheme = \in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
        $peerScheme = $scheme === 'https' ? 'http' : 'https';
        $candidates = [
            $fullUri,
            $this->alternateRootFullUri($fullUri, $scheme),
        ];
        try {
            $parts = \parse_url($fullUri);
        } catch (\ValueError) {
            $parts = null;
        }
        if (\is_array($parts)) {
            $host = \strtolower(\rtrim((string)($parts['host'] ?? ''), '.'));
            if ($host !== '') {
                $path = (string)($parts['path'] ?? '/');
                $path = $path !== '' ? $path : '/';
                $query = isset($parts['query']) && (string)$parts['query'] !== ''
                    ? '?' . (string)$parts['query']
                    : '';
                $peerUri = $peerScheme . '://' . $host . $path . $query;
                $candidates[] = $peerUri;
                $candidates[] = $this->alternateRootFullUri($peerUri, $peerScheme);
            }
        }

        $unique = [];
        foreach ($candidates as $uri) {
            if ($uri !== '' && !\in_array($uri, $unique, true)) {
                $unique[] = $uri;
            }
        }

        return $unique;
    }

    /**
     * READY/natural receipts may be stored with an explicit :443/:80 while the
     * live Host header omits the default port (or the reverse). Try the peer
     * form so fail-open Workers still bind the anonymous root identity.
     */
    private function alternateRootFullUri(string $fullUri, string $scheme): string
    {
        try {
            $parts = \parse_url($fullUri);
        } catch (\ValueError) {
            return $fullUri;
        }
        if (!\is_array($parts)) {
            return $fullUri;
        }

        $host = \strtolower(\rtrim((string)($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return $fullUri;
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int)($parts['port'] ?? 0);
        $authority = $port > 0 && $port !== $defaultPort
            ? $host
            : $host . ':' . $defaultPort;
        $path = (string)($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';

        return $scheme . '://' . $authority . $path;
    }
}
