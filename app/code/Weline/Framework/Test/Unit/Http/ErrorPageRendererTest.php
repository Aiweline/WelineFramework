<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ErrorPageRenderer;
use Weline\Framework\Http\NoRouterException;

final class ErrorPageRendererTest extends TestCase
{
    public function testRenderHtmlIncludesStatusAndTitle(): void
    {
        $html = ErrorPageRenderer::render(404, '未知的路由！', [
            'prefer_json' => false,
            'is_dev' => false,
            'home_href' => '/',
        ]);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('页面不存在', $html);
        self::assertStringContainsString('未知的路由！', $html);
        self::assertStringContainsString('w-error', $html);
        self::assertGreaterThan(80, \strlen(\trim(\strip_tags($html))));
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
        self::assertStringContainsString('请求无法完成', $html);
    }
}
