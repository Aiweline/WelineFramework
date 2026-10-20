<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FpcWarmupProviderInterface;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

/**
 * Framework-owned storefront FPC warm orchestration (phases A→B→C→D).
 *
 * WLS Workers must not schedule storefront warm; cron / Clear call this only.
 */
final class StorefrontWarmupOrchestrator
{
    private const LOCK_NAME = 'storefront_warmup_orchestrator.lock';
    private const OFFER_DRAIN_PER_SLICE = 3;
    /**
     * Locale homepage roots are warmed before the sitemap crawl. A cold locale
     * home is a full SSR (multi-second) while the sitemap cursor may be tens of
     * thousands of locs away; one priority home per slice bounds the extra work.
     */
    private const PRIORITY_HOME_BUDGET_PER_SLICE = 1;
    private const PRIORITY_HOME_MAX = 8;

    public function __construct(
        private readonly StorefrontWarmupProgressLedger $ledger = new StorefrontWarmupProgressLedger(),
        private readonly StorefrontWarmupBusyGate $busyGate = new StorefrontWarmupBusyGate(),
        private readonly StorefrontWarmupExcludeGate $excludeGate = new StorefrontWarmupExcludeGate(),
        private readonly StorefrontWarmupHttpClient $http = new StorefrontWarmupHttpClient(),
        private readonly StorefrontWarmupSitemapParser $sitemapParser = new StorefrontWarmupSitemapParser(),
        private readonly StorefrontWarmupOfferQueue $offerQueue = new StorefrontWarmupOfferQueue(),
        private readonly StorefrontWarmupCapacityPlanner $capacityPlanner = new StorefrontWarmupCapacityPlanner(),
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
     * Cron tick: resume ledger; designed budget + traffic bands (no sticky busy backoff).
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
            // Clear legacy sticky busy backoff so idle ticks resume designed budget.
            if (($state['backoff_until'] ?? 0) > 0) {
                $state['backoff_until'] = 0.0;
            }
            $state = $this->syncPriorityHomes($state);

            $busy = $this->busyGate->sample();
            $capacity = $this->capacityPlanner->plan($state, $busy);
            if ($source !== 'clear' && $capacity['band'] === StorefrontWarmupCapacityPlanner::BAND_YIELD) {
                $state['last_status'] = 'skip';
                $state['last_reason'] = 'yield_traffic';
                $state['last_yield_at'] = \microtime(true);
                $state['backoff_until'] = 0.0;
                $this->ledger->write($state);

                return [
                    'ok' => true,
                    'reason' => 'yield_traffic',
                    'source' => $source,
                    'busy' => $busy,
                    'capacity' => $capacity,
                    'phase' => $state['phase'],
                    'warmed' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'designed_conc' => (int)$capacity['designed_concurrency'],
                    'designed_shard' => (int)$capacity['designed_shard'],
                    'effective_conc' => 0,
                    'effective_shard' => 0,
                    'band' => StorefrontWarmupCapacityPlanner::BAND_YIELD,
                    'idle_ratio' => 1.0 - (float)$capacity['ratio'],
                    'remaining' => (int)$capacity['remaining'],
                    'elapsed_ms' => $this->elapsedMs($startedAt),
                ];
            }

            $shard = (int)$capacity['effective_shard'];
            $wallMs = (int)$capacity['effective_wall_ms'];
            $concurrency = \max(1, (int)$capacity['effective_concurrency']);
            $warmed = 0;
            $skipped = 0;
            $failed = 0;
            $samples = [];
            // One priority-home attempt per slice: on failure keep the cursor so
            // the next cron tick retries instead of waiting for a full cycle.
            $priorityAttemptedThisSlice = false;

            // Priority: Changed / event ad-hoc offers before phased A–D crawl.
            $offerBudget = self::OFFER_DRAIN_PER_SLICE;
            while ($offerBudget > 0 && $warmed + $skipped + $failed < $shard) {
                if ($this->elapsedMs($startedAt) >= $wallMs) {
                    break;
                }
                $offerStep = $this->drainOneOffer();
                if ($offerStep === null) {
                    break;
                }
                $offerBudget--;
                $samples[] = $offerStep['sample'];
                $state['last_url'] = (string)($offerStep['sample']['url'] ?? '');
                $state['last_status'] = (string)($offerStep['sample']['status'] ?? '');
                $state['last_reason'] = (string)($offerStep['sample']['reason'] ?? '');
                if ($offerStep['sample']['status'] === 'ok') {
                    $warmed++;
                    $state['ok'] = (int)$state['ok'] + 1;
                } elseif ($offerStep['sample']['status'] === 'skip') {
                    $skipped++;
                    $state['skip'] = (int)$state['skip'] + 1;
                } elseif ($offerStep['sample']['status'] === 'fail') {
                    $failed++;
                    $state['fail'] = (int)$state['fail'] + 1;
                }
                $this->ledger->write($state);
            }

            while ($warmed + $skipped + $failed < $shard) {
                if ($this->elapsedMs($startedAt) >= $wallMs) {
                    break;
                }
                $midBusy = $this->busyGate->sample();
                if ($source !== 'clear'
                    && $this->busyGate->shouldAbortMidSlice((float)$midBusy['ratio'], (float)$midBusy['abort_threshold'])
                ) {
                    $state['last_status'] = 'skip';
                    $state['last_reason'] = 'abort_busy';
                    $state['last_yield_at'] = \microtime(true);
                    $state['backoff_until'] = 0.0;
                    $this->ledger->write($state);
                    break;
                }

                if (!$priorityAttemptedThisSlice && $this->hasPendingPriorityHomes($state)) {
                    $priorityAttemptedThisSlice = true;
                    $priorityRoom = $shard - ($warmed + $skipped + $failed);
                    $priority = $this->advancePriorityHomes(
                        $state,
                        \max(1, \min(self::PRIORITY_HOME_BUDGET_PER_SLICE, $priorityRoom))
                    );
                    $state = $priority['state'];
                    foreach ($priority['samples'] as $sample) {
                        $samples[] = $sample;
                        if (($sample['status'] ?? '') === 'ok') {
                            $warmed++;
                            $state['ok'] = (int)$state['ok'] + 1;
                        } elseif (($sample['status'] ?? '') === 'skip') {
                            $skipped++;
                            $state['skip'] = (int)$state['skip'] + 1;
                        } elseif (($sample['status'] ?? '') === 'fail') {
                            $failed++;
                            $state['fail'] = (int)$state['fail'] + 1;
                        }
                    }
                    $this->ledger->write($state);
                    continue;
                }

                $room = $shard - ($warmed + $skipped + $failed);
                $batchN = \max(1, \min($concurrency, $room));
                $batch = $this->advanceBatch($state, $batchN);
                $state = $batch['state'];
                foreach ($batch['samples'] as $sample) {
                    $samples[] = $sample;
                    if (($sample['status'] ?? '') === 'ok') {
                        $warmed++;
                        $state['ok'] = (int)$state['ok'] + 1;
                    } elseif (($sample['status'] ?? '') === 'skip') {
                        $skipped++;
                        $state['skip'] = (int)$state['skip'] + 1;
                    } elseif (($sample['status'] ?? '') === 'fail') {
                        $failed++;
                        $state['fail'] = (int)$state['fail'] + 1;
                    }
                }
                $this->ledger->write($state);
                if (($batch['done'] ?? false) === true) {
                    break;
                }
                if ($batch['samples'] === []) {
                    break;
                }
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
                'offer_pending' => $this->offerQueue->pendingCount(),
                'samples' => \array_slice($samples, 0, 12),
                'busy' => $busy,
                'capacity' => $capacity,
                'designed_conc' => (int)$capacity['designed_concurrency'],
                'designed_shard' => (int)$capacity['designed_shard'],
                'effective_conc' => $concurrency,
                'effective_shard' => $shard,
                'band' => (string)$capacity['band'],
                'idle_ratio' => 1.0 - (float)$capacity['ratio'],
                'remaining' => (int)$capacity['remaining'],
                'elapsed_ms' => $this->elapsedMs($startedAt),
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Keep the persisted priority-home queue in sync with configuration.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function syncPriorityHomes(array $state): array
    {
        $paths = $this->priorityHomePaths();
        $existing = \is_array($state['priority_homes'] ?? null) ? $state['priority_homes'] : [];
        if ($paths !== $existing) {
            $state['priority_homes'] = $paths;
            $state['priority_home_cursor'] = 0;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasPendingPriorityHomes(array $state): bool
    {
        $paths = \is_array($state['priority_homes'] ?? null) ? $state['priority_homes'] : [];

        return (int)($state['priority_home_cursor'] ?? 0) < \count($paths);
    }

    /**
     * Warm the next priority locale home(s) before the long sitemap crawl.
     *
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>}
     */
    private function advancePriorityHomes(array $state, int $n): array
    {
        $paths = \is_array($state['priority_homes'] ?? null) ? $state['priority_homes'] : [];
        $cursor = (int)($state['priority_home_cursor'] ?? 0);
        if ($cursor >= \count($paths)) {
            return ['state' => $state, 'samples' => []];
        }

        $samples = [];
        $processed = 0;
        while ($processed < \max(1, $n) && $cursor < \count($paths)) {
            $raw = (string)$paths[$cursor];
            $cursor++;
            $processed++;
            $gate = $this->excludeGate->evaluate($raw);
            if (!$gate['allow']) {
                $samples[] = ['status' => 'skip', 'url' => $raw, 'reason' => $gate['reason'], 'phase' => 'priority_home'];
                $state['last_url'] = $raw;
                $state['last_status'] = 'skip';
                $state['last_reason'] = $gate['reason'];
                continue;
            }
            // Visitor path: a MISS renders/publishes; a warm receipt stays a
            // cheap HIT. Pierce/bypass would mark the response no-store.
            $fetch = $this->http->getVisitor($gate['path']);
            $status = ($fetch['ok'] ?? false) ? 'ok' : 'fail';
            $reason = ($fetch['ok'] ?? false)
                ? (((string)($fetch['fpc'] ?? '')) !== '' ? (string)$fetch['fpc'] : 'http_ok')
                : (string)($fetch['reason'] ?? 'fail');
            $state['last_url'] = $gate['path'];
            $state['last_status'] = $status;
            $state['last_reason'] = $reason;
            $samples[] = ['status' => $status, 'url' => $gate['path'], 'reason' => $reason, 'phase' => 'priority_home'];
            if ($status === 'fail') {
                // Keep this priority home pending; next cron slice retries it.
                $cursor--;
                break;
            }
        }
        $state['priority_home_cursor'] = $cursor;

        return ['state' => $state, 'samples' => $samples];
    }

    /**
     * Priority locale homepage roots. Explicit config wins; otherwise the first
     * enabled non-default language is used so a cold multi-locale storefront does
     * not wait for its sitemap loc to come up.
     *
     * @return list<string>
     */
    private function priorityHomePaths(): array
    {
        $configured = [];
        try {
            $configured = Env::get('wls.storefront_warmup.priority_home_paths', []);
        } catch (\Throwable) {
            $configured = [];
        }
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $paths = [];
        if (\is_array($configured)) {
            foreach ($configured as $raw) {
                if (!\is_scalar($raw)) {
                    continue;
                }
                $path = $this->excludeGate->normalizePath((string)$raw);
                if ($path === '' || $path === '/') {
                    continue;
                }
                $gate = $this->excludeGate->evaluate($path);
                if (!$gate['allow']) {
                    continue;
                }
                $paths[$gate['path']] = $gate['path'];
            }
        }

        if ($paths === []) {
            foreach ($this->fallbackPriorityHomePaths() as $path) {
                $paths[$path] = $path;
            }
        }

        return \array_slice(\array_values($paths), 0, self::PRIORITY_HOME_MAX);
    }

    /**
     * @return list<string>
     */
    private function fallbackPriorityHomePaths(): array
    {
        try {
            return $this->selectFallbackPriorityHome(
                WebsiteData::languageCodesForWebsite(Website::ID_DEFAULT),
                (string)(WebsiteData::defaultLanguageForWebsite(Website::ID_DEFAULT) ?? '')
            );
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * English is the common international entry: prefer en_GB, then any en_*,
     * then the first enabled non-default language.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private function selectFallbackPriorityHome(array $codes, string $default): array
    {
        $normalizedDefault = \strtolower(\str_replace('-', '_', $default));
        $nonDefault = [];
        foreach ($codes as $code) {
            $code = \trim((string)$code, '/');
            $normalized = \strtolower(\str_replace('-', '_', $code));
            if ($normalized === '' || $normalized === $normalizedDefault) {
                continue;
            }
            $nonDefault[] = $code;
        }
        foreach ($nonDefault as $code) {
            if (\strtolower(\str_replace('-', '_', $code)) === 'en_gb') {
                return ['/' . $code . '/'];
            }
        }
        foreach ($nonDefault as $code) {
            if (\str_starts_with(\strtolower(\str_replace('-', '_', $code)), 'en_')) {
                return ['/' . $code . '/'];
            }
        }

        return $nonDefault !== [] ? ['/' . $nonDefault[0] . '/'] : [];
    }

    /**
     * Advance up to N warm steps in the current phase (HTTP via getMany when possible).
     *
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>,done:bool}
     */
    private function advanceBatch(array $state, int $n): array
    {
        $n = \max(1, $n);
        $phase = (string)($state['phase'] ?? StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
        if ($phase === StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE
            || $phase === StorefrontWarmupProgressLedger::PHASE_DONE
            || $n === 1
        ) {
            $step = $this->advanceOne($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => (($step['sample']['status'] ?? '') === 'done'),
            ];
        }

        return match ($phase) {
            StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS => $this->advanceBatchPhaseB($state, $n),
            StorefrontWarmupProgressLedger::PHASE_C_EXTRAS => $this->advanceBatchPhaseC($state, $n),
            StorefrontWarmupProgressLedger::PHASE_D_LOCS => $this->advanceBatchPhaseD($state, $n),
            default => [
                'state' => $state,
                'samples' => [['status' => 'done', 'url' => '', 'reason' => 'phase_done']],
                'done' => true,
            ],
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>,done:bool}
     */
    private function advanceBatchPhaseB(array $state, int $n): array
    {
        $docs = \is_array($state['sitemap_docs'] ?? null) ? $state['sitemap_docs'] : [];
        if ($docs === []) {
            $step = $this->runPhaseB($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => false,
            ];
        }
        $cursor = (int)($state['sitemap_doc_cursor'] ?? 0);
        if ($cursor >= \count($docs)) {
            $step = $this->runPhaseB($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => false,
            ];
        }

        $planned = [];
        $i = $cursor;
        while (\count($planned) < $n && $i < \count($docs)) {
            $path = (string)$docs[$i];
            $gate = $this->excludeGate->evaluate($path);
            $isXml = \str_ends_with(\strtolower((string)(\parse_url($path, \PHP_URL_PATH) ?: $path)), '.xml')
                || $path === '/sitemap.xml'
                || \str_contains($path, '/sitemap');
            if (!$isXml && !$gate['allow']) {
                $planned[] = ['path' => $path, 'skip' => true, 'reason' => $gate['reason'], 'index' => $i];
            } else {
                $planned[] = ['path' => $path, 'skip' => false, 'reason' => '', 'index' => $i];
            }
            $i++;
        }

        $fetchPaths = [];
        $fetchMap = [];
        foreach ($planned as $idx => $row) {
            if ($row['skip']) {
                continue;
            }
            $fetchMap[\count($fetchPaths)] = $idx;
            $fetchPaths[] = $row['path'];
        }
        $fetches = $fetchPaths === [] ? [] : $this->http->getMany($fetchPaths, true);
        $samples = [];
        foreach ($planned as $idx => $row) {
            $path = $row['path'];
            if ($row['skip']) {
                $samples[] = ['status' => 'skip', 'url' => $path, 'reason' => $row['reason'], 'phase' => 'B'];
                $state['last_url'] = $path;
                $state['last_status'] = 'skip';
                $state['last_reason'] = $row['reason'];
                continue;
            }
            $fetchIdx = \array_search($idx, $fetchMap, true);
            $fetch = \is_int($fetchIdx) ? ($fetches[$fetchIdx] ?? ['ok' => false, 'reason' => 'missing', 'body' => '']) : ['ok' => false, 'reason' => 'missing', 'body' => ''];
            $status = ($fetch['ok'] ?? false) ? 'ok' : 'fail';
            $reason = ($fetch['ok'] ?? false) ? (string)($fetch['reason'] !== '' ? $fetch['reason'] : 'http_ok') : (string)($fetch['reason'] ?? 'fail');
            $state['last_url'] = $path;
            $state['last_status'] = $status;
            $state['last_reason'] = $reason;
            if (($fetch['ok'] ?? false) && ($fetch['body'] ?? '') !== '') {
                $locs = $state['locs'] ?? [];
                if (!\is_array($locs)) {
                    $locs = [];
                }
                foreach ($this->sitemapParser->extractPageLocs((string)$fetch['body']) as $loc) {
                    $pagePath = $this->sitemapParser->locToPath($loc, true);
                    if ($pagePath === null) {
                        continue;
                    }
                    $locs[$pagePath] = $pagePath;
                }
                $state['locs'] = \array_values($locs);
            }
            $samples[] = ['status' => $status, 'url' => $path, 'reason' => $reason, 'phase' => 'B'];
        }
        $state['sitemap_doc_cursor'] = $cursor + \count($planned);

        return ['state' => $state, 'samples' => $samples, 'done' => false];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>,done:bool}
     */
    private function advanceBatchPhaseC(array $state, int $n): array
    {
        $extras = \is_array($state['extras'] ?? null) ? $state['extras'] : [];
        if ($extras === []) {
            $step = $this->runPhaseC($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => false,
            ];
        }
        $cursor = (int)($state['extras_cursor'] ?? 0);
        if ($cursor >= \count($extras)) {
            $step = $this->runPhaseC($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => false,
            ];
        }

        return $this->advanceBatchWarmPaths($state, $extras, $cursor, $n, 'extras_cursor', 'C');
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>,done:bool}
     */
    private function advanceBatchPhaseD(array $state, int $n): array
    {
        $locs = \is_array($state['locs'] ?? null) ? $state['locs'] : [];
        $cursor = (int)($state['loc_cursor'] ?? 0);
        if ($cursor >= \count($locs)) {
            $step = $this->runPhaseD($state);

            return [
                'state' => $step['state'],
                'samples' => [$step['sample']],
                'done' => (($step['sample']['status'] ?? '') === 'done'),
            ];
        }

        return $this->advanceBatchWarmPaths($state, $locs, $cursor, $n, 'loc_cursor', 'D');
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string>|array<int, string> $paths
     * @return array{state:array<string,mixed>,samples:list<array<string,mixed>>,done:bool}
     */
    private function advanceBatchWarmPaths(
        array $state,
        array $paths,
        int $cursor,
        int $n,
        string $cursorKey,
        string $phaseLabel,
    ): array {
        $planned = [];
        $i = $cursor;
        while (\count($planned) < $n && $i < \count($paths)) {
            $raw = (string)$paths[$i];
            $gate = $this->excludeGate->evaluate($raw);
            if (!$gate['allow']) {
                $planned[] = ['path' => $raw, 'fetch' => null, 'skip' => true, 'reason' => $gate['reason']];
            } else {
                $planned[] = ['path' => $gate['path'], 'fetch' => $gate['path'], 'skip' => false, 'reason' => ''];
            }
            $i++;
        }
        $fetchPaths = [];
        $fetchMap = [];
        foreach ($planned as $idx => $row) {
            if ($row['skip'] || $row['fetch'] === null) {
                continue;
            }
            $fetchMap[\count($fetchPaths)] = $idx;
            $fetchPaths[] = $row['fetch'];
        }
        $fetches = $fetchPaths === [] ? [] : $this->http->getMany($fetchPaths, false);
        $samples = [];
        foreach ($planned as $idx => $row) {
            $path = $row['path'];
            if ($row['skip']) {
                $samples[] = ['status' => 'skip', 'url' => $path, 'reason' => $row['reason'], 'phase' => $phaseLabel];
                $state['last_url'] = $path;
                $state['last_status'] = 'skip';
                $state['last_reason'] = $row['reason'];
                continue;
            }
            $fetchIdx = \array_search($idx, $fetchMap, true);
            $fetch = \is_int($fetchIdx) ? ($fetches[$fetchIdx] ?? ['ok' => false, 'reason' => 'missing', 'fpc' => '']) : ['ok' => false, 'reason' => 'missing', 'fpc' => ''];
            $status = ($fetch['ok'] ?? false) ? 'ok' : 'fail';
            $reason = ($fetch['ok'] ?? false)
                ? (((string)($fetch['fpc'] ?? '')) !== '' ? (string)$fetch['fpc'] : 'http_ok')
                : (string)($fetch['reason'] ?? 'fail');
            $state['last_url'] = $path;
            $state['last_status'] = $status;
            $state['last_reason'] = $reason;
            $samples[] = ['status' => $status, 'url' => $path, 'reason' => $reason, 'phase' => $phaseLabel];
        }
        $state[$cursorKey] = $cursor + \count($planned);

        return ['state' => $state, 'samples' => $samples, 'done' => false];
    }

    /**
     * @return array{sample:array<string,mixed>}|null
     */
    private function drainOneOffer(): ?array
    {
        $taken = $this->offerQueue->take(1);
        if ($taken === []) {
            return null;
        }
        $row = $taken[0];
        $url = (string)($row['url'] ?? '');
        if ($url === '') {
            return [
                'sample' => ['status' => 'skip', 'url' => '', 'reason' => 'offer_empty', 'phase' => 'offer'],
            ];
        }
        // Absolute URLs skip excludeGate path-only rules; relative paths still gate.
        if (\preg_match('#^https?://#i', $url) !== 1) {
            $gate = $this->excludeGate->evaluate($url);
            if (!$gate['allow']) {
                return [
                    'sample' => [
                        'status' => 'skip',
                        'url' => $url,
                        'reason' => $gate['reason'],
                        'phase' => 'offer',
                        'source' => (string)($row['source'] ?? ''),
                    ],
                ];
            }
            $fetch = $this->http->get($gate['path'], false);
        } else {
            $fetch = $this->http->getAbsolute($url, false);
        }

        return [
            'sample' => [
                'status' => $fetch['ok'] ? 'ok' : 'fail',
                'url' => $url,
                'reason' => $fetch['ok']
                    ? ($fetch['fpc'] !== '' ? $fetch['fpc'] : 'http_ok')
                    : $fetch['reason'],
                'phase' => 'offer',
                'source' => (string)($row['source'] ?? ''),
            ],
        ];
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
}
