<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class WelineEnvStateResetTest extends TestCase
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

        Runtime::setMode(RuntimeInterface::MODE_WLS);
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        Runtime::resetModeCache();

        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_COOKIE = $this->originalCookie;
        $_FILES = $this->originalFiles;
    }

    public function testResetClearsRequestContextShadowedValues(): void
    {
        WelineEnv::set('area', 'backend', 'unit-test');
        self::assertSame('backend', RequestContext::get('env.area'));

        unset($_SERVER['WELINE_AREA']);

        WelineEnv::getInstance()->reset();

        self::assertNull(RequestContext::get('env.area'));
        self::assertNull(WelineEnv::get('area', null));
    }

    public function testResetAlsoClearsDirectRequestContextEnvShadow(): void
    {
        RequestContext::set('env.custom.shadow', '/stale');
        self::assertSame('/stale', WelineEnv::get('custom.shadow', null));

        WelineEnv::getInstance()->reset();

        self::assertNull(RequestContext::get('env.custom.shadow'));
        self::assertNull(WelineEnv::get('custom.shadow', null));
    }

    public function testRestoreReplaysCapturedParameterStateWithoutReReadingCurrentGlobals(): void
    {
        $_GET = ['foo' => 'from-snapshot'];
        $_POST = ['bar' => 'from-snapshot'];
        $_COOKIE = ['sid' => 'snapshot-sid'];

        $env = WelineEnv::getInstance();
        $env->initFromGlobals();
        $snapshot = $env->capture();

        $env->reset();
        $_GET = ['foo' => 'from-current-globals'];
        $_POST = ['bar' => 'from-current-globals'];
        $_COOKIE = ['sid' => 'current-sid'];

        $env->restore($snapshot);

        self::assertSame('from-snapshot', WelineEnv::getGet('foo'));
        self::assertSame('from-snapshot', WelineEnv::getPost('bar'));
        self::assertSame('snapshot-sid', WelineEnv::getCookie('sid'));
    }

    public function testInitFromSnapshotOverridesStaleGlobalsWithCurrentRequestMemoryState(): void
    {
        $_SERVER['REQUEST_URI'] = '/stale-from-globals';
        $_SERVER['HTTP_HOST'] = 'stale.test';
        $_GET = ['foo' => 'stale'];

        $env = WelineEnv::getInstance();
        $env->initFromSnapshot(
            ['foo' => 'fresh'],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/fresh-from-snapshot',
                'HTTP_HOST' => 'fresh.test',
                'WELINE_AREA' => 'backend',
                'WELINE_WEBSITE_URL' => 'https://fresh.test',
            ]
        );

        self::assertSame('fresh', WelineEnv::getGet('foo'));
        self::assertSame('/fresh-from-snapshot', WelineEnv::get('request.uri'));
        self::assertSame('fresh.test', WelineEnv::get('server.http_host'));
        self::assertSame('backend', WelineEnv::get('area'));
        self::assertSame('https://fresh.test', WelineEnv::get('website_url'));
    }

    public function testInitFromSnapshotPreservesCurrentRequestBody(): void
    {
        $env = WelineEnv::getInstance();
        $env->initFromSnapshot(
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/body-test',
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'fresh.test',
            ]
        );

        Context::current()->set('input.body', '{"foo":"bar"}');

        $env->initFromSnapshot(
            ['foo' => 'fresh'],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/body-test-2',
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'fresh.test',
            ]
        );

        self::assertSame('{"foo":"bar"}', WelineEnv::get('request.body'));
        self::assertSame('/body-test-2', WelineEnv::get('request.uri'));
    }

    public function testContextFullRequestUriWinsOverStaleRequestContextShadow(): void
    {
        $env = WelineEnv::getInstance();
        $env->initFromSnapshot(
            [],
            [],
            [],
            [],
            [
                'REQUEST_URI' => '/fresh-path?legacy=1',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'fresh.test',
                'WELINE_ORIGIN_REQUEST_URI' => '/fresh-path?legacy=1',
                'WELINE_FULL_REQUEST_URI' => 'https://fresh.test/fresh-path?legacy=1',
            ]
        );

        RequestContext::set('env.full_request_uri', '');
        RequestContext::set('env.origin_request_uri', '');

        self::assertSame('https://fresh.test/fresh-path?legacy=1', WelineEnv::get('full_request_uri'));
        self::assertSame('/fresh-path?legacy=1', WelineEnv::get('origin_request_uri'));
    }
}
