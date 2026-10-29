<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\RetirementProbeDeadline;

/**
 * task-6 缺陷 A contract test: certificate-retirement probe deadline.
 *
 * Production symptom: `certificate retirement replay state rejected:
 * Certificate retirement state deadline was exhausted.` 816-6001 times/day for
 * six days on changanhanfu.
 *
 * Root cause (Agent.php:638 before the fix): the probe deadline was derived
 * from the MONOTONIC TICK START (`$now + 0.25`). A tick that already spent
 * >250 ms of work passes an already-expired deadline, and
 * ProjectCertificateGenerationStore::retirementDeadlineRemaining() (:274-290)
 * throws whenever `deadline - snapshotMonotonicNow() <= 0`.
 *
 * Frozen contract (Lead 2026-10-30):
 *
 *   final class RetirementProbeDeadline
 *   {
 *       public const BUDGET_SECONDS = 0.25;
 *       public function __construct(?callable $clock = null);
 *       public function forTick(float $tickDeadline): float;
 *   }
 *
 *   now = clock(); d = min(tickDeadline, now + 0.25);
 *   if (tickDeadline <= 0 || d <= now || !is_finite(tickDeadline)) d = now + 0.25;
 *   => returned value is ALWAYS strictly greater than now, and normally never
 *      exceeds the tick's remaining budget (bounded overshoot <= 0.25 s).
 */
