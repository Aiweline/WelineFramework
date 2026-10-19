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

    public function testSplitRequestTargetPreservesBareUtf8Query(): void
    {
        $split = Url::splitRequestTargetPathQuery('/search?q=四川');
        self::assertArrayNotHasKey('error', $split);
        self::assertSame('/search', $split['path']);
        self::assertSame('q=四川', $split['raw_query']);
        self::assertTrue(Url::isValidUtf8Text($split['path'] . '?' . $split['raw_query']));

        // Contrast: PHP parse_url corrupts the same wire target.
        $phpQuery = \parse_url('/search?q=四川', \PHP_URL_QUERY);
        self::assertIsString($phpQuery);
        self::assertFalse(Url::isValidUtf8Text((string)$phpQuery));
    }

    public function testParseUrlOverlayPreservesBareUtf8Query(): void
    {
        Url::resetParserRequestCaches();
        try {
            $parsed = Url::parse_url('http://playinginchina.test.weline.com:9555/search?q=四川');
            self::assertIsArray($parsed);
            self::assertSame('/search', $parsed['path'] ?? null);
            self::assertSame('q=四川', $parsed['query'] ?? null);
            self::assertTrue(Url::isValidUtf8Text((string)($parsed['query'] ?? '')));
        } finally {
            Url::resetParserRequestCaches();
        }
    }

    public function testParserDoesNotRejectBareUtf8SearchQueryAsInvalidEncoding(): void
    {
        $_SERVER['REQUEST_URI'] = '/search?q=四川';
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = '/search?q=四川';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'playinginchina.test.weline.com:9555';

        Url::resetParserRequestCaches();
        try {
            Url::parser();
        } catch (NoRouterException $e) {
            self::assertNotSame(
                'Invalid request URI encoding',
                $e->getMessage(),
                'Bare UTF-8 search query must pass UTF-8 URI guard',
            );
        } catch (\Throwable) {
            // Website match / DB may be unavailable in unit harness; encoding guard is the contract.
        } finally {
            unset(
                $_SERVER['REQUEST_URI'],
                $_SERVER['WELINE_ORIGIN_REQUEST_URI'],
                $_SERVER['REQUEST_SCHEME'],
                $_SERVER['HTTP_HOST'],
            );
            Url::resetParserRequestCaches();
        }
        self::assertTrue(true);
    }

    public function testParserDoesNotRejectAbsoluteUrlBareUtf8QueryAsInvalidEncoding(): void
    {
        Url::resetParserRequestCaches();
        try {
            Url::parser('http://playinginchina.test.weline.com:9555/search?q=四川');
        } catch (NoRouterException $e) {
            self::assertNotSame(
                'Invalid request URI encoding',
                $e->getMessage(),
                'Absolute URL with bare UTF-8 query must pass UTF-8 URI guard',
            );
        } catch (\Throwable) {
            // Same as relative: later website stages may fail in unit harness.
        } finally {
            Url::resetParserRequestCaches();
        }
        self::assertTrue(true);
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
