<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\TlsAlpnRuntimeProbe;
use Weline\Server\Service\Runtime\TlsSessionCacheRuntime;
use Weline\Server\Service\Runtime\TlsSessionResumptionLiveVerifier;
use Weline\Server\Service\SharedRuntimeConnectionWarmup;
use Weline\Server\Service\SharedStateProtocolProbe;
use Weline\Server\Service\SharedStateRuntimeResolver;

final class RuntimeProbeMonotonicTimingTest extends TestCase
{
    /**
     * @dataProvider runtimeProbeClassProvider
     */
    public function testProbeTimeoutsCooldownsAndElapsedMetricsUseMonotonicClock(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString('\\hrtime(true)', $source, $class);
    }

    /** @return iterable<string,array{class-string}> */
    public static function runtimeProbeClassProvider(): iterable
    {
        yield 'TLS ALPN probe budget' => [TlsAlpnRuntimeProbe::class];
        yield 'TLS session cache maintenance budget' => [TlsSessionCacheRuntime::class];
        yield 'TLS live verifier recovery windows' => [TlsSessionResumptionLiveVerifier::class];
        yield 'shared state protocol read deadline' => [SharedStateProtocolProbe::class];
        yield 'shared state warmup cooldown' => [SharedRuntimeConnectionWarmup::class];
        yield 'shared state resolver elapsed metric' => [SharedStateRuntimeResolver::class];
    }
}
