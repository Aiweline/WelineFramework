<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Compare\Controller\Router;

final class RouterTest extends TestCase
{
    public function testPublicComparePathRoutesToCompareIndexController(): void
    {
        $path = '/compare/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('weline_compare/frontend', $path);
        self::assertSame('Weline_Compare', $rule['module'] ?? null);
    }

    public function testUnrelatedPathsAreNotRewritten(): void
    {
        $path = 'products';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('products', $path);
        self::assertSame([], $rule);
    }

    public function testAnExistingModuleMatchAlwaysWins(): void
    {
        $path = 'compare';
        $rule = ['module' => 'Existing_Module'];

        Router::process($path, $rule);

        self::assertSame('compare', $path);
        self::assertSame(['module' => 'Existing_Module'], $rule);
    }
}
