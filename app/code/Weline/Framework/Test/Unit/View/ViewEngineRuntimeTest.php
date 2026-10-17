<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Engine\EngineFlags;
use Weline\Framework\View\Engine\HookRuntime;
use Weline\Framework\View\Engine\RenderContext;
use Weline\Framework\View\Engine\RenderMode;

final class ViewEngineRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(EngineFlags::REQUEST_KEY);
        RequestContext::remove(RenderContext::REQUIRE_STRING_HOOK);
        parent::tearDown();
    }

    public function testRenderModeCasesExist(): void
    {
        self::assertSame('stream', RenderMode::Stream->value);
        self::assertSame('capture', RenderMode::Capture->value);
        self::assertSame('include', RenderMode::Include->value);
    }

    public function testEngineFlagsDefaultEnabledAndRequestOverride(): void
    {
        RequestContext::remove(EngineFlags::REQUEST_KEY);
        self::assertTrue(EngineFlags::isV2Enabled());

        RequestContext::set(EngineFlags::REQUEST_KEY, false);
        self::assertFalse(EngineFlags::isV2Enabled());

        RequestContext::set(EngineFlags::REQUEST_KEY, true);
        self::assertTrue(EngineFlags::isV2Enabled());
    }

    public function testHookRuntimeRespectsRequireStringFlag(): void
    {
        RequestContext::remove(RenderContext::REQUIRE_STRING_HOOK);
        // Without active capture, preferStream is always false.
        self::assertFalse(HookRuntime::preferStreamIntoCapture(false));

        RequestContext::set(RenderContext::REQUIRE_STRING_HOOK, true);
        self::assertFalse(HookRuntime::preferStreamIntoCapture(false));
    }
}
