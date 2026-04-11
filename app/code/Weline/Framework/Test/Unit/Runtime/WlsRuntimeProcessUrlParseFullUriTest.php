<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimeProcessUrlParseFullUriTest extends TestCase
{
    private array $originalServer = [];
    private array $originalGet = [];
    private array $originalPost = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        WelineEnv::getInstance()->reset();
        \Weline\Framework\Http\Request::clearStaticUrlPathCache();
        RequestContext::cleanup();
    }

    protected function tearDown(): void
    {
        Runtime::resetModeCache();
        WelineEnv::getInstance()->reset();
        \Weline\Framework\Http\Request::clearStaticUrlPathCache();
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        RequestContext::cleanup();
    }

    public function testProcessUrlParseRebuildsFullRequestUriFromCurrentRequest(): void
    {
        $_SERVER = [
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.weline.local',
            'REQUEST_URI' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            'WELINE_FULL_REQUEST_URI' => 'https://p11005ce4.weline.local/pagebuilder/backend/ai-site-agent/stream-sse?public_id=stale',
            'WELINE_ORIGIN_REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/stream-sse?public_id=stale',
        ];
        $_GET = [];
        $_POST = [];

        $parse = [
            'area' => 'backend',
            'uri' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            'server' => [
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'p11005ce4.weline.local',
                'REQUEST_URI' => '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
                'QUERY_STRING' => 'page_id=152&visual_editor=1',
                'WELINE_AREA' => 'backend',
            ],
        ];

        $runtime = new WlsRuntime();
        $method = new ReflectionMethod(WlsRuntime::class, 'processUrlParse');
        $method->setAccessible(true);
        $method->invoke($runtime, $parse);

        self::assertSame(
            'https://p11005ce4.weline.local/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null
        );
        self::assertSame(
            '/pagebuilder/backend/preview/full?page_id=152&visual_editor=1',
            $_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? null
        );
    }

    public function testProcessUrlParseSyncsParsedRequestUriForWlsRequest(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];

        $request = WlsRequest::fromRaw(
            "GET /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/zh_Hans_CN/pagebuilder/backend/page/index HTTP/1.1\r\n"
            . "Host: p11005ce4.weline.local\r\n\r\n"
        );

        $parse = [
            'area' => 'backend',
            'uri' => '/pagebuilder/backend/page/index',
            'server' => [
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'p11005ce4.weline.local',
                'REQUEST_URI' => '/pagebuilder/backend/page/index',
                'QUERY_STRING' => '',
                'WELINE_AREA' => 'backend',
            ],
        ];

        $runtime = new WlsRuntime();
        $method = new ReflectionMethod(WlsRuntime::class, 'processUrlParse');
        $method->setAccessible(true);
        $method->invoke($runtime, $parse);

        self::assertSame('/pagebuilder/backend/page/index', WelineEnv::get('request.uri'));
        self::assertTrue((bool) WelineEnv::get('url_parsed', false));
        self::assertSame('/pagebuilder/backend/page/index', $request->getUri());
        self::assertSame('/pagebuilder/backend/page/index', $request->getUrlPath());
    }

    public function testProcessUrlParseBackendRequestUriDoesNotKeepPreviousFrontendValue(): void
    {
        $_SERVER = [
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.weline.local',
            'REQUEST_URI' => '/privacy',
            'QUERY_STRING' => '',
            'WELINE_AREA' => 'frontend',
        ];
        $_GET = [];
        $_POST = [];

        $parse = [
            'area' => 'backend',
            'uri' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            'server' => [
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'p11005ce4.weline.local',
                'WELINE_AREA' => 'backend',
            ],
        ];

        $runtime = new WlsRuntime();
        $method = new ReflectionMethod(WlsRuntime::class, 'processUrlParse');
        $method->setAccessible(true);
        $method->invoke($runtime, $parse);

        self::assertSame('/pagebuilder/backend/ai-site-agent/index?legacy=1', $_SERVER['REQUEST_URI'] ?? null);
        self::assertSame('legacy=1', $_SERVER['QUERY_STRING'] ?? null);
        self::assertSame('/pagebuilder/backend/ai-site-agent/index?legacy=1', WelineEnv::get('request.uri'));
        self::assertSame(
            'https://p11005ce4.weline.local/pagebuilder/backend/ai-site-agent/index?legacy=1',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null
        );
    }
}
