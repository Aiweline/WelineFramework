<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
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
            PostResponseTaskQueue::enqueue($queueKey, function () use ($key): void {
                $this->drainOne($key);
            });
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
            return 'skip';
        }
        if (!$this->leases->tryAcquire($key)) {
            return 'skip';
        }
        $ok = false;
        $failed = false;
        $dirty = false;
        $failReason = '';
        try {
            $snap = $this->jobs->read($key);
            if ($snap === null) {
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
            } elseif ($failed) {
                $this->jobs->moveToFailed($key, $failReason !== '' ? $failReason : 'drain_fail');
            }
            // dirty: leave slot file for next Cron tick
        }

        if ($dirty) {
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
        if ((int)$version->getContentRevision() < 1) {
            $version->setContentRevision(1);
        }
        if ((string)$version->getLifecycle() !== \Weline\Theme\Model\ThemeScopeVersion::LIFECYCLE_SEALED) {
            $version->setLifecycle(\Weline\Theme\Model\ThemeScopeVersion::LIFECYCLE_SEALED);
        }
        $version->save();
        $this->scopeVersions->markPublished($version);

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
    }
}
