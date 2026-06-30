<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\TelemetryBroadcaster;

final class TelemetryBroadcasterTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSampleCounter();
    }

    protected function tearDown(): void
    {
        Env::getInstance()->reload();
        $this->resetSampleCounter();
    }

    public function testDevToolActivationUsesCurrentRequestQuery(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getGet')->willReturn('1');

        $method = new ReflectionMethod(TelemetryBroadcaster::class, 'hasDevToolActivation');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke(null, $request));
    }

    public function testExistingDevToolMarkupAllowsTelemetryBodyMutation(): void
    {
        $method = new ReflectionMethod(TelemetryBroadcaster::class, 'containsDevToolMarkup');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke(null, '<div id="dev-tool-panel"></div>'));
        self::assertTrue((bool)$method->invoke(null, '<script>window.__WELINE_REQUEST_ID__="req-12345678";</script>'));
        self::assertFalse((bool)$method->invoke(null, '<div class="product-card"></div>'));
    }

    public function testTraceModesControlBroadcastDecision(): void
    {
        $env = Env::getInstance();
        $env->applyRuntimeConfig(['performance' => ['telemetry' => ['mode' => 'off', 'sample_rate' => 100]]]);

        self::assertFalse(TelemetryBroadcaster::shouldBroadcast());
        self::assertTrue(TelemetryBroadcaster::shouldBroadcast(true));

        $env->applyRuntimeConfig(['performance' => ['telemetry' => ['mode' => 'full', 'sample_rate' => 100]]]);
        self::assertTrue(TelemetryBroadcaster::shouldBroadcast());
    }

    public function testSampledModeUsesConfiguredRequestRate(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'performance' => [
                'telemetry' => [
                    'mode' => 'sampled',
                    'sample_rate' => 2,
                ],
            ],
        ]);

        self::assertTrue(TelemetryBroadcaster::shouldBroadcast());
        self::assertFalse(TelemetryBroadcaster::shouldBroadcast());
        self::assertTrue(TelemetryBroadcaster::shouldBroadcast());
    }

    private function resetSampleCounter(): void
    {
        $counter = new \ReflectionProperty(TelemetryBroadcaster::class, 'sampleCounter');
        $counter->setAccessible(true);
        $counter->setValue(null, 0);
    }
}
