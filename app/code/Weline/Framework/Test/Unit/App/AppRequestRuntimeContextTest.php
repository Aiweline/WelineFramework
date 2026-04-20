<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\App;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
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
        WelineEnv::getInstance()->reset();
        Context::leave();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
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
}
