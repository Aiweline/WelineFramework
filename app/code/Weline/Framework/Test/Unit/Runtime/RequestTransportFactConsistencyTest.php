<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

/**
 * 传输事实一致性契约：`WelineEnv::setServer()` 写入 input.server 的同时，
 * 必须同步到对应的 Context 别名键，保证"读上下文"和"读 server 键"拿到同一个值。
 *
 * 这是"上下文单一权威"的可回归门禁：任何一条别名同步被删掉，这里就红。
 */
final class RequestTransportFactConsistencyTest extends TestCase
{
    private ?Context $contextBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextBackup = Context::getCurrent();
        Context::enter(new Context());
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->contextBackup !== null) {
            Context::enter($this->contextBackup);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0:string,1:mixed,2:string}>
     */
    public static function transportFacts(): array
    {
        return [
            'request uri' => ['REQUEST_URI', '/a/b?x=1', 'input.uri'],
            'request method' => ['REQUEST_METHOD', 'POST', 'input.method'],
            'http host' => ['HTTP_HOST', 'shop.example.test', 'input.host'],
            'request scheme' => ['REQUEST_SCHEME', 'https', 'input.scheme'],
            'remote addr' => ['REMOTE_ADDR', '10.0.0.9', 'input.ip'],
            'origin request uri' => ['WELINE_ORIGIN_REQUEST_URI', '/origin?a=1', 'input.origin_request_uri'],
            'full request uri' => ['WELINE_FULL_REQUEST_URI', 'https://shop.example.test/a', 'input.full_request_uri'],
        ];
    }

    /**
     * @dataProvider transportFacts
     */
    public function testServerWriteSyncsContextAlias(string $serverKey, mixed $value, string $contextPath): void
    {
        WelineEnv::setServer($serverKey, $value, 'test');

        self::assertSame($value, Context::current()->get($contextPath));
        self::assertSame($value, Context::current()->server($serverKey));
    }

    public function testHeaderWriteSyncsHeaderBag(): void
    {
        WelineEnv::setServer('HTTP_X_WELINE_TEST', 'ctx-header', 'test');

        self::assertSame('ctx-header', Context::current()->header('X-Weline-Test'));
    }

    /**
     * Context 里没有这个键时，serverOrGlobal 必须回落超全局；
     * 有键（哪怕是空串）就以 Context 为准，不能偷偷去读超全局。
     */
    public function testServerOrGlobalFallsBackOnlyWhenContextLacksKey(): void
    {
        $serverBackup = $_SERVER;
        try {
            $_SERVER = ['WLS_WORKER_ID' => 'worker-7', 'WLS_PORT' => '9555'];
            WelineEnv::setServer('REQUEST_URI', '/from-context', 'test');

            self::assertSame('/from-context', WelineEnv::serverOrGlobal('REQUEST_URI'));
            self::assertSame('worker-7', WelineEnv::serverOrGlobal('WLS_WORKER_ID'));
            self::assertSame('fallback', WelineEnv::serverOrGlobal('NOPE_KEY', 'fallback'));

            WelineEnv::setServer('WLS_PORT', '', 'test');
            self::assertSame('', WelineEnv::serverOrGlobal('WLS_PORT'));

            self::assertSame('worker-7', WelineEnv::serverAllOrGlobal()['WLS_WORKER_ID']);
        } finally {
            $_SERVER = $serverBackup;
        }
    }

    public function testInboundCookieBagPrefersContextThenGlobal(): void
    {
        $cookieBackup = $_COOKIE;
        try {
            $_COOKIE = ['from_global' => '1'];
            self::assertSame(['from_global' => '1'], WelineEnv::inboundCookieBag());

            Context::current()->set('input.cookie', ['from_context' => '2']);
            self::assertSame(
                ['from_global' => '1', 'from_context' => '2'],
                WelineEnv::inboundCookieBag()
            );
        } finally {
            $_COOKIE = $cookieBackup;
        }
    }
}
