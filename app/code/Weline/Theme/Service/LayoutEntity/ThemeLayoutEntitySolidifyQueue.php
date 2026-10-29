<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseCaptureMode;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\Runtime;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Durable solidify queue: gate persists jobs; Cron drains them (FPM-safe).
 * On WLS, PostResponseTaskQueue remains an optional eager fast-path for the same job file.
 *
 * Slot contract: one pending file per layout dimension; unconsumed updates overwrite;
 * drain re-reads payload before delete so last-write-wins is not lost mid-bake.
 */
final class ThemeLayoutEntitySolidifyQueue
{
    /** @var array<string, true> */
    private static array $requestEnqueued = [];

    /** @var array<string, int> slotHash => unix time until which the gate must not re-enqueue */
    private static array $enqueueDeferredUntil = [];

    public const DEFAULT_ENQUEUE_DEFER_SECONDS = 5;

    public function __construct(
        private readonly ThemeLayoutEntitySolidifyLeaseStore $leases,
        private readonly ThemeLayoutEntitySolidifyStampStore $stamps,
        private readonly ThemeLayoutEntitySolidifyJobStore $jobs,
        private readonly ThemeLayoutEntityBakeCoordinator $bakeCoordinator,
        private readonly ThemeScopeVersionService $scopeVersions,
    ) {
    }

    /**
     * @return array{enqueued:bool,coalesced:bool,pending:bool,overwritten:bool}
     */
    public function enqueue(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFingerprint): array
    {
        $queueKey = 'theme-layout-solidify:' . $key->slotHash();
        if (isset(self::$requestEnqueued[$queueKey])) {
            // Same HTTP request already touched this slot — still refresh payload.
            $put = $this->jobs->put($key, $expectedFingerprint);

            return [
                'enqueued' => false,
                'coalesced' => true,
                'pending' => true,
                'overwritten' => (bool)($put['overwritten'] ?? true),
            ];
        }

        $leaseHeld = $this->leases->isPending($key);
        $put = $this->jobs->put($key, $expectedFingerprint);
        self::$requestEnqueued[$queueKey] = true;

        // WLS only: eager-drain new slots after response. Overwrite while lease held
        // relies on drain re-read; Cron picks leftover dirty slots.
        if (!empty($put['enqueued']) && !$leaseHeld && Runtime::isPersistent()) {
            PostResponseTaskQueue::enqueue(
                $queueKey,
                function () use ($key): void {
                    $this->drainOne($key);
                },
                0.0,
                PostResponseCaptureMode::None,
            );
        }

        return [
            'enqueued' => (bool)($put['enqueued'] ?? false),
            'coalesced' => (bool)($put['coalesced'] ?? false),
            'pending' => true,
            'overwritten' => (bool)($put['overwritten'] ?? false),
        ];
    }

    public function isPending(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        return $this->leases->isPending($key) || $this->jobs->has($key);
    }

    /**
     * Age (seconds) of this slot's pending state: `now - min(job.queued_at, lease mtime)`.
     *
     * `queued_at` is rewritten on every gate re-enqueue, so a bake that keeps running can
     * look brand new forever; the slot lease mtime (created when the drain acquires the
     * lease) is the oldest marker that actually survives. Null when neither marker exists.
     */
    public function pendingAgeSeconds(ThemeLayoutEntitySolidifySerialKey $key, ?int $now = null): ?int
    {
        $now ??= time();
        $startedAt = null;
        if ($this->jobs->has($key)) {
            $job = $this->jobs->read($key);
            $queuedAt = $job === null ? '' : trim((string)($job['queued_at'] ?? ''));
            if ($queuedAt !== '') {
                $queuedTs = strtotime($queuedAt);
                if (is_int($queuedTs)) {
                    $startedAt = $queuedTs;
                }
            }
        }
        $leaseAt = $this->leases->leaseStartedAt($key);
        if ($leaseAt !== null) {
            $startedAt = $startedAt === null ? $leaseAt : min($startedAt, $leaseAt);
        }
        if ($startedAt === null) {
            return null;
        }

        return max(0, $now - $startedAt);
    }

    /**
     * After a slot's pending budget is exceeded the gate must stop re-enqueueing it for a
     * short window, otherwise every request rewrites `queued_at` and the deadline never
     * fires. Per-worker statics: slots are few and expired entries are pruned.
     */
    public function deferEnqueue(
        ThemeLayoutEntitySolidifySerialKey $key,
        int $seconds = self::DEFAULT_ENQUEUE_DEFER_SECONDS,
    ): void {
        if (\count(self::$enqueueDeferredUntil) > 64) {
            self::pruneEnqueueDeferrals((string)$key->slotHash());
        }
        self::$enqueueDeferredUntil[(string)$key->slotHash()] = time() + max(1, $seconds);
    }

