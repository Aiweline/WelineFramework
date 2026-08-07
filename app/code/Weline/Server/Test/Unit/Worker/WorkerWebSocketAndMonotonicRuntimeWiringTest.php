<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\TestCase;

final class WorkerWebSocketAndMonotonicRuntimeWiringTest extends TestCase
{
    /**
     * @dataProvider productionWorkers
     */
    public function testProductionWorkerWiresTheValidatedFrameDataPlane(string $script): void
    {
        $identifiers = $this->identifiers($script);

        self::assertContains('webSocketUpgradeAccepted', $identifiers);
        self::assertContains('webSocketConsumeClientBytes', $identifiers);
        self::assertContains('webSocketInitiateServerClose', $identifiers);
    }

    /**
     * @dataProvider productionWorkers
     */
    public function testProductionWorkerWiresMonotonicFiberIdleDecisions(string $script): void
    {
        $identifiers = $this->identifiers($script);

        self::assertContains('monotonicNowNs', $identifiers);
        self::assertContains('idleReleaseDecision', $identifiers);
    }

    public function testPlainWorkerFallbackExitUsesTheBoundedMonotonicDeadlineApi(): void
    {
        $identifiers = $this->identifiers('worker.php');

        self::assertContains('deadlineAfterSeconds', $identifiers);
        self::assertContains('deadlineReached', $identifiers);
        self::assertContains('monotonicElapsedSeconds', $identifiers);
    }

    public function testTlsWorkerWiresIncrementalHttp2SseWithoutLegacyRejection(): void
    {
        $source = $this->source('worker_ssl.php');
        $identifiers = $this->identifiers('worker_ssl.php');

        self::assertStringNotContainsString('HTTP/2 SSE streaming is not available', $source);
        self::assertContains('beginStreamingResponse', $identifiers);
        self::assertContains('appendStreamingData', $identifiers);
        self::assertContains('endStreamingResponse', $identifiers);
        self::assertContains('wlsSslIsHttp2SseStreamAlive', $identifiers);
        self::assertStringContainsString('HTTP/3 streaming is not available', $source);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function productionWorkers(): iterable
    {
        yield 'plain HTTP' => ['worker.php'];
        yield 'TLS HTTP/1.1' => ['worker_ssl.php'];
    }

    /**
     * @return list<string>
     */
    private function identifiers(string $script): array
    {
        $source = $this->source($script);

        $identifiers = [];
        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_STRING) {
                $identifiers[] = $token[1];
            }
        }

        return $identifiers;
    }

    private function source(string $script): string
    {
        $path = \dirname(__DIR__, 3) . '/bin/' . $script;
        $source = \file_get_contents($path);
        self::assertIsString($source, 'Unable to read production worker: ' . $path);

        return $source;
    }
}
