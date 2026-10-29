<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

/**
 * Certificate-retirement probe deadline semantics (task-6, tester seat).
 *
 * Production symptom: `certificate retirement replay state rejected:
 * Certificate retirement state deadline was exhausted.` 816-6001 times/day for
 * six days on changanhanfu.
 *
 * Root cause (verified by reading the code, this file locks the behaviour):
 *   - Agent.php:631 passes `pendingRetirementIntents($now + 0.25)` where `$now`
 *     is the MONOTONIC TICK START captured at the top of the loop;
 *   - ProjectCertificateGenerationStore::retirementDeadlineRemaining()
 *     (:274-290) subtracts its own live `snapshotMonotonicNow()` and throws
 *     `Certificate retirement state deadline was exhausted.` as soon as
 *     `remaining <= 0.0`;
 *   - so any tick that already spent >250 ms of work before reaching the probe
 *     throws on every 1 Hz tick, regardless of queue contents.
 *
 * These tests are contract-independent: they pin the store-level boundary the
 * Agent-side fix must respect (compute the deadline from a FRESH monotonic read,
 * not from the tick start). The Agent-side/provider-side cases are added once
 * the task-6 implementation contract is frozen.
 *
 * Store clock is injected (`snapshotMonotonicClock`), so every case is
 * deterministic — no sleeps, no wall clock.
 */
final class CertificateRetirementProbeDeadlineTest extends TestCase
{
    private string $root = '';
    private float $now = 0.0;

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-retirement-probe-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->now = 1000.0;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /** Store wired to the deterministic monotonic clock of this test. */
    private function store(): ProjectCertificateGenerationStore
    {
        return new ProjectCertificateGenerationStore(
            $this->root,
            null,
            function (): float {
                return $this->now;
            },
        );
    }

    /**
     * REPRODUCTION: a deadline derived from the tick start is already in the
     * past once the tick spent more than 250 ms before the probe — the store
     * rejects the whole probe with the production error text.
     */
    public function testStaleTickStartDeadlineIsRejected(): void
    {
        $tickStart = $this->now;            // $now captured at Agent.php:284
        $this->now = $tickStart + 0.30;     // tick consumed 300 ms of work

        try {
            $this->store()->pendingRetirementIntents($tickStart + 0.25);
            self::fail('A stale tick-start deadline must not be accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Certificate retirement state deadline was exhausted.',
                $exception->getMessage(),
                'the production error text must be reproduced verbatim',
            );
        }
    }

    /**
     * FIX BEHAVIOUR: a deadline recomputed from the CURRENT monotonic clock
     * accepts the same probe under the same 300 ms tick workload.
     */
    public function testFreshMonotonicDeadlineAcceptsTheProbe(): void
    {
        $tickStart = $this->now;
        $this->now = $tickStart + 0.30;     // same 300 ms of tick work

        $pending = $this->store()->pendingRetirementIntents($this->now + 0.25);

        self::assertSame([], $pending, 'an empty retirement queue yields an empty batch');
    }