    public function isEnqueueDeferred(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        $id = (string)$key->slotHash();
        $until = self::$enqueueDeferredUntil[$id] ?? 0;
        if ($until < 1) {
            return false;
        }
        if ($until <= time()) {
            unset(self::$enqueueDeferredUntil[$id]);

            return false;
        }

        return true;
    }

    private static function pruneEnqueueDeferrals(string $keepId): void
    {
        $now = time();
        foreach (self::$enqueueDeferredUntil as $id => $until) {
            if ($until <= $now || (string)$id === $keepId) {
                unset(self::$enqueueDeferredUntil[$id]);
            }
        }
    }

    /**
     * Circuit breaker for a slot whose bake keeps failing.
     *
     * Open = `fail_count >= theme.layout_entity.solidify_circuit_failures` (default 3) and the
     * last failure is younger than `theme.layout_entity.solidify_circuit_cooldown_seconds`
     * (default 900). While open the request gate must serve the source template **without**
     * forbidding shared FPC and without re-enqueueing. After cooldown the circuit is half-open:
     * one more enqueue is allowed; success clears the record, failure re-opens it.
     *
     * @return array{open:bool,fail_count:int,failed_at:int,threshold:int,cooldown_seconds:int,reason:string}
     */
    public function circuitState(ThemeLayoutEntitySolidifySerialKey $key): array
    {
        $threshold = max(1, (int)(Env::get('theme.layout_entity.solidify_circuit_failures', 3) ?: 3));
        $cooldown = max(60, (int)(Env::get('theme.layout_entity.solidify_circuit_cooldown_seconds', 900) ?: 900));
        $state = $this->jobs->failureState($key);
        if ($state === null) {
            return [
                'open' => false,
                'fail_count' => 0,
                'failed_at' => 0,
                'threshold' => $threshold,
                'cooldown_seconds' => $cooldown,
                'reason' => '',
            ];
        }
        $open = $state['fail_count'] >= $threshold
            && (time() - $state['failed_at']) < $cooldown;

        return [
            'open' => $open,
            'fail_count' => $state['fail_count'],
            'failed_at' => $state['failed_at'],
            'threshold' => $threshold,
            'cooldown_seconds' => $cooldown,
            'reason' => $state['fail_reason'],
        ];
    }

    /**
     * 调度前置检查：是否有待排水的持久化 job（仅目录扫描，不做固化）。
     */
    public function hasPendingJobs(): bool
    {
        return $this->jobs->listPending(1) !== [];
    }

    /**
     * Cron / tests: drain up to $limit durable jobs.
     *
     * @return array{processed:int,skipped:int,failed:int,folded?:array{folded:int,removed:int,slots:int}}
     */
    public function drainPendingJobs(int $limit = 16): array
    {
        $fold = $this->jobs->foldLegacyMultiVersionJobs();
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($this->jobs->listPending($limit) as $job) {
            $key = $job['key'];
            $status = $this->drainOne($key, (string)($job['expected_fp'] ?? ''));
            if ($status === 'ok') {
                $processed++;
            } elseif ($status === 'fail') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'folded' => $fold,
        ];
    }

