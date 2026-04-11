<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

final class ContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::leave();
        WelineEnv::getInstance()->reset();
    }

    public function testWelineEnvReadsFromCurrentContext(): void
    {
        $context = new Context([
            'route' => [
                'area' => 'backend',
                'website_id' => 12,
            ],
            'input' => [
                'query' => ['foo' => 'bar'],
                'post' => ['baz' => 'qux'],
                'cookie' => ['sid' => 'cookie-sid'],
                'host' => 'example.test',
            ],
            'session' => [
                'id' => 'sess-1',
                'user_id' => 99,
            ],
            'runtime' => [
                'attrs_raw' => [
                    'custom.key' => 'custom-value',
                ],
            ],
        ]);

        Context::enter($context);

        self::assertSame('backend', WelineEnv::get('area'));
        self::assertSame(12, WelineEnv::getWebsiteId());
        self::assertSame('bar', WelineEnv::getGet('foo'));
        self::assertSame('qux', WelineEnv::getPost('baz'));
        self::assertSame('cookie-sid', WelineEnv::getCookie('sid'));
        self::assertSame('example.test', WelineEnv::getHttpHost());
        self::assertSame(99, WelineEnv::getUserId());
        self::assertSame('sess-1', WelineEnv::getSessionId());
        self::assertSame('custom-value', WelineEnv::get('custom.key'));
    }

    public function testCurrentContextIsFiberLocal(): void
    {
        $mainContext = new Context([
            'route' => ['area' => 'frontend'],
        ]);
        Context::enter($mainContext);

        $fiber = new \Fiber(static function (): string {
            Context::enter(new Context([
                'route' => ['area' => 'backend'],
            ]));

            $area = (string)Context::current()->area();
            Context::leave();

            return $area;
        });

        $fiber->start();
        self::assertSame('backend', $fiber->getReturn());
        self::assertSame('frontend', Context::current()->area());
    }
}
