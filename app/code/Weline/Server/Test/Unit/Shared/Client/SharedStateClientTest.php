<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Client;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class SharedStateClientTest extends TestCase
{
    public function testDisconnectDoesNotShutdownProcessLevelPool(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::never())->method('shutdown');

        $client = $this->createClientWithPool($pool);
        $client->disconnect();

        self::assertTrue(true);
    }

    public function testShutdownPoolStillClosesUnderlyingPool(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('shutdown');

        $client = $this->createClientWithPool($pool);
        $client->shutdownPool();
    }

    private function createClientWithPool(ConnectionPoolInterface $pool): SharedStateClient
    {
        $reflection = new ReflectionClass(SharedStateClient::class);
        /** @var SharedStateClient $client */
        $client = $reflection->newInstanceWithoutConstructor();

        $poolProperty = $reflection->getProperty('pool');
        $poolProperty->setAccessible(true);
        $poolProperty->setValue($client, $pool);

        $timeoutProperty = $reflection->getProperty('acquireTimeout');
        $timeoutProperty->setAccessible(true);
        $timeoutProperty->setValue($client, 0.2);

        return $client;
    }

    public function testWithConnectionReleasesWhenCallbackReturnsArray(): void
    {
        $conn = $this->createMock(PooledConnectionInterface::class);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturn($conn);
        $pool->expects(self::once())->method('release')->with($conn);
        $pool->expects(self::never())->method('invalidate');

        $client = $this->createClientWithPool($pool);
        $method = (new ReflectionClass(SharedStateClient::class))->getMethod('withConnection');
        $method->setAccessible(true);

        $out = $method->invoke($client, static fn (PooledConnectionInterface $c): array => ['ok' => true]);

        self::assertSame(['ok' => true], $out);
    }

    public function testWithConnectionInvalidatesWhenCallbackThrows(): void
    {
        $conn = $this->createMock(PooledConnectionInterface::class);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturn($conn);
        $pool->expects(self::never())->method('release');
        $pool->expects(self::once())->method('invalidate')->with($conn);

        $client = $this->createClientWithPool($pool);
        $method = (new ReflectionClass(SharedStateClient::class))->getMethod('withConnection');
        $method->setAccessible(true);

        $out = $method->invoke($client, static function (PooledConnectionInterface $c): void {
            throw new \RuntimeException('simulated');
        });

        self::assertNull($out);
    }

    public function testRequestTraceMeasuresTheSentPayloadWithoutChangingTheResponse(): void
    {
        $previousSerializer = SessionProtocol::getSerializer();
        SessionProtocol::setSerializer(SessionProtocol::SERIALIZER_JSON);
        try {
            $this->withRequestTrace(true, function (): void {
                $encodedValue = new class implements \JsonSerializable {
                    public int $calls = 0;
                    public function jsonSerialize(): mixed
                    {
                        ++$this->calls;
                        return ['private_value' => 'rpc-fixture-secret-value'];
                    }
                };
                $params = ['ns' => 'cache:view', 'key' => 'rpc-fixture-secret-key', 'sid' => 'rpc-fixture-secret-sid', 'val' => $encodedValue];
                $response = ['ok' => true, 'data' => ['unchanged' => 7]];
                $wirePayload = '';
                $conn = $this->createMock(PooledConnectionInterface::class);
                $pool = $this->createMock(ConnectionPoolInterface::class);
                $pool->expects(self::once())->method('acquire')->willReturn($conn);
                $pool->expects(self::once())->method('release')->with($conn);
                $pool->expects(self::never())->method('invalidate');
                $conn->expects(self::once())->method('send')->willReturnCallback(static function (string $payload) use (&$wirePayload): bool {
                    $wirePayload = $payload;
                    return true;
                });
                $observedReadStartUs = null;
                $observedReadEndUs = null;
                $conn->expects(self::once())->method('read')->willReturnCallback(static function () use ($response, &$observedReadStartUs, &$observedReadEndUs): array {
                    $observedReadStartUs = intdiv(hrtime(true), 1000);
                    $observedReadEndUs = intdiv(hrtime(true), 1000);
                    return $response;
                });

                $client = $this->createClientWithPool($pool);
                $beforeRequestUs = intdiv(hrtime(true), 1000);
                self::assertSame($response, $client->request(SessionProtocol::CMD_SET, $params));
                $afterRequestUs = intdiv(hrtime(true), 1000);
                self::assertSame(1, $encodedValue->calls, 'Timing must reuse the sent payload rather than serialize it again.');
                $spans = $this->rpcSpans();
                self::assertCount(1, $spans);
                self::assertSame('rpc', $spans[0]['category']);
                $meta = $spans[0]['meta'];
                foreach (['rpc_start_monotonic_us', 'rpc_end_monotonic_us', 'read_start_monotonic_us', 'read_end_monotonic_us'] as $coordinate) {
                    self::assertArrayHasKey($coordinate, $meta);
                    self::assertIsInt($meta[$coordinate]);
                }
                self::assertGreaterThanOrEqual($beforeRequestUs, $meta['rpc_start_monotonic_us']);
                self::assertLessThanOrEqual($afterRequestUs, $meta['rpc_end_monotonic_us']);
                self::assertGreaterThanOrEqual($meta['rpc_start_monotonic_us'], $meta['read_start_monotonic_us']);
                self::assertLessThanOrEqual($observedReadStartUs, $meta['read_start_monotonic_us']);
                self::assertGreaterThanOrEqual($observedReadEndUs, $meta['read_end_monotonic_us']);
                self::assertLessThanOrEqual($meta['rpc_end_monotonic_us'], $meta['read_end_monotonic_us']);
                self::assertEqualsWithDelta(($meta['read_end_monotonic_us'] - $meta['read_start_monotonic_us']) / 1000, $meta['read_ms'], 0.002);
                self::assertEqualsWithDelta(($meta['rpc_end_monotonic_us'] - $meta['rpc_start_monotonic_us']) / 1000, $spans[0]['duration_ms'], 0.006);
                self::assertSame(strlen($wirePayload), $meta['encoded_bytes']);
                self::assertSame('cache:view', $meta['namespace']);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $meta['key_hash']);
                foreach (['acquire_ms', 'encode_ms', 'send_ms', 'read_ms', 'dispose_ms'] as $phase) {
                    self::assertIsNumeric($meta[$phase]);
                    self::assertGreaterThanOrEqual(0, $meta[$phase]);
                }
                self::assertTrue($meta['send_called']);
                self::assertTrue($meta['send_succeeded']);
                self::assertTrue($meta['read_called']);
                self::assertTrue($meta['response_received']);
                $logged = json_encode($spans, JSON_THROW_ON_ERROR);
                foreach (['rpc-fixture-secret-key', 'rpc-fixture-secret-sid', 'rpc-fixture-secret-value'] as $secret) {
                    self::assertStringNotContainsString($secret, $logged);
                }
                self::assertSame(0, RequestLifecycleTrace::getAggregateSummary()['wls_span_count'], 'An RPC diagnostic must not double-count the facade WLS total.');
            });
        } finally {
            SessionProtocol::setSerializer($previousSerializer);
        }
    }

    public function testRequestWithoutTraceKeepsTheResponseAndAddsNoDiagnosticSpan(): void
    {
        $this->withRequestTrace(false, function (): void {
            $response = ['ok' => true, 'data' => false];
            $conn = $this->createMock(PooledConnectionInterface::class);
            $pool = $this->createMock(ConnectionPoolInterface::class);
            $pool->expects(self::once())->method('acquire')->willReturn($conn);
            $pool->expects(self::once())->method('release')->with($conn);
            $pool->expects(self::never())->method('invalidate');
            $conn->expects(self::once())->method('send')->willReturn(true);
            $conn->expects(self::once())->method('read')->willReturn($response);

            self::assertFalse(RequestLifecycleTrace::isEnabled());
            self::assertSame($response, $this->createClientWithPool($pool)->request(SessionProtocol::CMD_GET, ['ns' => 'sess', 'key' => 'disabled-fixture-key']));
            self::assertSame([], RequestLifecycleTrace::getSpans());
            self::assertSame([], RequestLifecycleTrace::getAggregateSummary()['phases']);
        });
    }

    public function testFailedSendAndAcquireExceptionKeepTheirExistingContractsWithTrace(): void
    {
        $this->withRequestTrace(true, function (): void {
            $conn = $this->createMock(PooledConnectionInterface::class);
            $pool = $this->createMock(ConnectionPoolInterface::class);
            $pool->expects(self::once())->method('acquire')->willReturn($conn);
            $pool->expects(self::never())->method('release');
            $pool->expects(self::once())->method('invalidate')->with($conn);
            $conn->expects(self::once())->method('send')->willReturn(false);
            $conn->expects(self::never())->method('read');
            self::assertNull($this->createClientWithPool($pool)->request(SessionProtocol::CMD_SET, ['ns' => 'cache:product', 'key' => 'failed-fixture', 'val' => 'value']));
            $spans = $this->rpcSpans();
            self::assertCount(1, $spans);
            self::assertTrue($spans[0]['meta']['send_called']);
            self::assertFalse($spans[0]['meta']['send_succeeded']);
            self::assertFalse($spans[0]['meta']['read_called']);
            self::assertNull($spans[0]['meta']['read_ms']);
            foreach (['read_start_monotonic_us', 'read_end_monotonic_us'] as $coordinate) {
                self::assertArrayHasKey($coordinate, $spans[0]['meta']);
                self::assertNull($spans[0]['meta'][$coordinate]);
            }
            self::assertIsInt($spans[0]['meta']['rpc_start_monotonic_us']);
            self::assertIsInt($spans[0]['meta']['rpc_end_monotonic_us']);
        });
        $this->withRequestTrace(true, function (): void {
            $failure = new \RuntimeException('fixture acquire exception');
            $pool = $this->createMock(ConnectionPoolInterface::class);
            $pool->expects(self::once())->method('acquire')->willThrowException($failure);
            $pool->expects(self::never())->method('release');
            $pool->expects(self::never())->method('invalidate');
            try {
                $this->createClientWithPool($pool)->request(SessionProtocol::CMD_GET, ['ns' => 'sess', 'key' => 'acquire-fixture']);
                self::fail('The existing acquire exception must still propagate.');
            } catch (\RuntimeException $actual) {
                self::assertSame($failure, $actual);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function rpcSpans(): array
    {
        return array_values(array_filter(RequestLifecycleTrace::getSpansWithDbSummary(), static fn(array $span): bool => $span['name'] === 'wls.rpc.request'));
    }

    private function withRequestTrace(bool $enabled, callable $callback): void
    {
        $previousTrace = Env::get('wls.debug.request_trace', false);
        $previousCap = Env::get('wls.debug.request_trace_max_spans', 4096);
        Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => $enabled, 'request_trace_max_spans' => 64]]]);
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Context::enter(new Context($enabled ? ['runtime' => ['request_context' => ['initialized' => true, 'request_id' => 'rpc-unit-request']]] : []));
        if ($enabled) {
            RequestContext::setId('rpc-unit-request');
        }
        RequestLifecycleTrace::reset();
        try {
            $callback();
        } finally {
            RequestLifecycleTrace::reset();
            Context::leave();
            Runtime::resetModeCache();
            Env::getInstance()->applyRuntimeConfig(['wls' => ['debug' => ['request_trace' => $previousTrace, 'request_trace_max_spans' => $previousCap]]]);
        }
    }
}