    /**
     * Run one job if lease acquired. Removes durable job file only on success when payload
     * is unchanged after bake (re-read). Dirty leftover stays for next drain.
     *
     * @return 'ok'|'skip'|'fail'
     */
    public function drainOne(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFp = ''): string
    {
        if (!$this->jobs->has($key)) {
            Env::log('theme/layout_solidify_drain', 'job_missing key=' . $key->toString());

            return 'skip';
        }
        if (!$this->leases->tryAcquire($key)) {
            Env::log('theme/layout_solidify_drain', 'lease_busy key=' . $key->toString());

            return 'skip';
        }
        $ok = false;
        $failed = false;
        $dirty = false;
        $failReason = '';
        try {
            $snap = $this->jobs->read($key);
            if ($snap === null) {
                Env::log('theme/layout_solidify_drain', 'job_missing key=' . $key->toString());

                return 'skip';
            }
            $bakeKey = $snap['key'];
            $fp = (string)$snap['expected_fp'];
            if ($fp === '') {
                $fp = $expectedFp;
            }
            $this->executeBake($bakeKey, $fp);

            $after = $this->jobs->read($key);
            if ($after === null) {
                $ok = true;
            } elseif ($this->payloadChanged($snap, $after)) {
                // Gate overwrote during bake — bake latest once more.
                $this->executeBake($after['key'], (string)$after['expected_fp']);
                $after2 = $this->jobs->read($key);
                if ($after2 !== null && $this->payloadChanged($after, $after2)) {
                    $dirty = true;
                } else {
                    $ok = true;
                }
            } else {
                $ok = true;
            }
        } catch (\Throwable $e) {
            $failed = true;
            $failReason = $e->getMessage();
            Env::log_error('theme/layout_solidify_queue', $failReason . ' key=' . $key->toString());
        } finally {
            $this->leases->release($key);
            if ($ok && !$dirty) {
                $this->jobs->delete($key);
                // Bake landed after earlier failures: pages may have entered shared FPC
                // rendered from the source template while the circuit was open — bump
                // the scoped theme generation so readers pick up the derived layout.
                if ($this->jobs->clearFailure($key)) {
                    $this->invalidateScopeAfterRecovery($key);
                }
            } elseif ($failed) {
                $this->jobs->moveToFailed($key, $failReason !== '' ? $failReason : 'drain_fail');
            }
            // dirty: leave slot file for next Cron tick
        }

        if ($dirty) {
            Env::log('theme/layout_solidify_drain', 'payload_dirty key=' . $key->toString());

            return 'skip';
        }

        return $ok ? 'ok' : ($failed ? 'fail' : 'skip');
    }

    /**
     * @param array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string} $a
     * @param array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string,path:string,queued_at:string} $b
     */
    private function payloadChanged(array $a, array $b): bool
    {
        if (!hash_equals((string)$a['expected_fp'], (string)$b['expected_fp'])) {
            return true;
        }
        $ka = $a['key'];
        $kb = $b['key'];

        return $ka->themeVersionId !== $kb->themeVersionId
            || $ka->contentRevision !== $kb->contentRevision;
    }

    /**
     * Run bake body (tests / forced). Prefer drainOne for lease + durable job lifecycle.
     *
     * @param array<string, mixed> $payload
     */
    public function runJob(ThemeLayoutEntitySolidifySerialKey $key, array $payload = []): void
    {
        $expectedFp = trim((string)($payload['expected_fp'] ?? ''));
        try {
            $this->executeBake($key, $expectedFp);
        } catch (\Throwable $e) {
            Env::log_error('theme/layout_solidify_queue', $e->getMessage() . ' key=' . $key->toString());
            throw $e;
        }
    }

    /**
     * Process several distinct-layout jobs under Fiber concurrency (same owner lock per identity).
     *
     * @param list<array{key:ThemeLayoutEntitySolidifySerialKey,expected_fp:string}> $jobs
     */
    public function runDistinctLayoutJobsConcurrently(array $jobs): void
    {
        $byLayout = [];
        foreach ($jobs as $job) {
            $key = $job['key'] ?? null;
            if (!$key instanceof ThemeLayoutEntitySolidifySerialKey) {
                continue;
            }
            $byLayout[$key->layoutDimension()] = $job;
        }
        if ($byLayout === []) {
            return;
        }
        if (count($byLayout) === 1) {
            $only = array_values($byLayout)[0];
            $this->jobs->put($only['key'], (string)($only['expected_fp'] ?? ''));
            $this->drainOne($only['key'], (string)($only['expected_fp'] ?? ''));

            return;
        }

        $fibers = [];
        foreach ($byLayout as $job) {
            /** @var ThemeLayoutEntitySolidifySerialKey $key */
            $key = $job['key'];
            $fp = (string)($job['expected_fp'] ?? '');
            $this->jobs->put($key, $fp);
            $fibers[] = new \Fiber(function () use ($key, $fp): void {
                $this->drainOne($key, $fp);
            });
        }
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        $pending = $fibers;
        while ($pending !== []) {
            $next = [];
            foreach ($pending as $fiber) {
                if (!$fiber->isTerminated()) {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                    if (!$fiber->isTerminated()) {
                        $next[] = $fiber;
                    }
                }
            }
            $pending = $next;
            if ($pending !== []) {
                usleep(1000);
            }
        }
    }

