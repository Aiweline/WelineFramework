<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\PanelTraceCookie;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\TraceGate;

final class PanelTraceCookieScopedNameTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadsWorkerScopedAliasWhenCookieHelperIsNotLoaded(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'mount_path' => '/',
        ]);
        try {
            $value = PanelTraceCookie::buildPanelValue();
            $_COOKIE = [
                'w_weline_trace_panel_w0' => $value,
            ];
            unset($_COOKIE[PanelTraceCookie::PANEL_COOKIE]);
            self::assertTrue(PanelTraceCookie::isPanelArmed());
            self::assertTrue(PanelTraceCookie::isValidPanelValue($value));
        } finally {
            CookieScope::setPolicyResolverOverride(null);
            $_COOKIE = [];
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAcceptsSoleScopedAliasBeforeCookieScopeIsLoaded(): void
    {
        // Do not touch CookieScope — simulate TraceGate bootstrap before first qualify.
        CookieScope::setPolicyResolverOverride(null);
        CookieScope::resetRequestState();
        $_COOKIE = [
            'w_weline_trace_panel_w0' => PanelTraceCookie::buildPanelValue(),
        ];
        unset($_COOKIE[PanelTraceCookie::PANEL_COOKIE]);
        try {
            // Even with no active CookieScope suffix, a sole `{name}_*` wire alias arms.
            self::assertTrue(PanelTraceCookie::isPanelArmed());
        } finally {
            $_COOKIE = [];
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRejectsForeignWorkerSuffixWhenCookieScopeIsActive(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w1',
            'mount_path' => '/',
        ]);
        try {
            $_COOKIE = [
                'w_weline_trace_panel_w0' => PanelTraceCookie::buildPanelValue(),
            ];
            self::assertFalse(PanelTraceCookie::isPanelArmed());
        } finally {
            CookieScope::setPolicyResolverOverride(null);
            $_COOKIE = [];
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTraceGateBootstrapArmsFromScopedPanelCookie(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'mount_path' => '/',
        ]);
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        try {
            $_COOKIE = [
                'w_weline_trace_panel_w0' => PanelTraceCookie::buildPanelValue(),
            ];
            TraceGate::reset();
            TraceGate::bootstrapFromRequest();
            self::assertTrue(TraceGate::isArmed());
        } finally {
            TraceGate::reset();
            RequestLifecycleTrace::reset();
            CookieScope::setPolicyResolverOverride(null);
            Context::leave();
            Runtime::resetModeCache();
            $_COOKIE = [];
        }
    }
}
