<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\TraceGate;

final class TraceGateLazyLoadTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClosedGateSkipsWithoutInvokingLazyMeta(): void
    {
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        try {
            TraceGate::markArmed(false);
            $loadedBefore = \class_exists(RequestLifecycleTrace::class, false);
            $metaCalls = 0;
            self::assertSame('ok', TraceGate::measurePhase(
                'noop',
                static fn(): string => 'ok',
                static function () use (&$metaCalls): array {
                    ++$metaCalls;

                    return ['heavy' => \str_repeat('x', 4096)];
                }
            ));
            TraceGate::recordSpan('skip', 1.0, 'framework', null, static function () use (&$metaCalls): array {
                ++$metaCalls;

                return ['sql' => 'SELECT 1'];
            });
            self::assertSame(0, $metaCalls);
            // Suite bootstrap may already have loaded the recorder; closed gate must not
            // newly require it when it was absent at the start of this process.
            if (!$loadedBefore) {
                self::assertFalse(\class_exists(RequestLifecycleTrace::class, false));
            }
        } finally {
            Context::leave();
            TraceGate::reset();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testArmLoadsRecorderIntoRequestContext(): void
    {
        RequestContext::init();
        try {
            RequestLifecycleTrace::installPanelTraceOn();
            self::assertTrue(TraceGate::isArmed());
            self::assertTrue(\class_exists(RequestLifecycleTrace::class, false));
            self::assertSame(
                RequestLifecycleTrace::class,
                RequestContext::get(TraceGate::CONTEXT_RECORDER_KEY)
            );
            TraceGate::recordSpan('armed', 1.5, 'framework');
            self::assertSame('armed', RequestLifecycleTrace::getSpans()[0]['name'] ?? null);
        } finally {
            RequestLifecycleTrace::clearPanelTrace();
            RequestLifecycleTrace::reset();
            TraceGate::reset();
            Context::leave();
        }
    }
}
