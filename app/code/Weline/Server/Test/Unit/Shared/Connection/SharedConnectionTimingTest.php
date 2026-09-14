<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Connection;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Server\Shared\Connection\ConnectionPoolManager;
use Weline\Server\Shared\Connection\PooledConnection;

final class SharedConnectionTimingTest extends TestCase
{
    private array $previousDebugConfig;

    protected function setUp(): void
    {
        $this->previousDebugConfig = [
            'request_trace_max_spans' => Env::get('wls.debug.request_trace_max_spans', 4096),
        ];
        Env::getInstance()->applyRuntimeConfig([
            'wls' => ['debug' => ['request_trace_max_spans' => 32]],
        ]);
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context([
            'input' => ['uri' => '/connection-timing-test'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => $this->name()]],
        ]));
        self::assertTrue(RequestContext::isInitialized());
        RequestLifecycleTrace::reset();
        RequestLifecycleTrace::installPanelTraceOn();
        self::assertTrue(RequestLifecycleTrace::isEnabled());
    }

    protected function tearDown(): void
    {
        RequestLifecycleTrace::clearPanelTrace();
        RequestLifecycleTrace::reset();
        Context::leave();
        Runtime::resetModeCache();
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => $this->previousDebugConfig]]);
    }

    public function testSilentPeerReadStillTimesOutAndClosesWithEffectiveTimeoutInTrace(): void
    {
        [$socket, $peer] = $this->socketPair();
        $connection = new PooledConnection('127.0.0.1', 19001, 0.02, 0.01, '', false, 'Session', false);
        $this->setProperty($connection, 'socket', $socket);

        try {
            self::assertTrue($connection->isConnected());
            self::assertNull($connection->read());
            self::assertFalse($connection->isConnected());
            self::assertFalse(\is_resource($socket));

            $span = $this->span('wls.connection.read');
            self::assertSame('rpc', $span['category']);
            self::assertSame('timeout', $span['meta']['result']);
            self::assertSame('127.0.0.1', $span['meta']['host']);
            self::assertSame(19001, $span['meta']['port']);
            self::assertSame(0.01, $span['meta']['timeout_sec']);
            self::assertSame(0.02, $span['meta']['connect_timeout_sec']);
            self::assertGreaterThan(0.0, $span['duration_ms']);
            self::assertSame(0.0, RequestLifecycleTrace::exportCompactPayload()['summary']['wls_duration_ms']);
        } finally {
            $connection->close();
            if (\is_resource($peer)) {
                \fclose($peer);
            }
        }
    }

    public function testFullPoolAcquireTimeoutKeepsExistingBusyLeaseAndReportsRetries(): void
    {
        [$socket, $peer] = $this->socketPair();
        $connection = new PooledConnection('127.0.0.1', 19002, 0.02, 0.03, '', false, 'Session', false);
        $this->setProperty($connection, 'socket', $socket);
        // Build a private pool fixture without entering the process-global pool registry.
        $reflection = new \ReflectionClass(ConnectionPoolManager::class);
        $pool = $reflection->newInstanceWithoutConstructor();
        $reflection->getConstructor()->invoke($pool, '127.0.0.1', 19002, [
            'min_idle' => 0,
            'max_size' => 1,
            'connect_timeout' => 0.02,
            'timeout' => 0.03,
        ]);
        $slot = ['conn' => $connection, 'busy' => true, 'last_used' => \hrtime(true) / 1_000_000_000, 'lease_fiber_id' => 12345];
        $this->setProperty($pool, 'pool', [$slot]);

        try {
            self::assertNull($pool->acquire(0.003));
            self::assertSame([$slot], (new \ReflectionProperty($pool, 'pool'))->getValue($pool));
            self::assertTrue($connection->isConnected());
            self::assertSame(['idle' => 0, 'busy' => 1, 'total' => 1], $pool->getPoolMetrics());

            $span = $this->span('wls.pool.acquire');
            self::assertSame('rpc', $span['category']);
            self::assertSame('timeout', $span['meta']['result']);
            self::assertSame('127.0.0.1', $span['meta']['host']);
            self::assertSame(19002, $span['meta']['port']);
            self::assertSame(0.03, $span['meta']['timeout_sec']);
            self::assertSame(0.02, $span['meta']['connect_timeout_sec']);
            self::assertSame(1, $span['meta']['busy']);
            self::assertSame(0, $span['meta']['idle']);
            self::assertGreaterThan(0, $span['meta']['retry_count']);
        } finally {
            $connection->close();
            if (\is_resource($peer)) {
                \fclose($peer);
            }
        }
    }

    public function testMissingTokenStillRejectsConnectionAndRecordsOnlySafeAuthReason(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server);
        $address = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($address, \strrpos($address, ':') + 1);
        $missingTokenPath = \sys_get_temp_dir() . '/weline-test-secret-key-' . \bin2hex(\random_bytes(8)) . '/missing-token.json';
        $connection = new PooledConnection('127.0.0.1', $port, 0.05, 0.02, $missingTokenPath, false, 'Session', false);

        try {
            self::assertFalse(\file_exists($missingTokenPath));
            for ($index = 0; $index < 32; ++$index) {
                RequestLifecycleTrace::recordSpan('fixture.' . $index, 0.01, 'test');
            }
            $startedAt = \hrtime(true);
            self::assertFalse($connection->connect());
            $elapsedMs = (\hrtime(true) - $startedAt) / 1_000_000;
            self::assertFalse($connection->isConnected());
            self::assertCount(32, RequestLifecycleTrace::getSpans());

            $phases = RequestLifecycleTrace::getAggregateSummary()['phases'];
            self::assertArrayHasKey('wls.connection.auth.last_failure', $phases);
            $span = $phases['wls.connection.auth.last_failure'];
            self::assertSame('last_failure', $span['meta']['measurement']);
            self::assertGreaterThanOrEqual(0.0, $span['duration_ms']);
            self::assertLessThanOrEqual(\ceil($elapsedMs * 100) / 100, $span['duration_ms']);
            self::assertSame('failure', $span['meta']['result']);
            self::assertSame('token_unavailable', $span['meta']['reason']);
            self::assertSame('127.0.0.1', $span['meta']['host']);
            self::assertSame($port, $span['meta']['port']);
            self::assertSame(0.02, $span['meta']['timeout_sec']);
            self::assertSame(0.05, $span['meta']['connect_timeout_sec']);
            $trace = \array_merge(RequestLifecycleTrace::getSpans(), \array_values($phases));
            self::assertStringNotContainsString($missingTokenPath, \json_encode($trace, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('weline-test-secret-key-', \json_encode($trace, JSON_THROW_ON_ERROR));
            $metaKeys = \array_merge(...\array_map(
                static fn(array $item): array => \array_keys($item['meta'] ?? []),
                $trace,
            ));
            self::assertSame([], \array_values(\array_intersect(
                ['token', 'auth_token', 'token_file', 'token_file_path', 'key', 'value', 'sid', 'session_id'],
                $metaKeys,
            )));
        } finally {
            $connection->close();
            \fclose($server);
        }
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        \stream_set_blocking($pair[0], false);
        \stream_set_blocking($pair[1], false);
        return $pair;
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        (new \ReflectionProperty($object, $name))->setValue($object, $value);
    }

    private function span(string $name): array
    {
        $matches = \array_values(\array_filter(
            RequestLifecycleTrace::getSpans(),
            static fn(array $span): bool => $span['name'] === $name,
        ));
        self::assertCount(1, $matches, 'Expected one diagnostic span for the actual connection outcome.');
        return $matches[0];
    }
}
