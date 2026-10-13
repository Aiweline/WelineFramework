<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\Bake;

use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;

/**
 * Warm a list of absolute URLs onto local FPC via connect-only HTTP + Host virtualization.
 *
 * No Seo/Websites imports. Caller supplies loc list already resolved.
 */
final class SitemapFpcBakeEngine
{
    public function __construct(
        private readonly FpcConnectOnlyHttpClient $http = new FpcConnectOnlyHttpClient(),
    ) {
    }

    /**
     * @param list<string> $absoluteUrls Source locs (any origin); rewritten to $bakeOrigin
     * @return array{
     *   ok:bool,
     *   reason?:string,
     *   planned:list<string>,
     *   warmed:int,
     *   failed:int,
     *   failures:list<array{url:string,reason:string,status:int}>,
     *   load_samples:list<float>
     * }
     */
    public function bake(
        array $absoluteUrls,
        string $bakeOrigin,
        string $connectOrigin = '',
        float $loadTargetPct = 70.0,
        ?int $maxConcurrency = null,
        bool $fullPower = false,
        bool $dryRun = false,
        bool $failFast = false,
        ?callable $onProgress = null,
    ): array {
        $origin = FpcOfflineBakeOrigin::normalize($bakeOrigin);
        if ($origin === '') {
            return $this->failEmpty('origin_invalid');
        }
        $requestHost = $this->requestHostFromOrigin($origin);
        if ($requestHost === '') {
            return $this->failEmpty('origin_host_invalid');
        }

        $planned = [];
        $seen = [];
        foreach ($absoluteUrls as $raw) {
            $rewritten = FpcConnectOnlyHttpClient::rewriteOrigin((string)$raw, $origin);
            if ($rewritten === '' || !FpcOfflineBakeOrigin::urlBelongsTo($rewritten, $origin)) {
                continue;
            }
            if (isset($seen[$rewritten])) {
                continue;
            }
            $seen[$rewritten] = true;
            $planned[] = $rewritten;
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'planned' => $planned,
                'warmed' => 0,
                'failed' => 0,
                'failures' => [],
                'load_samples' => [],
            ];
        }

        $connect = $this->http->resolveConnect($connectOrigin);
        if (!$this->isSafeConnectPeer($connect['connect'], $origin)) {
            return $this->failEmpty('connect_peers_bake_origin_forbidden');
        }

        $probe = $this->http->probe($connectOrigin, $requestHost);
        if (!($probe['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => 'connect_probe_failed:' . (string)($probe['reason'] ?? 'unknown'),
                'planned' => $planned,
                'warmed' => 0,
                'failed' => 0,
                'failures' => [],
                'load_samples' => [],
            ];
        }

        $max = $maxConcurrency ?? LoadTargetConcurrencyController::defaultMaxConcurrency();
        $target = \max(1.0, \min(99.0, $loadTargetPct)) / 100.0;
        $controller = new LoadTargetConcurrencyController($target, \max(1, $max), $fullPower);
        $warmed = 0;
        $failed = 0;
        $failures = [];
        $loadSamples = [];
        $queue = $planned;

        while ($queue !== []) {
            $sleepUs = $controller->tick();
            $loadSamples[] = $controller->lastSample();
            if ($sleepUs > 0) {
                \usleep($sleepUs);
            }
            $limit = \max(1, $controller->concurrency());
            $wave = [];
            while (\count($wave) < $limit && $queue !== []) {
                $wave[] = \array_shift($queue);
            }
            foreach ($wave as $url) {
                $result = $this->http->getOnConnect(
                    $connectOrigin,
                    $requestHost,
                    $this->pathFromAbsolute((string)$url),
                    false,
                );
                if (!empty($result['ok'])) {
                    $warmed++;
                } else {
                    $failed++;
                    $failures[] = [
                        'url' => (string)$url,
                        'reason' => (string)($result['reason'] ?? 'fail'),
                        'status' => (int)($result['status'] ?? 0),
                    ];
                    if ($failFast) {
                        return [
                            'ok' => false,
                            'reason' => 'fail_fast',
                            'planned' => $planned,
                            'warmed' => $warmed,
                            'failed' => $failed,
                            'failures' => $failures,
                            'load_samples' => $loadSamples,
                        ];
                    }
                }
                if ($onProgress !== null) {
                    $onProgress([
                        'warmed' => $warmed,
                        'failed' => $failed,
                        'remaining' => \count($queue),
                        'concurrency' => $controller->concurrency(),
                        'load' => $controller->lastSample(),
                        'url' => (string)$url,
                    ]);
                }
            }
        }

        return [
            'ok' => $failed === 0 || $warmed > 0,
            'planned' => $planned,
            'warmed' => $warmed,
            'failed' => $failed,
            'failures' => $failures,
            'load_samples' => $loadSamples,
        ];
    }

    /**
     * Forbid using the bake/production host as TCP peer (must be loopback or other local face).
     */
    public function isSafeConnectPeer(string $connectHost, string $bakeOrigin): bool
    {
        $connectHost = \strtolower(\trim($connectHost));
        if ($connectHost === '' || $this->isLoopback($connectHost)) {
            return true;
        }
        $originHost = \strtolower((string)(\parse_url(FpcOfflineBakeOrigin::normalize($bakeOrigin), \PHP_URL_HOST) ?: ''));
        if ($originHost === '') {
            return true;
        }

        return $connectHost !== $originHost;
    }

    private function isLoopback(string $host): bool
    {
        $h = \strtolower(\trim($host));

        return $h === '127.0.0.1' || $h === 'localhost' || $h === '::1';
    }

    private function requestHostFromOrigin(string $origin): string
    {
        $parts = \parse_url($origin);
        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = \strtolower((string)$parts['host']);
        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        $port = (int)($parts['port'] ?? 0);
        if ($port > 0) {
            $default = $scheme === 'http' ? 80 : 443;
            if ($port !== $default) {
                return $host . ':' . $port;
            }
        }

        return $host;
    }

    private function pathFromAbsolute(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '/';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!empty($parts['query'])) {
            $path .= '?' . (string)$parts['query'];
        }

        return $path;
    }

    /**
     * @return array{ok:bool,reason:string,planned:list<string>,warmed:int,failed:int,failures:list<array{url:string,reason:string,status:int}>,load_samples:list<float>}
     */
    private function failEmpty(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'planned' => [],
            'warmed' => 0,
            'failed' => 0,
            'failures' => [],
            'load_samples' => [],
        ];
    }
}
