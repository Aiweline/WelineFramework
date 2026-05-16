<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\TelemetryBroadcaster;

final class TelemetryBroadcasterTest extends TestCase
{
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
}
