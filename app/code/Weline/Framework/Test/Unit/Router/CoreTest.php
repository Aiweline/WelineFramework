<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\UnitTest;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\Router\Core;

class CoreTest extends TestCase
{
    public function testSharedLocalizationParserFeedsCoreRoutePath(): void
    {
        $reflection = new ReflectionClass(Core::class);
        $core = $reflection->newInstanceWithoutConstructor();
        $strip = $reflection->getMethod('stripLeadingLocaleCurrencySegments');

        self::assertSame('catalog', $strip->invoke($core, 'CNY/zh_Hans_CN/catalog'));
        self::assertSame('catalog', $strip->invoke($core, 'zh_Hans_CN/CNY/catalog'));
        self::assertSame('USD/catalog', $strip->invoke($core, 'CNY/USD/catalog'));

        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);
        self::assertSame(
            'admin/login',
            $strip->invoke($core, $backendPrefix . '/zh_Hans_CN/CNY/admin/login')
        );
    }
}
