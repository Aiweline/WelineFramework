<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\Fpc\SharedFpcSetCookieSuppress;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\StorefrontGenerationOutbound;
use Weline\Framework\Runtime\RequestContext;

final class StorefrontGenerationOutboundContractTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        CookieScope::resetRequestState();
        WelineEnv::set('response.from_cache', false, 'unit-test');
        WelineEnv::set('is_static_file', false, 'unit-test');
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        CookieScope::resetRequestState();
        WelineEnv::set('response.from_cache', false, 'unit-test');
        WelineEnv::set('is_static_file', false, 'unit-test');
    }

    public function testCookieNameIsProtocolExactWireName(): void
    {
        self::assertTrue(CookieScope::isProtocolCookie(StorefrontGenerationOutbound::COOKIE_NAME));
        self::assertSame('weline_storefront_gen', StorefrontGenerationOutbound::COOKIE_NAME);
    }

    public function testApplySetsReadableCookieAndVaryCookieHeaderName(): void
    {
        if (!\class_exists(\Weline\Frontend\Helper\StorefrontGenerationIdentity::class)) {
            self::markTestSkipped('Frontend StorefrontGenerationIdentity unavailable');
        }

        $id = \trim((string)\Weline\Frontend\Helper\StorefrontGenerationIdentity::currentVersionId());
        if ($id === '') {
            self::markTestSkipped('No published storefront generation identity in this env');
        }

        $response = new Response(true);
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setBody('<html></html>');
        StorefrontGenerationOutbound::applyToResponse($response);

        $cookies = $response->getCookies();
        $found = null;
        foreach ($cookies as $cookie) {
            if (($cookie['name'] ?? '') === StorefrontGenerationOutbound::COOKIE_NAME) {
                $found = $cookie;
                break;
            }
        }
        self::assertNotNull($found, 'Expected identity Set-Cookie on non-suppressed response');
        self::assertSame($id, (string)$found['value']);
        self::assertFalse((bool)$found['httpOnly'], 'Identity cookie must be JS-readable');

        $vary = (string)($response->getHeader('Vary') ?? '');
        self::assertMatchesRegularExpression('/(^|,\s*)Cookie(\s*,|$)/i', $vary);
        self::assertStringNotContainsString($id, $vary, 'Vary must list header names, not the identity value');
    }

    public function testSharedFpcSuppressBlocksIdentitySetCookie(): void
    {
        SharedFpcSetCookieSuppress::arm('unit_storefront_gen');
        $response = new Response(true);
        $response->setBody('ok');
        // Even with a forced apply, suppress must win.
        $response->setCookie(
            StorefrontGenerationOutbound::COOKIE_NAME,
            'forced-id',
            \time() + 60,
            '/',
            '',
            false,
            false,
            'Lax'
        );
        self::assertSame([], $response->getCookies());
    }

    public function testResponsePrepareForEmissionWiresOutboundHelper(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Http/Response.php');
        self::assertStringContainsString('applyStorefrontGenerationOutboundIfNeeded', $src);
        self::assertStringContainsString('StorefrontGenerationOutbound::applyToResponse', $src);
    }

    public function testWelineJsReadsIdentityCookieConstant(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline.js'
        );
        self::assertStringContainsString('weline_storefront_gen', $js);
        self::assertStringContainsString('promptStorefrontGenerationRefresh', $js);
        self::assertStringContainsString('themePublishedVersionId', $js);
        self::assertStringNotContainsString('window.confirm(', $js);
    }
}
