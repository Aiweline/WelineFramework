<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Router;

class RouterTest extends TestCase
{
    public function testRootPathRemainsOwnedByFrameworkHomepage(): void
    {
        $path = '';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('', $path);
        self::assertSame([], $rule);
    }

    public function testExistingRouteRuleIsNotChanged(): void
    {
        $path = 'weshop';
        $rule = [
            'module' => 'WeShop_Frontend',
            'class' => [
                'name' => 'WeShop\\Frontend\\Controller\\Index',
                'method' => 'index',
            ],
        ];

        Router::process($path, $rule);

        self::assertSame('weshop', $path);
        self::assertSame('WeShop_Frontend', $rule['module']);
    }

    public function testRouterDoesNotQueryThemeState(): void
    {
        $source = \file_get_contents(\dirname(__DIR__, 3) . '/Controller/Router.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('getActiveTheme', $source);
        self::assertStringNotContainsString("w_query('theme'", $source);
    }
}