    /**
     * The 250 ms window is the exact inclusive boundary: spending
     * >= 250 ms before the probe is what turns a tick-start deadline stale.
     */
    public function testQuarterSecondBoundaryIsInclusive(): void
    {
        // 249 ms of tick work: the tick-start deadline still has 1 ms left.
        $tickStart = $this->now;
        $this->now = $tickStart + 0.249;
        self::assertSame(
            [],
            $this->store()->pendingRetirementIntents($tickStart + 0.25),
            'just inside the window must still be accepted',
        );

        // Exactly 250 ms: remaining == 0.0 -> rejected (inclusive boundary).
        $tickStart = $this->now;
        $this->now = $tickStart + 0.250;
        try {
            $this->store()->pendingRetirementIntents($tickStart + 0.25);
            self::fail('remaining == 0.0 must be treated as exhausted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('deadline was exhausted', $exception->getMessage());
        }

        // 251 ms: rejected as well.
        $tickStart = $this->now;
        $this->now = $tickStart + 0.251;
        try {
            $this->store()->pendingRetirementIntents($tickStart + 0.25);
            self::fail('remaining < 0.0 must be treated as exhausted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('deadline was exhausted', $exception->getMessage());
        }
    }

    /**
     * Clock rollback: the store compares against its own monotonic reading, so
     * a backwards jump only enlarges the remaining window (it must NOT throw,
     * and it must not be turned into an unbounded / negative wait either). A
     * forward jump must keep failing closed.
     */
    public function testClockRollbackCannotBreakOrReviveTheDeadlineFence(): void
    {
        $deadline = $this->now + 0.25;

        // Backwards jump: remaining grows -> fence still accepts (fail-open).
        $this->now -= 5.0;
        self::assertSame([], $this->store()->pendingRetirementIntents($deadline));

        // Forward jump past the deadline: fence fails closed.
        $this->now = $deadline + 5.0;
        try {
            $this->store()->pendingRetirementIntents($deadline);
            self::fail('A forward jump past the deadline must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('deadline was exhausted', $exception->getMessage());
        }

        // Non-finite deadline is a different, explicit rejection (never a silent
        // pass and never a warning).
        try {
            $this->store()->pendingRetirementIntents(\INF);
            self::fail('A non-finite deadline must be rejected explicitly.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Certificate retirement state deadline is invalid.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A 3-instance concurrent-tick model in one process: three agents, each with
     * its own monotonic clock and tick-start deadline, interleaved round-robin.
     * The model asserts the two properties the user asked to protect:
     *   (a) a stale tick-start deadline rejects EVERY one of the three on every
     *       tick (the current production bug — amplified 3x by concurrency);
     *   (b) a fresh per-tick deadline from the current clock accepts all three,
     *       and interleaving does not let one instance block another (each
     *       instance performs exactly its own ticks, no shared-state leakage).
     */
    public function testConcurrentInstanceTicksAreIndependent(): void
    {
        $instances = ['inst-a', 'inst-b', 'inst-c'];
        $ticks = 8;
        $tickWorkSeconds = 0.30;   // > 250 ms: keeps the tick-start deadline stale
        $store = $this->store();

        // (a) Before the fix: tick-start deadline, 3 instances interleaved.
        $clocks = [];
        foreach ($instances as $index => $id) {
            $clocks[$id] = 1000.0 + $index * 0.01;
        }
        $staleRejections = 0;
        for ($tick = 0; $tick < $ticks; $tick++) {
            foreach ($instances as $id) {
                $tickStart = $clocks[$id];
                $clocks[$id] += $tickWorkSeconds;      // this tick's work
                $this->now = $clocks[$id];
                try {
                    $store->pendingRetirementIntents($tickStart + 0.25);
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('deadline was exhausted', $exception->getMessage());
                    $staleRejections++;
                }
            }
        }
        self::assertSame(
            $ticks * \count($instances),
            $staleRejections,
            'the stale tick-start deadline must reject every instance tick (bug reproduction)',
        );

        // (b) After the fix: fresh deadline per probe, same interleaving.
        $accepted = 0;
        for ($tick = 0; $tick < $ticks; $tick++) {
            foreach ($instances as $id) {
                $clocks[$id] += $tickWorkSeconds;      // this tick's work continues
                $this->now = $clocks[$id];
                $pending = $store->pendingRetirementIntents($this->now + 0.25);
                self::assertSame([], $pending);
                $accepted++;
            }
        }
        self::assertSame($ticks * \count($instances), $accepted);
    }

    /**
     * REAL-PROCESS concurrency supplement (user explicitly requires a local
     * concurrency test): three independent PHP processes probe the SAME
     * isolated retirement store at the same time. This exercises the real
     * filesystem lock + directory bootstrap + deadline fence across process
     * boundaries, which the in-process model above cannot cover.
     *
     * It deliberately does NOT start `server:gateway:agent`: a real agent needs
     * a live master lease, parent credential and a valid managed slot; faking
     * them would produce an unmanaged process that spawns full framework
     * children (5-13 CPU s each) and contends with the local dev WLS instance
     * that is running on this machine. See tester-report.md for that rationale.
     */
    public function testThreeRealProcessesProbeTheSameStoreConcurrently(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX process/locking semantics are required.');
        }

        $childCode = <<<'PHP'
$root = getenv('STORE_ROOT');
require getenv('APP_BOOTSTRAP');
$store = new \Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore(
    $root,
    null,
    static fn (): float => \hrtime(true) / 1_000_000_000,
);
$ok = 0;
$rejected = 0;
$errors = [];
for ($iteration = 0; $iteration < 25; $iteration++) {
    $now = \hrtime(true) / 1_000_000_000;
    try {
        $store->pendingRetirementIntents($now + 30.0);
        $ok++;
    } catch (\Throwable $throwable) {
        $rejected++;
        $errors[] = \get_class($throwable) . ': ' . $throwable->getMessage();
    }
}
echo \json_encode([
    'pid' => \getmypid(),
    'ok' => $ok,
    'rejected' => $rejected,
    'errors' => \array_slice($errors, 0, 3),
]);
PHP;

        $environment = \getenv();
        $environment['STORE_ROOT'] = $this->root;
        $environment['APP_BOOTSTRAP'] = \rtrim((string)\BP, '/\\')
            . '/app/bootstrap.php';

        $processes = [];
        for ($index = 0; $index < 3; $index++) {
            $process = \proc_open(
                [\PHP_BINARY, '-r', $childCode],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process, 'concurrent probe child must start');
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'output' => ''];
        }

        // Bounded wait: never let a hung child turn into a hung test run.
        $deadline = \hrtime(true) / 1_000_000_000 + 60.0;
        $pending = $processes;
        while ($pending !== []) {
            foreach ($pending as $key => $entry) {
                $status = @\proc_get_status($entry['process']);
                if (\is_array($status) && ($status['running'] ?? false) === false) {
                    $entry['output'] = (string)@\stream_get_contents($entry['pipes'][1]);
                    $entry['stderr'] = (string)@\stream_get_contents($entry['pipes'][2]);
                    @\fclose($entry['pipes'][1]);
                    @\fclose($entry['pipes'][2]);
                    $entry['exit'] = (int)($status['exitcode'] ?? -1);
                    @\proc_close($entry['process']);
                    $processes[$key] = $entry;
                    unset($pending[$key]);
                }
            }
            if (\hrtime(true) / 1_000_000_000 > $deadline) {
                foreach ($pending as $entry) {
                    @\proc_terminate($entry['process'], 9);
                }
                self::fail('concurrent retirement probe children did not finish within 60s');
            }
            if ($pending !== []) {
                \usleep(5_000);
            }
        }

        $totalOk = 0;
        $totalRejected = 0;
        foreach ($processes as $index => $entry) {
            self::assertSame(0, $entry['exit'], 'probe child #' . $index . ' must exit cleanly');
            self::assertStringNotContainsString('Fatal error', $entry['stderr']);
            self::assertStringNotContainsString('Uncaught', $entry['stderr']);
            $decoded = \json_decode($entry['output'], true);
            self::assertIsArray($decoded, 'probe child #' . $index . ' must report JSON: '
                . $entry['output'] . $entry['stderr']);
            self::assertSame(25, $decoded['ok'], 'probe child #' . $index . ' must complete all probes');
            self::assertSame(0, $decoded['rejected'], 'probe child #' . $index
                . ' must not reject concurrent probes: ' . \json_encode($decoded['errors']));
            $totalOk += (int)$decoded['ok'];
            $totalRejected += (int)$decoded['rejected'];
        }

        self::assertSame(75, $totalOk, '3 processes x 25 probes must all succeed');
        self::assertSame(0, $totalRejected, 'real-process concurrency must not surface a deadline rejection');
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