    private function executeBake(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFp): void
    {
        if ($key->themeId < 1) {
            return;
        }

        $version = $this->resolveVersionForBake($key);
        if ($version === null) {
            throw new \RuntimeException('theme_layout_solidify_version_unresolved');
        }

        // Selection may point at a draft lifecycle row; seal + mark so readers/bake agree.
        // Only persist when something actually changes: every save() emits a resource
        // change (namespace bump) and markPublished() invalidates chrome pointers — doing
        // that on each bake of an already-sealed, already-published version restarts
        // storefront warmup and drops hot caches for nothing.
        $needsSeal = false;
        if ((int)$version->getContentRevision() < 1) {
            $version->setContentRevision(1);
            $needsSeal = true;
        }
        if ((string)$version->getLifecycle() !== \Weline\Theme\Model\ThemeScopeVersion::LIFECYCLE_SEALED) {
            $version->setLifecycle(\Weline\Theme\Model\ThemeScopeVersion::LIFECYCLE_SEALED);
            $needsSeal = true;
        }
        if ($needsSeal) {
            $version->save();
        }
        if ($needsSeal || !$version->isPublished()) {
            $this->scopeVersions->markPublished($version);
        }

        $versionId = (int)$version->getVersionId();
        // rematerialize may return '' when candidate is intentionally null (no entity
        // intent → keep source template). Still stamp so RequestSolidifyGate stops
        // re-enqueueing on derived_missing alone.
        $this->bakeCoordinator->rematerializeVersionPageAt(
            $key->themeId,
            $key->canonicalScope,
            $versionId,
            $key->layoutType,
            $key->layoutOption,
            $key->area,
            $key->storeMode,
        );
        if ($key->layoutType !== 'mini-cart') {
            $this->bakeCoordinator->rematerializeVersionPageAt(
                $key->themeId,
                $key->canonicalScope,
                $versionId,
                'mini-cart',
                'default',
                $key->area,
                $key->storeMode,
            );
        }
        // Chrome RAW / digest drift may remain when page short-circuit skipped materialize.
        $this->bakeCoordinator->rematerializeChromeIfStale(
            $key->themeId,
            $key->canonicalScope,
            $versionId,
            $key->area,
            $key->storeMode,
        );

        if ($expectedFp === '') {
            $expectedFp = ObjectManager::getInstance(ThemeLayoutEntityRequestSolidifyGate::class)
                ->expectedInjectionFingerprint();
        }
        $stampKey = ThemeLayoutEntitySolidifySerialKey::fromParts(
            $key->themeId,
            $key->area,
            $key->canonicalScope,
            $key->storeMode,
            $key->layoutType,
            $key->layoutOption,
            $versionId,
            max(1, (int)$version->getContentRevision()),
        );
        // Slot-scoped stamp path: survives V bump with same fingerprint.
        $this->stamps->write($stampKey, $expectedFp);
        $this->stamps->write($key, $expectedFp);
    }

    /**
     * After a slot recovers from dead-letter, bump the scoped Theme generation so FPC
     * entries rendered from the source template (circuit open) are superseded.
     */
    private function invalidateScopeAfterRecovery(ThemeLayoutEntitySolidifySerialKey $key): void
    {
        try {
            $version = $this->resolveVersionForBake($key);
            if ($version === null) {
                return;
            }
            $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
                $key->themeId,
                $key->canonicalScope,
                $key->storeMode,
                $key->area,
                (int)$version->getVersionId(),
                'formal',
                max(1, (int)$version->getContentRevision()),
            );
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeCacheCleaner::class)
                ->invalidateAfterVersionPublish($identity, 'theme_layout_solidify_circuit_recovered');
        } catch (\Throwable $e) {
            Env::log_error('theme/layout_solidify_queue', 'circuit_recovery_invalidate_failed: ' . $e->getMessage() . ' key=' . $key->toString());
        }
    }

    private function resolveVersionForBake(ThemeLayoutEntitySolidifySerialKey $key): ?\Weline\Theme\Model\ThemeScopeVersion
    {
        $version = $this->scopeVersions->getPublished(
            $key->themeId,
            $key->canonicalScope,
            $key->storeMode,
            $key->area,
        );
        if ($version !== null) {
            return $version;
        }
        if ($key->themeVersionId > 0) {
            $loaded = (clone ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class))
                ->clearData()->clearQuery()->load($key->themeVersionId);
            if ((int)$loaded->getVersionId() === $key->themeVersionId
                && (int)$loaded->getThemeId() === $key->themeId
            ) {
                return $loaded;
            }
        }
        $current = $this->scopeVersions->getCurrent(
            $key->themeId,
            $key->canonicalScope,
            $key->storeMode,
            $key->area,
        );
        if ($current !== null) {
            return $current;
        }

        return $this->scopeVersions->ensureCurrent(
            $key->themeId,
            $key->canonicalScope,
            'website',
            null,
            $key->storeMode,
            $key->area,
        );
    }

    public static function resetRequestEnqueued(): void
    {
        self::$requestEnqueued = [];
        self::$enqueueDeferredUntil = [];
    }
}
