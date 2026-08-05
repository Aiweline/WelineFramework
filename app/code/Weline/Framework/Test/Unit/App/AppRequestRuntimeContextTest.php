<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App;
use Weline\Framework\App\Env as AppEnv;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RequestContext;

final class AppRequestRuntimeContextTest extends TestCase
{
    private array $originalServer = [];
    private array $originalGet = [];
    private array $originalPost = [];
    private array $originalCookie = [];
    private array $originalFiles = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalCookie = $_COOKIE;
        $this->originalFiles = $_FILES;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        RequestContext::cleanup();
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        AppEnv::getInstance()->reload();
        Context::leave();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        AppEnv::getInstance()->reload();
        Context::leave();

        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_COOKIE = $this->originalCookie;
        $_FILES = $this->originalFiles;
    }

    public function testInitKeepsRequestUriFactsForCliRequestContext(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
            'input' => [
                'uri' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
                'origin_request_uri' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
                'scheme' => 'https',
                'host' => 'p11005ce4.weline.test',
            ],
        ]));

        $_SERVER = [
            'REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'p11005ce4.weline.test',
            'SERVER_PORT' => '443',
        ];

        App::init();

        self::assertSame(
            '/pagebuilder/backend/ai-site-agent/index?legacy=1',
            Context::current()?->get('input.server.WELINE_ORIGIN_REQUEST_URI')
        );
        self::assertSame(
            'https://p11005ce4.weline.test/pagebuilder/backend/ai-site-agent/index?legacy=1',
            Context::current()?->get('input.server.WELINE_FULL_REQUEST_URI')
        );
    }

    public function testConfiguredBenchmarkPathSkipsEagerSessionStart(): void
    {
        $app = $this->createAppForRequest('/__bench/framework?iteration=1');

        AppEnv::getInstance()->applyRuntimeConfig([
            'session' => [
                'eager_start_excluded_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        self::assertFalse($this->shouldEagerStartSession($app));
    }

    public function testNonExcludedPathKeepsDefaultEagerSessionStart(): void
    {
        $app = $this->createAppForRequest('/unit/path?id=1');

        AppEnv::getInstance()->applyRuntimeConfig([
            'session' => [
                'eager_start_excluded_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        self::assertTrue($this->shouldEagerStartSession($app));
    }

    public function testConfiguredBenchmarkPathSuppressesRouteStateCookies(): void
    {
        $app = $this->createAppForRequest('/__bench/framework?iteration=1', [
            'WELINE_USER_LANG' => 'zh_Hans_CN',
            'WELINE_USER_CURRENCY' => 'CNY',
            'WELINE_WEBSITE_ID' => '1',
            'WELINE_WEBSITE_CODE' => 'default',
            'WELINE_WEBSITE_URL' => 'http://127.0.0.1:21399',
        ]);

        AppEnv::getInstance()->applyRuntimeConfig([
            'cookie' => [
                'suppress_response_paths' => [
                    '/__bench/framework',
                ],
            ],
        ]);

        $method = new ReflectionMethod($app, 'syncCookieRouteStateFromServer');
        $method->setAccessible(true);
        $method->invoke($app);

        self::assertSame([], HeaderCollector::getInstance()->getCookies());
    }

    private function createAppForRequest(string $uri, array $server = []): App
    {
        $server = ['REQUEST_URI' => $uri] + $server;
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'unit',
            ],
            'input' => [
                'uri' => $uri,
                'server' => $server,
            ],
        ]));
        WelineEnv::set('request.uri', $uri, 'unit test');
        WelineEnv::set('is_static_file', false, 'unit test');

        return new App();
    }

    private function shouldEagerStartSession(App $app): bool
    {
        $method = new ReflectionMethod($app, 'shouldEagerStartSessionForCurrentRequest');
        $method->setAccessible(true);

        return (bool)$method->invoke($app);
    }
}
