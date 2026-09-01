<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Controller\Router;

final class PromotionRouteTableTest extends TestCase
{
    public function testDynamicSlugRewritesToPageController(): void
    {
        $path = 'promotion/weekend';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('promotion/page', $path);
        self::assertSame('Weline_Promotion', $rule['module'] ?? '');
    }

    public function testFixedPromotionIndexSetsModule(): void
    {
        $path = 'promotion';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('promotion', $path);
        self::assertSame('Weline_Promotion', $rule['module'] ?? '');
    }

}
