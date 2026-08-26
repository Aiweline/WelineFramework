<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Search\Controller\Router;

final class RouterTest extends TestCase
{
    public function testPublicSearchPathRoutesToSearchIndexController(): void
    {
        $path = '/search/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('search/frontend', $path);
        self::assertSame('Weline_Search', $rule['module'] ?? null);
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
        $path = 'search';
        $rule = ['module' => 'Existing_Module'];

        Router::process($path, $rule);

        self::assertSame('search', $path);
        self::assertSame(['module' => 'Existing_Module'], $rule);
    }
}
