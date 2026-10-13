<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

/**
 * Storefront Scope hole-punch must be reachable at /api/frontend/scope-bootstrap.
 *
 * Discovery scans module Api/ (not Controller/Api); Router also keeps a QueryBin-style
 * fast-path so hole-punch works before/without a full route regenerate.
 */
final class ScopeBootstrapRouteContractTest extends TestCase
{
    public function testApiScanWrapperExtendsFrontendImplementation(): void
    {
        $wrapper = BP . 'app/code/Weline/Api/Api/Frontend/ScopeBootstrap.php';
        self::assertFileExists($wrapper);
        $src = (string)\file_get_contents($wrapper);
        self::assertStringContainsString('namespace Weline\\Api\\Api\\Frontend;', $src);
        self::assertStringContainsString(
            'extends \\Weline\\Frontend\\Controller\\Api\\ScopeBootstrap',
            $src
        );
    }

    public function testRouterFastPathAndStripAliasAreWired(): void
    {
        $router = BP . 'app/code/Weline/Framework/Router/Core.php';
        $routerSrc = (string)\file_get_contents($router);
        self::assertStringContainsString('isFrontendScopeBootstrapApiUrl', $routerSrc);
        self::assertStringContainsString('routeFrontendScopeBootstrapApi', $routerSrc);
        self::assertStringContainsString('frontend/scope-bootstrap', $routerSrc);
        self::assertStringContainsString(
            '\\Weline\\Frontend\\Controller\\Api\\ScopeBootstrap::class',
            $routerSrc
        );

        $helper = BP . 'app/code/Weline/Framework/Module/Helper/Data.php';
        $helperSrc = (string)\file_get_contents($helper);
        self::assertStringContainsString('collectFrontendScopeBootstrapRouteAlias', $helperSrc);
        self::assertStringContainsString(
            "str_starts_with(\$route, 'api/frontend/scope-bootstrap')",
            $helperSrc
        );

        $matcher = BP . 'app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php';
        $matcherSrc = (string)\file_get_contents($matcher);
        self::assertStringContainsString('matchesWorkerScopeBootstrapRoute', $matcherSrc);
        self::assertStringContainsString('api/frontend/scope-bootstrap', $matcherSrc);
    }

    public function testClientFetchesCanonicalHolePunchPath(): void
    {
        $js = BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js';
        self::assertFileExists($js);
        $src = (string)\file_get_contents($js);
        self::assertStringContainsString("fetch('/api/frontend/scope-bootstrap'", $src);
    }
}
