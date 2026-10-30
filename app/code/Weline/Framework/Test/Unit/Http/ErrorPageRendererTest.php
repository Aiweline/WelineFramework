<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\Fpc\StorefrontNotFoundFpcStore;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\StorefrontLocale;

final class ErrorPageRendererTest extends TestCase
{
    public function testRenderHtmlIncludesStatusAndTitle(): void
    {
        // skip_static = generic fallback shell (no theme/special-FPC lookup).
        $html = ErrorPageRenderer::render(404, '未知的路由！', [
            'prefer_json' => false,
            'is_dev' => false,
            'home_href' => '/',
            'skip_static' => true,
        ]);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('w-error', $html);
        self::assertGreaterThan(80, \strlen(\trim(\strip_tags($html))));
    }

    public function testRenderHtmlPrefersStorefrontFpcWhenAvailable(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        $marker = '<main data-testid="storefront-not-found-page">';
        $path = $this->writeFpcLeaf('<!DOCTYPE html><html><body>' . $marker . '</body></html>');
        try {
            $html = ErrorPageRenderer::render(404, '未知的路由！', [
                'prefer_json' => false,
                'is_dev' => false,
                'home_href' => '/',
            ]);

            self::assertStringContainsString($marker, $html);
            self::assertStringNotContainsString('w-error', $html);
        } finally {
            @\unlink($path);
            @\unlink($path . '.ok');
        }
    }

    public function testSkipStaticBypassesStorefrontFpc(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        $marker = '<main data-testid="must-not-reuse-fpc-404">';
        $path = $this->writeFpcLeaf('<!DOCTYPE html><html><body>' . $marker . '</body></html>');
        try {
            $html = ErrorPageRenderer::render(404, '未知的路由！', [
                'prefer_json' => false,
                'is_dev' => false,
                'home_href' => '/',
                'skip_static' => true,
            ]);

            self::assertStringNotContainsString($marker, $html);
            self::assertStringContainsString('404', $html);
        } finally {
            @\unlink($path);
            @\unlink($path . '.ok');
        }
    }

    public function testRenderHtmlDoesNotUseStorefrontFpcForBackendArea(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP not defined');
        }

        $marker = '<main data-testid="backend-not-found-page">';
        $path = $this->writeFpcLeaf('<!DOCTYPE html><html><body>' . $marker . '</body></html>');
        try {
            $html = ErrorPageRenderer::render(404, '未知的路由！', [
                'prefer_json' => false,
                'is_dev' => false,
                'home_href' => '/',
                'area' => 'backend',
            ]);

            self::assertStringNotContainsString($marker, $html);
            self::assertStringContainsString('404', $html);
            self::assertStringContainsString('w-error', $html);
        } finally {
            @\unlink($path);
            @\unlink($path . '.ok');
        }
    }

    private function writeFpcLeaf(string $html): string
    {
        $path = StorefrontNotFoundFpcStore::filePath('default', StorefrontLocale::DEFAULT_LANG);
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        @\file_put_contents($path, $html);

        return $path;
    }

    public function testRenderJsonWhenPreferJson(): void
    {
        $json = ErrorPageRenderer::render(429, 'scope_rate_limited', [
            'prefer_json' => true,
            'request_id' => 'req-test-1',
        ]);
        $payload = \json_decode($json, true);

        self::assertIsArray($payload);
        self::assertFalse($payload['ok']);
        self::assertSame(429, $payload['status']);
        self::assertSame('Too Many Requests', $payload['error']);
        self::assertSame('scope_rate_limited', $payload['message']);
        self::assertSame('req-test-1', $payload['request_id']);
    }

    public function testNoRouterExceptionBodyIsRichHtml(): void
    {
        $ex = new NoRouterException(403, 'Forbidden by ACL');
        $body = $ex->getBody();

        self::assertSame(403, $ex->getStatusCode());
        self::assertStringContainsString('无权访问', $body);
        self::assertStringContainsString('Forbidden by ACL', $body);
        self::assertNotSame('403', \trim(\strip_tags($body)));
    }

    public function testDefaultMessageCatalog(): void
    {
        self::assertSame('Not Found', ErrorPageRenderer::defaultMessage(404));
        self::assertSame('Service Unavailable', ErrorPageRenderer::defaultMessage(503));
        self::assertSame('Too Many Requests', ErrorPageRenderer::defaultMessage(429));
    }

    public function testUnknownCodeFallsBackToDefaultTemplate(): void
    {
        $html = ErrorPageRenderer::render(418, 'I am a teapot', ['prefer_json' => false]);
        self::assertStringContainsString('418', $html);
        self::assertStringContainsString('I am a teapot', $html);
    }

    public function testIncludeTemplateUsesFiberOutputBufferNotNativeOb(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Http/ErrorPageRenderer.php'
        );
        $pos = \strpos($source, 'function includeTemplate');
        self::assertNotFalse($pos);
        $chunk = \substr($source, (int)$pos, 1200);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $chunk);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $chunk);
        self::assertStringNotContainsString('ob_start()', $chunk);
        self::assertStringNotContainsString('ob_get_clean()', $chunk);
        self::assertStringNotContainsString('ob_end_clean()', $chunk);
    }
}
