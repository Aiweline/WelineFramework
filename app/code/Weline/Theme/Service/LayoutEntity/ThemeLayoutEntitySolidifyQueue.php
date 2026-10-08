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
     * @return array{enqueued:bool,coalesced:bool,pending:bool}
     */
    public function enqueue(ThemeLayoutEntitySolidifySerialKey $key, string $expectedFingerprint): array
    {
        $queueKey = 'theme-layout-solidify:' . $key->hash();
        if (isset(self::$requestEnqueued[$queueKey])) {
            return ['enqueued' => false, 'coalesced' => true, 'pending' => $this->isPending($key)];
        }

        if ($this->leases->isPending($key) || $this->jobs->has($key)) {
            self::$requestEnqueued[$queueKey] = true;

            return ['enqueued' => false, 'coalesced' => true, 'pending' => true];
        }

        $put = $this->jobs->put($key, $expectedFingerprint);
        self::$requestEnqueued[$queueKey] = true;

        // WLS only: try to drain this job after the response (same durable job file).
        // FPM must wait for Cron — in-memory PostResponseTaskQueue is never drained there.
        if (!empty($put['enqueued']) && Runtime::isPersistent()) {
            PostResponseTaskQueue::enqueue($queueKey, function () use ($key): void {
                $this->drainOne($key);
            });
        }

        return [
            'enqueued' => (bool)($put['enqueued'] ?? false),
            'coalesced' => (bool)($put['coalesced'] ?? false),
            'pending' => true,
        ];
    }

    public function isPending(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        return $this->leases->isPending($key) || $this->jobs->has($key);
    }

    /**
     * Cron / tests: drain up to $limit durable jobs.
     *
     * @return array{processed:int,skipped:int,failed:int}
     */
    public function drainPendingJobs(int $limit = 16): array
    {
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

        return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Run one job if lease acquired. Removes durable job file only on success (failed jobs retry via Cron).
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
        try {
            if ($expectedFp === '') {
                foreach ($this->jobs->listPending(64) as $job) {
                    if ($job['key']->hash() === $key->hash()) {
                        $expectedFp = (string)$job['expected_fp'];
                        break;
                    }
                }
            }
            $this->executeBake($key, $expectedFp);
            $ok = true;
        } catch (\Throwable $e) {
            $failed = true;
            Env::log_error('theme/layout_solidify_queue', $e->getMessage() . ' key=' . $key->toString());
        } finally {
            $this->leases->release($key);
            if ($ok) {
                $this->jobs->delete($key);
            }
        }

        return $ok ? 'ok' : ($failed ? 'fail' : 'skip');
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
            // Ensure job file exists for drainOne when called from tests with raw keys.
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
