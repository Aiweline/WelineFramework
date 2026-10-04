<?php
declare(strict_types=1);
namespace Weline\Frontend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Frontend\Service\StaticErrorRuntimeTransport;

final class StaticErrorRuntimeTransportTest extends TestCase
{
    public function testStaticConfigUsesTheCurrentMountAndPreservesLanguageAndPageBytes(): void
    {
        $config = ['api' => ['endpoint' => '/api/framework/query-bin', 'workerUrl' => '/worker.js'], 'site' => ['base_host' => 'http:///', 'i18n' => ['关闭' => 'Close']], 'currentLang' => 'en_US', 'custom' => null];
        $prefix = '<main data-widget-code="recommended-products">Keep</main>';
        $html = $prefix . '<script type="application/json" id="weline-frontend-runtime-config">' . json_encode($config) . '</script>';
        $out = StaticErrorRuntimeTransport::rebind($html, 'https://example.test/shop', 'https://example.test/shop/', 'https://example.test/shop/api', 'https://example.test/shop/api/framework/query-bin');
        self::assertStringStartsWith($prefix, $out);
        preg_match('#<script[^>]*>(.*?)</script>#s', $out, $matches);
        $actual = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://example.test/shop/api/framework/query-bin', $actual['api']['endpoint']);
        self::assertSame($actual['api']['endpoint'], $actual['api']['queryBinUrl']);
        self::assertSame('https://example.test/shop/', $actual['site']['base_host']);
        self::assertSame('/worker.js', $actual['api']['workerUrl']);
        self::assertSame($config['site']['i18n'], $actual['site']['i18n']);
        self::assertSame('en_US', $actual['currentLang']);
        self::assertNull($actual['custom']);
    }

    public function testResponseWithoutFrontendConfigIsPreserved(): void
    {
        $html = '<html><body>404</body></html>';
        self::assertSame($html, StaticErrorRuntimeTransport::rebind($html, 'https://example.test', 'https://example.test/', 'https://example.test/api', 'https://example.test/api/framework/query-bin'));
    }
}
