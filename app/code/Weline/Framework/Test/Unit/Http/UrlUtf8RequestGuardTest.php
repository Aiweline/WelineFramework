<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Url;

final class UrlUtf8RequestGuardTest extends TestCase
{
    public function testIsValidUtf8TextRejectsInvalidByteSequence(): void
    {
        self::assertTrue(Url::isValidUtf8Text(''));
        self::assertTrue(Url::isValidUtf8Text('/zh_Hans_CN/contact'));
        self::assertTrue(Url::isValidUtf8Text('/全角＿'));

        $invalid = '/' . \pack('H*', 'efbc5f');
        self::assertFalse(Url::isValidUtf8Text($invalid));
    }

    public function testLooksLikeInjectionProbeCatchesSqlAndDoubleEncoding(): void
    {
        self::assertFalse(Url::looksLikeInjectionProbe('/zh_Hans_CN/products'));
        self::assertFalse(Url::looksLikeInjectionProbe('/select-from-collection'));
        self::assertTrue(Url::looksLikeInjectionProbe('/?q=1\' OR 1=1--'));
        self::assertTrue(Url::looksLikeInjectionProbe('/?q=%2527%20OR%201%3D1'));
        self::assertTrue(Url::looksLikeInjectionProbe('/?x=<script>alert(1)</script>'));
        self::assertTrue(Url::looksLikeInjectionProbe('/?f=php://filter'));
    }

    public function testParserRejectsInvalidUtf8UriWith400(): void
    {
        $invalid = '/' . \pack('H*', 'efbc5f');
        $_SERVER['REQUEST_URI'] = $invalid;
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'p05113ef3.test.weline.com:9555';

        Url::resetParserRequestCaches();
        try {
            Url::parser();
            self::fail('Expected NoRouterException for invalid UTF-8 URI');
        } catch (NoRouterException $e) {
            self::assertSame(400, $e->getCode());
        } finally {
            unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST']);
            Url::resetParserRequestCaches();
        }
    }

    public function testParserRejectsInjectionProbeWith403(): void
    {
        $_SERVER['REQUEST_URI'] = '/search?q=1%27%20OR%201%3D1--';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'p05113ef3.test.weline.com:9555';

        Url::resetParserRequestCaches();
        try {
            Url::parser();
            self::fail('Expected NoRouterException for injection probe');
        } catch (NoRouterException $e) {
            self::assertSame(403, $e->getCode());
        } finally {
            unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST']);
            Url::resetParserRequestCaches();
        }
    }
}
