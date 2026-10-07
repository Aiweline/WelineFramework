<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Async solidify queue: same serial_key coalesce; distinct layouts may Fiber-parallel.
 */
final class ThemeLayoutEntitySolidifyQueue
{
    /** @var array<string, true> */
    private static array $requestEnqueued = [];

    public function __construct(
        private readonly ThemeLayoutEntitySolidifyLeaseStore $leases,
        private readonly ThemeLayoutEntitySolidifyStampStore $stamps,
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
            return ['enqueued' => false, 'coalesced' => true, 'pending' => $this->leases->isPending($key)];
        }

        if ($this->leases->isPending($key)) {
            self::$requestEnqueued[$queueKey] = true;

            return ['enqueued' => false, 'coalesced' => true, 'pending' => true];
        }

        $acquired = $this->leases->tryAcquire($key);
        if (!$acquired) {
            self::$requestEnqueued[$queueKey] = true;

            return ['enqueued' => false, 'coalesced' => true, 'pending' => true];
        }

        self::$requestEnqueued[$queueKey] = true;
        $payload = [
            'serial' => $key->toString(),
            'theme_id' => $key->themeId,
            'area' => $key->area,
            'scope' => $key->canonicalScope,
            'store_mode' => $key->storeMode,
            'layout_type' => $key->layoutType,
            'layout_option' => $key->layoutOption,
            'theme_version_id' => $key->themeVersionId,
            'content_revision' => $key->contentRevision,
            'expected_fp' => $expectedFingerprint,
        ];

        PostResponseTaskQueue::enqueue($queueKey, function () use ($key, $payload): void {
            $this->runJob($key, $payload);
        });

        return ['enqueued' => true, 'coalesced' => false, 'pending' => true];
    }

    public function isPending(ThemeLayoutEntitySolidifySerialKey $key): bool
    {
        return $this->leases->isPending($key);
    }

    /**
     * Run one job immediately (tests / forced drain). Same serial rules as async.
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
        } finally {
            $this->leases->release($key);
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
            $this->runJob($only['key'], ['expected_fp' => (string)($only['expected_fp'] ?? '')]);

            return;
        }

        $fibers = [];
        foreach ($byLayout as $job) {
            /** @var ThemeLayoutEntitySolidifySerialKey $key */
            $key = $job['key'];
            $fp = (string)($job['expected_fp'] ?? '');
            $fibers[] = new \Fiber(function () use ($key, $fp): void {
                $this->runJob($key, ['expected_fp' => $fp]);
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
