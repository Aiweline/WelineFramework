<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class RequestLifecycleTraceQualifiedCookieTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTraceCanArmAfterTheRequestCookieScopeIsResolved(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        CookieScope::setPolicyResolverOverride(static fn(): array => ['active' => false]);
        Context::enter(new Context([
            'input' => ['uri' => '/api/framework/query-bin', 'cookie' => [
                'w_weline_trace_panel_w0' => RequestLifecycleTrace::buildPanelTraceCookieValue(),
            ]],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        try {
            self::assertTrue(\Weline\Framework\Runtime\RequestContext::isInitialized());
            self::assertFalse(RequestLifecycleTrace::isEnabled());
            CookieScope::setPolicyResolverOverride(static fn(): array => ['active' => true, 'name_suffix' => '_w0', 'mount_path' => '/']);
            self::assertTrue(RequestLifecycleTrace::isPanelTraceArmed());
            self::assertTrue(RequestLifecycleTrace::isEnabled());
        } finally {
            CookieScope::setPolicyResolverOverride(null);
            Context::leave();
            Runtime::resetModeCache();
            RequestLifecycleTrace::reset();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testColdQueryRequestReadsOnlyItsOwnSignedTraceCookies(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        CookieScope::setPolicyResolverOverride(static fn(): array => ['active' => true, 'name_suffix' => '_w0', 'mount_path' => '/']);
        Context::enter(new Context([
            'input' => ['uri' => '/api/framework/query-bin', 'cookie' => [
                'w_weline_trace_panel_w0' => RequestLifecycleTrace::buildPanelTraceCookieValue(),
                'w_weline_tpl_perf_w0' => RequestLifecycleTrace::buildPanelTplPerfCookieValue(),
            ]],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        try {
            self::assertTrue(\Weline\Framework\Runtime\RequestContext::isInitialized());
            self::assertTrue(RequestLifecycleTrace::isPanelTraceArmed());
            self::assertTrue(RequestLifecycleTrace::isPanelTplPerfArmed());
            self::assertTrue(RequestLifecycleTrace::isEnabled());
            CookieScope::setPolicyResolverOverride(static fn(): array => ['active' => true, 'name_suffix' => '_w1', 'mount_path' => '/']);
            self::assertFalse(RequestLifecycleTrace::isPanelTraceArmed());
            self::assertFalse(RequestLifecycleTrace::isPanelTplPerfArmed());
        } finally {
            CookieScope::setPolicyResolverOverride(null);
            Context::leave();
            Runtime::resetModeCache();
            RequestLifecycleTrace::reset();
        }
    }
}
