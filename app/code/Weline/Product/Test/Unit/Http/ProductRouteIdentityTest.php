<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Product\Http\ProductRouteIdentity;

final class ProductRouteIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::leave();
        parent::tearDown();
    }

    public function testSlugFallsBackToContextWhenRequestParamEmpty(): void
    {
        Context::enter(new Context());
        Context::current()->set('input.query.slug', 'huazhaoji-dfc0090f-tc2bb-s7ca5');

        $request = new Request();
        self::assertSame('huazhaoji-dfc0090f-tc2bb-s7ca5', ProductRouteIdentity::slug($request));
    }

    public function testSlugPrefersRequestParamOverContext(): void
    {
        Context::enter(new Context());
        Context::current()->set('input.query.slug', 'from-context');

        $request = new Request();
        $request->setGet('slug', 'from-request');
        self::assertSame('from-request', ProductRouteIdentity::slug($request));
    }
}
