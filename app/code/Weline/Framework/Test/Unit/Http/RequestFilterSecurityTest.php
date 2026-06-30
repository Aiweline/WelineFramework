<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request\RequestFilter;

final class RequestFilterSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::getInstance()->reload();
    }

    public function testSerializeFilterIsDisabledByDefault(): void
    {
        Env::getInstance()->reload();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('security.request_filter.allow_php_unserialize');

        RequestFilter::filter('serialize', 'a:1:{s:2:"id";i:1;}');
    }

    public function testSerializeFilterCanBeExplicitlyReEnabled(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'request_filter' => [
                    'allow_php_unserialize' => true,
                ],
            ],
        ]);

        self::assertSame(['id' => 1], RequestFilter::filter('serialize', 'a:1:{s:2:"id";i:1;}'));
    }

    public function testStringFilterEncodesArrayWithoutWarning(): void
    {
        self::assertSame('{"a":1,"b":2}', RequestFilter::filter('string', ['a' => 1, 'b' => 2]));
        self::assertSame('', RequestFilter::filter('string', null));
        self::assertSame('1', RequestFilter::filter('string', true));
        self::assertSame('', RequestFilter::filter('string', false));
        self::assertSame('plain', RequestFilter::filter('string', 'plain'));
    }
}
