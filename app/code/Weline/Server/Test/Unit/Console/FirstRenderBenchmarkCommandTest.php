<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Benchmark\FirstRender;

final class FirstRenderBenchmarkCommandTest extends TestCase
{
    public function testResolveFirstRenderPathsFiltersAndRanksExplicitList(): void
    {
        $command = new FirstRender();
        $command->__init();

        self::assertSame(
            ['/', '/catalog/category/sports', '/product/demo'],
            $command->resolveFirstRenderPaths([
                'paths' => '/api/test,/product/demo,/catalog/category/sports,/,/product/demo',
            ], 8)
        );
    }
}
