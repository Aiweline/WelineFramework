<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\Url;

/**
 * 解析器写终态：Url::parser() 必须刷新 Context 的 route.path。
 *
 * route.path 在 Context::fromGlobals() 里按原始 REQUEST_URI 算一次；
 * 解析器剥离区域/货币/语言前缀后，路由路径变了，route.path 必须跟着变，
 * 否则后续"直接读上下文"拿到的永远是入口时的旧路径。
 */
final class UrlParserRoutePathTest extends TestCase
{
    private array $serverBackup = [];
    private ?Context $contextBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->contextBackup = Context::getCurrent();
        Url::resetParserRequestCaches();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Context::leave();
        if ($this->contextBackup !== null) {
            Context::enter($this->contextBackup);
        }
        Url::resetParserRequestCaches();
        parent::tearDown();
    }

    public function testParserRefreshesStaleRoutePath(): void
    {
        $_SERVER = [
            'REQUEST_URI' => '/shop?x=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'example.test',
            'SERVER_NAME' => 'example.test',
            'SERVER_PORT' => '80',
            'QUERY_STRING' => 'x=1',
        ];

        // 模拟入口时算出的旧值：解析器跑完后必须被改写成当前路由路径。
        Context::enter(new Context([
            'input' => [
                'server' => $_SERVER,
                'uri' => '/shop?x=1',
                'host' => 'example.test',
                'scheme' => 'http',
                'method' => 'GET',
            ],
            'route' => ['path' => '/stale-from-entry'],
        ]));

        Url::parser();

        self::assertSame('/shop', Context::current()->get('route.path'));
    }

    public function testParserRefreshesRoutePathForSecondRequestInSameWorker(): void
    {
        $this->enterRequest('/first?x=1');
        Url::parser();
        self::assertSame('/first', Context::current()->get('route.path'));

        Context::leave();
        Url::resetParserRequestCaches();

        $this->enterRequest('/second?x=1');
        Url::parser();
        self::assertSame('/second', Context::current()->get('route.path'));
    }

    /**
     * URI 的三份副本（input.uri / input.server.REQUEST_URI / route.path）
     * 在解析后必须指向同一个路由事实。
     */
    public function testParserKeepsUriCopiesConsistent(): void
    {
        $this->enterRequest('/shop?x=1');
        Url::parser();

        $context = Context::current();

        self::assertSame($context->uri(), (string)$context->server('REQUEST_URI'));
        self::assertSame('/shop', (string)(\parse_url($context->uri(), PHP_URL_PATH) ?: '/'));
        self::assertSame('/shop', $context->path());
    }

    /**
     * Request 门面与 Context 必须给出同一个当前 URI（无路由缓存命中时）。
     */
    public function testRequestGetUriMatchesContextUri(): void
    {
        $this->enterRequest('/shop?x=1');
        Url::parser();

        $request = new \Weline\Framework\Http\Request();

        self::assertSame(Context::current()->uri(), $request->getUri());
    }

    private function enterRequest(string $requestUri): void
    {
        $_SERVER = [
            'REQUEST_URI' => $requestUri,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'example.test',
            'SERVER_NAME' => 'example.test',
            'SERVER_PORT' => '80',
            'QUERY_STRING' => 'x=1',
        ];

        Context::enter(Context::fromGlobals());
    }
}
