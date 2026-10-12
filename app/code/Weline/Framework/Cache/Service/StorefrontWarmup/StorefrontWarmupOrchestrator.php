<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FpcWarmupProviderInterface;

/**
 * Framework-owned storefront FPC warm orchestration (phases A→B→C→D).
 *
 * WLS Workers must not schedule storefront warm; cron / Clear call this only.
 */
final class StorefrontWarmupOrchestrator
{
    private const LOCK_NAME = 'storefront_warmup_orchestrator.lock';
    private const DEFAULT_SHARD = 6;
    private const DEFAULT_WALL_MS = 45000;

    public function __construct(
        private readonly StorefrontWarmupProgressLedger $ledger = new StorefrontWarmupProgressLedger(),
        private readonly StorefrontWarmupBusyGate $busyGate = new StorefrontWarmupBusyGate(),
        private readonly StorefrontWarmupExcludeGate $excludeGate = new StorefrontWarmupExcludeGate(),
        private readonly StorefrontWarmupHttpClient $http = new StorefrontWarmupHttpClient(),
        private readonly StorefrontWarmupSitemapParser $sitemapParser = new StorefrontWarmupSitemapParser(),
    ) {
    }

    /**
     * Clear→warm entry: reset ledger and run one bounded slice from phase A.
     *
     * @return array<string, mixed>
     */
    public function runAfterClear(): array
    {
        $this->ledger->reset();

        return $this->runSlice(['source' => 'clear', 'reset' => true]);
    }

