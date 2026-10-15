<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\ProjectRoot;

/**
 * Path-only FPC must key off visitor pretty paths (e.g. /destinations),
 * not rewritten controller paths (/guide?view=…), or distinct pages collide.
 *
 * Shared pool identity must match engine live/{host}/{path}: freeze the
 * visitor full URL at request start and never let canonicalize overwrite ORIGIN.
 */
final class FullPageCacheGuideOriginPrettyPathContractTest extends TestCase
{
    protected function setUp(): void
    {
        ProjectRoot::defineBp(__DIR__);
    }

    public function testCoordinatorPrefersWelineOriginRequestUriForRawFullUri(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );

        self::assertStringContainsString('function getRawFullUri', $source);
        self::assertStringContainsString('WELINE_ORIGIN_REQUEST_URI', $source);
        self::assertStringContainsString('visitor-facing pretty path', $source);
        self::assertStringContainsString('/destinations', $source);
        self::assertStringContainsString('/guide?view=', $source);
        self::assertLessThan(
            strpos($source, "WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'") ?: PHP_INT_MAX,
            strpos($source, 'function getRawFullUri') ?: PHP_INT_MAX,
        );
    }

    public function testCoordinatorPrefersFrozenEngineIdentityUrlBeforeOriginFallback(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );

        $getRawPos = strpos($source, 'function getRawFullUri');
        $nextFn = strpos($source, "\n    private function ", $getRawPos + 10);
        $body = $nextFn === false
            ? substr($source, $getRawPos)
            : substr($source, $getRawPos, $nextFn - $getRawPos);

        self::assertNotFalse($getRawPos);
        self::assertStringContainsString("RequestContext::get('fpc.engine_identity_url'", $body);
        self::assertStringContainsString('FpcEngineIdentity::isGuideControllerUri', $body);
        self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $body);
        self::assertStringContainsString('Engine-aligned Shared identity', $body);
        self::assertStringContainsString('generated/html/live/{host}/{path}', $body);
        $freezeInBody = strpos($body, "RequestContext::get('fpc.engine_identity_url'");
        $originInBody = strpos($body, "WelineEnv::server('WELINE_ORIGIN_REQUEST_URI'");
        self::assertNotFalse($freezeInBody);
        self::assertNotFalse($originInBody);
        self::assertLessThan($originInBody, $freezeInBody);
    }

    public function testCanonicalizeDoesNotOverwriteOriginOrFullRequestUri(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );

        $fnPos = strpos($source, 'function canonicalizeCurrentRequestForCacheBuild');
        self::assertNotFalse($fnPos);
        $nextFn = strpos($source, "\n    private function ", $fnPos + 10);
        $body = $nextFn === false
            ? substr($source, $fnPos)
            : substr($source, $fnPos, $nextFn - $fnPos);

        self::assertStringContainsString('Do NOT overwrite WELINE_ORIGIN_REQUEST_URI', $body);
        self::assertStringNotContainsString(
            "WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI'",
            $body
        );
        self::assertStringNotContainsString(
            "WelineEnv::setServer('WELINE_FULL_REQUEST_URI'",
            $body
        );
        self::assertStringNotContainsString(
            "WelineEnv::set('full_request_uri'",
            $body
        );
        self::assertStringNotContainsString(
            "WelineEnv::set('origin_request_uri'",
            $body
        );
    }

    public function testAppFreezesEngineIdentityUrlFromVisitorOrigin(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/App.php'
        );

        self::assertStringContainsString('FpcEngineIdentity::freezeFullUrl', $source);
        self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $source);
        self::assertStringContainsString('Freeze Shared FPC identity', $source);
        self::assertStringContainsString('generated/html/live/{host}/{path}', $source);
    }

    public function testUrlLocksImmutableVisitorRequestUri(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Http/Url.php'
        );

        self::assertStringContainsString('WELINE_VISITOR_REQUEST_URI', $source);
        self::assertStringContainsString('Immutable visitor path for Shared FPC', $source);
    }

    public function testUrlMatrixExpanderDocumentsPathLocaleNotQueryIdentity(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Event/Changed/UrlMatrixExpander.php'
        );

        self::assertStringContainsString('path 语种，非 query 标记', $source);
        self::assertStringContainsString('withPathVariant', $source);
    }
}
