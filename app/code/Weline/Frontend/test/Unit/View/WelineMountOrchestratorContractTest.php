<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: data-weline-mount is a Weline.js framework UI capability
 * (provide / scan / wait), not a business-module self-scan.
 */
final class WelineMountOrchestratorContractTest extends TestCase
{
    public function testWelineJsExposesMountOrchestratorWithoutBusinessNames(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('Weline.mount', $js);
        self::assertStringContainsString('data-weline-mount', $js);
        self::assertStringContainsString('data-weline-mount-load', $js);
        self::assertStringContainsString('data-weline-mount-after', $js);
        self::assertStringContainsString('provide:', $js);
        self::assertStringContainsString('scan:', $js);
        self::assertStringContainsString('waitFor:', $js);
        self::assertStringContainsString('no_provider', $js);
        self::assertStringContainsString('weline:mount:scan', $js);
        self::assertStringContainsString('weline:mount:ready', $js);
        // Framework must not hard-code business surfaces / modules.
        self::assertStringNotContainsString('customer/social-quick', $js);
        self::assertStringNotContainsString("provide('account'", $js);
        self::assertStringNotContainsString("load('account')", $js);
        self::assertStringNotContainsString('WelineAccountModule', $js);
        self::assertStringNotContainsString('b2bSellingMode', $js);
    }

    public function testMountOrchestratorIsLazyUntilPageDeclaresHosts(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('pageDeclaresMount', $js);
        self::assertStringContainsString('ensureMountCore', $js);
        self::assertStringContainsString('no_hosts', $js);
        self::assertStringContainsString("querySelector('[data-weline-mount]')", $js);
        // Auto-scan must gate on declaration presence.
        self::assertStringContainsString('if (!pageDeclaresMount(document))', $js);
    }

    public function testScanSecondPassDoesNotForceRemount(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('runPass(force)', $js);
        self::assertStringContainsString('runPass(false)', $js);
        self::assertStringContainsString('must not force-remount settled hosts', $js);
    }
}