    /**
     * Cron tick: resume ledger; respect busy/backoff.
     *
     * @param array{source?:string,reset?:bool} $options
     * @return array<string, mixed>
     */
    public function runSlice(array $options = []): array
    {
        $startedAt = \microtime(true);
        $source = (string)($options['source'] ?? 'cron');
        $lock = $this->acquireLock();
        if ($lock === null) {
            return [
                'ok' => false,
                'reason' => 'lock_busy',
                'source' => $source,
                'elapsed_ms' => 0.0,
            ];
        }

        try {
            $state = $this->ledger->read();
            $now = \microtime(true);
            if (($state['backoff_until'] ?? 0) > $now) {
                return [
                    'ok' => true,
                    'reason' => 'backoff',
                    'source' => $source,
                    'phase' => $state['phase'],
                    'backoff_until' => $state['backoff_until'],
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                ];
            }

            $busy = $this->busyGate->sample();
            if ($busy['busy'] && $source !== 'clear') {
                $state['backoff_until'] = $now + 60.0;
                $state['last_status'] = 'skip';
                $state['last_reason'] = $busy['reason'];
                $this->ledger->write($state);

                return [
                    'ok' => true,
                    'reason' => 'busy_gate',
                    'source' => $source,
                    'busy' => $busy,
                    'phase' => $state['phase'],
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                ];
            }

            $shard = $this->intEnv('framework.cache.storefront_warmup_shard', self::DEFAULT_SHARD, 1, 24);
            $wallMs = $this->intEnv('framework.cache.storefront_warmup_wall_ms', self::DEFAULT_WALL_MS, 5000, 180000);
            $warmed = 0;
            $skipped = 0;
            $failed = 0;
            $samples = [];

            while ($warmed + $skipped + $failed < $shard) {
                if ($this->elapsedMs($startedAt) >= $wallMs) {
                    break;
                }
                $midBusy = $this->busyGate->sample();
                if ($source !== 'clear'
                    && $this->busyGate->shouldAbortMidSlice((float)$midBusy['ratio'], (float)$midBusy['abort_threshold'])
                ) {
                    $state['backoff_until'] = \microtime(true) + 60.0;
                    $state['last_status'] = 'skip';
                    $state['last_reason'] = 'abort_busy';
                    $this->ledger->write($state);
                    break;
                }

                $step = $this->advanceOne($state);
                $state = $step['state'];
                $samples[] = $step['sample'];
                if ($step['sample']['status'] === 'ok') {
                    $warmed++;
                    $state['ok'] = (int)$state['ok'] + 1;
                } elseif ($step['sample']['status'] === 'skip') {
                    $skipped++;
                    $state['skip'] = (int)$state['skip'] + 1;
                } elseif ($step['sample']['status'] === 'fail') {
                    $failed++;
                    $state['fail'] = (int)$state['fail'] + 1;
                }
                if (($step['sample']['status'] ?? '') === 'done') {
                    break;
                }
                $this->ledger->write($state);
            }
            $this->ledger->write($state);

            return [
                'ok' => true,
                'reason' => 'slice',
                'source' => $source,
                'phase' => $state['phase'],
                'warmed' => $warmed,
                'skipped' => $skipped,
                'failed' => $failed,
                'samples' => \array_slice($samples, 0, 12),
                'busy' => $busy,
                'elapsed_ms' => $this->elapsedMs($startedAt),
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,sample:array<string,mixed>}
     */
    private function advanceOne(array $state): array
    {
        $phase = (string)($state['phase'] ?? StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
        return match ($phase) {
            StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE => $this->runPhaseA($state),
            StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS => $this->runPhaseB($state),
            StorefrontWarmupProgressLedger::PHASE_C_EXTRAS => $this->runPhaseC($state),
            StorefrontWarmupProgressLedger::PHASE_D_LOCS => $this->runPhaseD($state),
            default => [
                'state' => $state,
                'sample' => ['status' => 'done', 'url' => '', 'reason' => 'phase_done'],
            ],
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,sample:array<string,mixed>}
     */
    private function runPhaseA(array $state): array
    {
        $result = $this->warmPath('/');
        $state['last_url'] = '/';
        $state['last_status'] = $result['status'];
        $state['last_reason'] = $result['reason'];
        $state['phase'] = StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS;
        $state['sitemap_doc_cursor'] = 0;
        $state['sitemap_docs'] = [];

        return [
            'state' => $state,
            'sample' => ['status' => $result['status'], 'url' => '/', 'reason' => $result['reason'], 'phase' => 'A'],
        ];
    }

    /**
     * Phase B: index ∪ shard documents in one phase.
     *
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,sample:array<string,mixed>}
     */
    private function runPhaseB(array $state): array
    {
        $docs = \is_array($state['sitemap_docs'] ?? null) ? $state['sitemap_docs'] : [];
        if ($docs === []) {
            $docs = $this->discoverSitemapDocuments();
            $state['sitemap_docs'] = $docs;
            $state['sitemap_doc_cursor'] = 0;
        }
        $cursor = (int)($state['sitemap_doc_cursor'] ?? 0);
        if ($cursor >= \count($docs)) {
            $state['phase'] = StorefrontWarmupProgressLedger::PHASE_C_EXTRAS;
            $state['extras_cursor'] = 0;
            $state['extras'] = [];

            return [
                'state' => $state,
                'sample' => ['status' => 'skip', 'url' => '', 'reason' => 'phase_b_complete', 'phase' => 'B'],
            ];
        }

        $path = (string)$docs[$cursor];
        $gate = $this->excludeGate->evaluate($path);
        // Sitemap XML docs are not HTML FPC — still HTTP-GET to populate edge/origin XML cache.
        $isXml = \str_ends_with(\strtolower((string)(\parse_url($path, \PHP_URL_PATH) ?: $path)), '.xml')
            || $path === '/sitemap.xml'
            || \str_contains($path, '/sitemap');
        if (!$isXml && !$gate['allow']) {
            $state['sitemap_doc_cursor'] = $cursor + 1;
            $state['last_url'] = $path;
            $state['last_status'] = 'skip';
            $state['last_reason'] = $gate['reason'];

            return [
                'state' => $state,
                'sample' => ['status' => 'skip', 'url' => $path, 'reason' => $gate['reason'], 'phase' => 'B'],
            ];
        }

        $fetch = $this->http->get($path, true);
        $status = $fetch['ok'] ? 'ok' : 'fail';
        $state['sitemap_doc_cursor'] = $cursor + 1;
        $state['last_url'] = $path;
        $state['last_status'] = $status;
        $state['last_reason'] = $fetch['reason'];

        // Collect page locs from body for phase D (do not enter D until A–C done).
        if ($fetch['ok'] && $fetch['body'] !== '') {
            $locs = $state['locs'] ?? [];
            if (!\is_array($locs)) {
                $locs = [];
            }
            foreach ($this->sitemapParser->extractPageLocs($fetch['body']) as $loc) {
                $pagePath = $this->sitemapParser->locToPath($loc, true);
                if ($pagePath === null) {
                    continue;
                }
                $locs[$pagePath] = $pagePath;
            }
            $state['locs'] = \array_values($locs);
        }

        return [
            'state' => $state,
            'sample' => ['status' => $status, 'url' => $path, 'reason' => $fetch['reason'], 'phase' => 'B'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,sample:array<string,mixed>}
     */
    private function runPhaseC(array $state): array
    {
        $extras = \is_array($state['extras'] ?? null) ? $state['extras'] : [];
        if ($extras === []) {
            $extras = $this->discoverExtras($state);
            $state['extras'] = $extras;
            $state['extras_cursor'] = 0;
        }
        $cursor = (int)($state['extras_cursor'] ?? 0);
        if ($cursor >= \count($extras)) {
            $state['phase'] = StorefrontWarmupProgressLedger::PHASE_D_LOCS;
            $state['loc_cursor'] = 0;

            return [
                'state' => $state,
                'sample' => ['status' => 'skip', 'url' => '', 'reason' => 'phase_c_complete', 'phase' => 'C'],
            ];
        }

        $path = (string)$extras[$cursor];
        $result = $this->warmPath($path);
        $state['extras_cursor'] = $cursor + 1;
        $state['last_url'] = $path;
        $state['last_status'] = $result['status'];
        $state['last_reason'] = $result['reason'];

        return [
            'state' => $state,
            'sample' => ['status' => $result['status'], 'url' => $path, 'reason' => $result['reason'], 'phase' => 'C'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,sample:array<string,mixed>}
     */
    private function runPhaseD(array $state): array
    {
        $locs = \is_array($state['locs'] ?? null) ? $state['locs'] : [];
        $cursor = (int)($state['loc_cursor'] ?? 0);
        if ($cursor >= \count($locs)) {
            $state['phase'] = StorefrontWarmupProgressLedger::PHASE_DONE;

            return [
                'state' => $state,
                'sample' => ['status' => 'done', 'url' => '', 'reason' => 'phase_d_complete', 'phase' => 'D'],
            ];
        }

        $path = (string)$locs[$cursor];
        $result = $this->warmPath($path);
        $state['loc_cursor'] = $cursor + 1;
        $state['last_url'] = $path;
        $state['last_status'] = $result['status'];
        $state['last_reason'] = $result['reason'];

        return [
            'state' => $state,
            'sample' => ['status' => $result['status'], 'url' => $path, 'reason' => $result['reason'], 'phase' => 'D'],
        ];
    }

    /**
     * @return array{status:string,reason:string}
     */
    private function warmPath(string $rawPath): array
    {
        $gate = $this->excludeGate->evaluate($rawPath);
        if (!$gate['allow']) {
            return ['status' => 'skip', 'reason' => $gate['reason']];
        }
        $path = $gate['path'];
        $fetch = $this->http->get($path, false);

        return [
            'status' => $fetch['ok'] ? 'ok' : 'fail',
            'reason' => $fetch['ok'] ? ($fetch['fpc'] !== '' ? $fetch['fpc'] : 'http_ok') : $fetch['reason'],
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverSitemapDocuments(): array
    {
        $indexPath = '/sitemap.xml';
        $docs = [$indexPath];
        $fetch = $this->http->get($indexPath, true);
        if (!$fetch['ok'] || $fetch['body'] === '') {
            return $docs;
        }
        foreach ($this->sitemapParser->extractSitemapDocUrls($fetch['body']) as $loc) {
            $path = $this->sitemapParser->locToPath($loc, false);
            if ($path === null) {
                continue;
            }
            // Keep XML sitemap documents in phase B (index ∪ shards).
            $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
            if (\str_ends_with(\strtolower($pathOnly), '.xml')
                || \str_contains($pathOnly, 'sitemap')
            ) {
                $docs[] = $pathOnly;
            }
        }

        return \array_values(\array_unique($docs));
    }

    /**
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private function discoverExtras(array $state): array
    {
        $paths = [];
        try {
            $provider = ObjectManager::getInstance(FpcWarmupProviderInterface::class);
            if ($provider instanceof FpcWarmupProviderInterface) {
                foreach ($provider->warmupPaths() as $raw) {
                    $path = $this->excludeGate->normalizePath((string)$raw);
                    if ($path === '' || $path === '/') {
                        continue;
                    }
                    $paths[$path] = $path;
                }
            }
        } catch (\Throwable) {
        }

        // Dedupe against locs already queued for phase D.
        $locSet = [];
        foreach (\is_array($state['locs'] ?? null) ? $state['locs'] : [] as $loc) {
            $locSet[(string)$loc] = true;
        }
        $out = [];
        foreach ($paths as $path) {
            if (isset($locSet[$path])) {
                continue;
            }
            $gate = $this->excludeGate->evaluate($path);
            if (!$gate['allow']) {
                continue;
            }
            $out[] = $gate['path'];
        }

        return \array_values($out);
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $dir = (\defined('BP') ? (string)BP : '') . 'var/cache';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . self::LOCK_NAME;
        $fh = @\fopen($path, 'c+');
        if ($fh === false) {
            return null;
        }
        if (!\flock($fh, \LOCK_EX | \LOCK_NB)) {
            \fclose($fh);

            return null;
        }

        return $fh;
    }

    /**
     * @param resource $lock
     */
    private function releaseLock($lock): void
    {
        try {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        } catch (\Throwable) {
        }
    }

    private function elapsedMs(float $startedAt): float
    {
        return \round((\microtime(true) - $startedAt) * 1000, 2);
    }

    private function intEnv(string $key, int $default, int $min, int $max): int
    {
        $raw = Env::get($key, $default);
        if (!\is_numeric($raw)) {
            return $default;
        }

        return \max($min, \min($max, (int)$raw));
    }
}
