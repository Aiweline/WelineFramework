<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Covers the drain-soft tolerance added to the start/adoption publication path
 * (prepareAndStartUnlocked), which used to treat a worker-drain observation as a
 * fatal rejection while reloadUnlocked() already treated it as soft.
 *
 * The decision is pinned as pure logic because the full publication path cannot
 * be driven from a unit test: every collaborator is a final class without an
 * injection seam, and the real path runs nginx -t, live HTTP/TLS probes and the
 * lifecycle lock.
 */
final class ManagedNginxServiceDrainSoftFailureTest extends TestCase
{
    private const DRAIN_DEADLINE = 'old nginx workers did not drain before the reload generation deadline';
    private const DRAIN_PROBE = 'unable to prove old nginx worker generation drain';
    private const OTHER_REJECTION = 'managed nginx lifecycle lock timed out';

    private function fatal(
        array $lifecycle,
        bool $wasRunning = true,
        bool $upstreamBindingUnchanged = true,
    ): bool {
        $method = new \ReflectionMethod(
            ManagedNginxService::class,
            'lifecycleRejectionIsFatal',
        );
        $method->setAccessible(true);

        return (bool)$method->invoke(
            new ManagedNginxService(),
            $lifecycle,
            $wasRunning,
            $upstreamBindingUnchanged,
        );
    }

    private function fingerprint(string $conf): string
    {
        $method = new \ReflectionMethod(
            ManagedNginxService::class,
            'upstreamBindingFingerprint',
        );
        $method->setAccessible(true);

        return (string)$method->invoke(new ManagedNginxService(), $conf);
    }

    /**
     * The tolerated case: the reload succeeded, old workers merely linger on
     * long-lived connections, and the upstream binding is unchanged. The
     * publication must continue to the mandatory live proofs instead of rolling
     * back.
     */
    public function testDrainSoftFailureWithLiveGenerationCommits(): void
    {
        self::assertFalse($this->fatal([
            'ok' => false,
            'message' => self::DRAIN_DEADLINE,
        ]));
        self::assertFalse($this->fatal([
            'ok' => false,
            'message' => self::DRAIN_PROBE,
        ]));
    }

    /**
     * A changed upstream must stay fatal: a lingering old worker would keep
     * proxying to the previous backend for up to worker_shutdown_timeout.
     */
    public function testDrainSoftFailureWithChangedUpstreamRollsBack(): void
    {
        self::assertTrue($this->fatal([
            'ok' => false,
            'message' => self::DRAIN_DEADLINE,
        ], true, false));
    }

    /** An unprovable binding (missing before-image / unreadable conf) stays fatal. */
    public function testDrainSoftFailureWithUnprovenUpstreamRollsBack(): void
    {
        self::assertTrue($this->fatal([
            'ok' => false,
            'message' => self::DRAIN_DEADLINE,
        ], true, false));
    }

    /** B1: a fresh start has no previous worker generation to drain. */
    public function testDrainMessageOnFreshStartStaysFatal(): void
    {
        self::assertTrue($this->fatal([
            'ok' => false,
            'message' => self::DRAIN_DEADLINE,
        ], false, true));
    }

    /** Any rejection outside the two drain messages stays fatal. */
    public function testOtherLifecycleRejectionsStayFatal(): void
    {
        foreach ([
            ['ok' => false, 'message' => self::OTHER_REJECTION],
            ['ok' => false, 'message' => 'nginx -t failed'],
            ['ok' => false, 'message' => ''],
            ['ok' => false],
            [],
        ] as $lifecycle) {
            self::assertTrue($this->fatal($lifecycle));
        }
    }

    /** A successful lifecycle is never a rejection. */
    public function testSuccessfulLifecycleIsNotAFailure(): void
    {
        self::assertFalse($this->fatal(['ok' => true, 'message' => 'reloaded']));
        self::assertFalse($this->fatal(['ok' => true]));
    }

    public function testUpstreamFingerprintIgnoresFormattingButNotTargets(): void
    {
        $base = "upstream wls_backend {\n    server 127.0.0.1:9981;\n    server 127.0.0.1:9982;\n}\n";
        $reformatted = "upstream   wls_backend\n{\n  server 127.0.0.1:9981;\n  server   127.0.0.1:9982 ;\n}\n";
        $reordered = "upstream wls_backend {\n    server 127.0.0.1:9982;\n    server 127.0.0.1:9981;\n}\n";
        $changedPort = "upstream wls_backend {\n    server 127.0.0.1:9981;\n    server 127.0.0.1:9983;\n}\n";

        self::assertSame($this->fingerprint($base), $this->fingerprint($reformatted));
        self::assertSame($this->fingerprint($base), $this->fingerprint($reordered));
        self::assertNotSame($this->fingerprint($base), $this->fingerprint($changedPort));
    }

    public function testUpstreamFingerprintIsEmptyWhenTheBlockIsAbsentOrMalformed(): void
    {
        self::assertSame('', $this->fingerprint("server {\n    listen 80;\n}\n"));
        self::assertSame('', $this->fingerprint(''));
        self::assertSame('', $this->fingerprint("upstream wls_backend {\n}\n"));
        self::assertSame(
            '',
            $this->fingerprint("upstream wls_backend {\n    server 127.0.0.1:0;\n}\n"),
        );
    }

    /**
     * Contract guard: the tolerance must never replace the live proofs. Both
     * rollback branches stay in place right after their probe.
     */
    public function testLiveProofFailuresStillRollBackThePublication(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 9)
            . '/app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxService.php',
        );
        foreach (['if (!$configGenerationLive) {', 'if (!$tls13Live) {'] as $branch) {
            $start = \strpos($source, $branch);
            self::assertIsInt($start, $branch . ' must stay in prepareAndStartUnlocked');
            $tail = \substr($source, $start, 400);
            self::assertStringContainsString('restorePublishedConfig(', $tail, $branch);
        }
        self::assertStringContainsString('$drainSoftFailed = $wasRunning', $source);
        self::assertStringContainsString(
            'drainSoftFailureKeepsUpstreamBinding($rollback)',
            $source,
        );
    }
}