final class RetirementProbeDeadlineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-retirement-deadline-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function deadline(float &$now): RetirementProbeDeadline
    {
        return new RetirementProbeDeadline(
            static function () use (&$now): float {
                return $now;
            },
        );
    }

    private function store(float &$now): ProjectCertificateGenerationStore
    {
        return new ProjectCertificateGenerationStore(
            $this->root,
            null,
            static function () use (&$now): float {
                return $now;
            },
        );
    }

    public function testContractSurface(): void
    {
        self::assertSame(0.25, RetirementProbeDeadline::BUDGET_SECONDS);
    }

    /** Normal tick: the probe window is now + BUDGET, never beyond the tick budget. */
    public function testReturnsBoundedWindowFromCurrentClock(): void
    {
        $now = 1000.0;
        $deadline = $this->deadline($now);

        $window = $deadline->forTick($now + 8.0);

        self::assertSame($now + RetirementProbeDeadline::BUDGET_SECONDS, $window);
        self::assertGreaterThan($now, $window, 'the deadline must be strictly in the future');
        self::assertLessThanOrEqual($now + 8.0, $window, 'must stay within the tick budget');
    }

    /** A tighter tick budget is honoured instead of being widened. */
    public function testNeverExceedsTickRemainingBudget(): void
    {
        $now = 1000.0;
        $deadline = $this->deadline($now);

        self::assertSame(1000.05, $deadline->forTick($now + 0.05));
        self::assertSame(1000.25, $deadline->forTick($now + 0.25));
        self::assertGreaterThan($now, $deadline->forTick($now + 0.05));
    }

    /**
     * THE FIX: a stale tick-start deadline (already expired when the probe
     * runs) is repaired to a fresh now + BUDGET window instead of being passed
     * through to the store.
     */
    public function testStaleTickStartDeadlineIsRepairedToFreshWindow(): void
    {
        $now = 1000.0;
        $tickStart = $now;
        $deadline = $this->deadline($now);

        // Tick already spent 300 ms: the tick-start deadline is 50 ms in the past.
        $now = $tickStart + 0.30;
        $repaired = $deadline->forTick($tickStart + RetirementProbeDeadline::BUDGET_SECONDS);
        self::assertSame($now + RetirementProbeDeadline::BUDGET_SECONDS, $repaired);
        self::assertGreaterThan($now, $repaired);

        // Deadline exactly at now is equally unusable ("d <= now" rule).
        $exactNow = $deadline->forTick($now);
        self::assertSame($now + RetirementProbeDeadline::BUDGET_SECONDS, $exactNow);
        self::assertGreaterThan($now, $exactNow);
    }

    /** Non-positive deadlines fall back to the fresh window. */
    public function testNonPositiveTickDeadlineUsesFreshWindow(): void
    {
        $now = 500.0;
        $deadline = $this->deadline($now);

        self::assertSame(500.25, $deadline->forTick(0.0), 'retirement mode without a tick deadline');
        self::assertSame(500.25, $deadline->forTick(-12.5));
    }

    /** Non-finite deadlines fall back to the fresh window (never propagate INF/NAN). */
    public function testNonFiniteTickDeadlineUsesFreshWindow(): void
    {
        $now = 700.0;
        $deadline = $this->deadline($now);

        foreach ([\INF, -\INF, \NAN] as $invalid) {
            $window = $deadline->forTick($invalid);
            self::assertTrue(\is_finite($window), 'deadline must stay finite');
            self::assertSame(700.25, $window);
        }
    }

    /**
     * Clock rollback: the deadline is recomputed from the CURRENT clock on
     * every call, so a backwards jump still yields a value strictly ahead of
     * the new now (never a past deadline), while a later clock read recovers.
     */
    public function testClockRollbackKeepsDeadlineAheadOfCurrentClock(): void
    {
        $now = 1000.0;
        $deadline = $this->deadline($now);
        self::assertSame(1000.25, $deadline->forTick(1008.0));

        $now = 900.0; // rolled back
        $rolledBack = $deadline->forTick(1008.0);
        self::assertSame(900.25, $rolledBack);
        self::assertGreaterThan(900.0, $rolledBack, 'rollback must not produce a past deadline');

        $now = 1100.0; // forward again
        self::assertSame(1100.25, $deadline->forTick(1108.0));
    }

    /**
     * End-to-end unit evidence: the repaired deadline satisfies the real store
     * fence that the old tick-start deadline violates.
     */
    public function testRepairedDeadlineSatisfiesTheStoreFence(): void
    {
        $now = 1000.0;
        $tickStart = $now;
        $tickDeadline = $tickStart + 8.0;
        $deadline = $this->deadline($now);
        $store = $this->store($now);

        // Tick work consumed 300 ms before the probe.
        $now = $tickStart + 0.30;

        // Old behaviour: tick-start-derived deadline is rejected by the store.
        try {
            $store->pendingRetirementIntents($tickStart + RetirementProbeDeadline::BUDGET_SECONDS);
            self::fail('the tick-start deadline must be rejected by the store fence');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Certificate retirement state deadline was exhausted.',
                $exception->getMessage(),
            );
        }

        // Fixed behaviour: repaired deadline is accepted.
        self::assertSame([], $store->pendingRetirementIntents($deadline->forTick($tickDeadline)));
    }

    /**
     * In-process 3-instance concurrent-tick model (user requirement: local
     * concurrency must pass). Each instance owns its clock + deadline object;
     * ticks interleave round-robin and every probe must succeed.
     *
     * Asserts: (a) no exception escapes from any instance, (b) no instance
     * blocks another (each performs exactly its own number of probes),
     * (c) deadlines never regress into the past, (d) concurrency does not
     * amplify work (1 probe per instance tick, no duplicates).
     */
    public function testThreeInterleavedInstancesProduceIndependentDeadlines(): void
    {
        $ticks = 10;
        $instances = [
            'inst-a' => 1000.0,
            'inst-b' => 1000.01,
            'inst-c' => 1000.02,
        ];
        $deadlines = [];
        foreach ($instances as $id => $start) {
            $clocks[$id] = $start;
            $deadlines[$id] = new RetirementProbeDeadline(
                static function () use (&$clocks, $id): float {
                    return $clocks[$id];
                },
            );
        }
        $probes = \array_fill_keys(\array_keys($instances), 0);
        $lastDeadline = [];

        for ($tick = 0; $tick < $ticks; $tick++) {
            foreach (\array_keys($instances) as $id) {
                $tickStart = $clocks[$id];
                $clocks[$id] += 0.30;                 // this tick's work
                $tickDeadline = $tickStart + 8.0;

                $window = $deadlines[$id]->forTick($tickDeadline);
                self::assertGreaterThan($clocks[$id], $window, $id . ': deadline must be in the future');
                self::assertLessThanOrEqual($tickDeadline, $window, $id . ': must respect tick budget');
                if (isset($lastDeadline[$id])) {
                    self::assertGreaterThan(
                        $lastDeadline[$id],
                        $window,
                        $id . ': deadline must advance with the clock, never regress',
                    );
                }
                $lastDeadline[$id] = $window;

                $store = new ProjectCertificateGenerationStore(
                    $this->root,
                    null,
                    static function () use (&$clocks, $id): float {
                        return $clocks[$id];
                    },
                );
                self::assertSame([], $store->pendingRetirementIntents($window));
                $probes[$id]++;
            }
        }

        // (b)+(d): exactly one probe per tick per instance, no amplification.
        foreach ($probes as $id => $count) {
            self::assertSame($ticks, $count, $id . ' must complete exactly its own ticks');
        }
        self::assertSame($ticks * \count($instances), \array_sum($probes));
    }

    private function removeTree(string $root): void
    {
        if ($root === '' || !\is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir() && !$entry->isLink()) {
                @\rmdir($entry->getPathname());
            } else {
                @\unlink($entry->getPathname());
            }
        }
        @\rmdir($root);
    }
}
