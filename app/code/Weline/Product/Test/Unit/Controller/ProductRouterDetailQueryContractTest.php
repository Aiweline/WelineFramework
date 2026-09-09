<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Product\Controller\Router;

final class ProductRouterDetailQueryContractTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $ctx = Context::current();
        $ctx->remove('input.query.id');
        $ctx->remove('input.query.slug');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $ctx = Context::current();
        $ctx->remove('input.query.id');
        $ctx->remove('input.query.slug');
    }

    public function testBareProductWithIdQueryClaimsDetailAndWritesContext(): void
    {
        $_GET['id'] = '311';
        $path = 'product';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/detail', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
        self::assertSame(311, (int)Context::current()->query('id'));
    }

    public function testBareProductWithSlugQueryClaimsDetailAndWritesContext(): void
    {
        $_GET['slug'] = 'Rou-Fen-Shan';
        $path = 'product';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/detail', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
        self::assertSame('rou-fen-shan', (string)Context::current()->query('slug'));
    }

    public function testBareProductWithoutIdentityDoesNotClaim(): void
    {
        $path = 'product';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('product', $path);
        self::assertArrayNotHasKey('module', $rule);
    }

    public function testPathSlugWritesContextOnce(): void
    {
        $path = 'product/rou-fen-shan';
        $rule = [];
        Router::process($path, $rule);

        self::assertSame('weline_product/frontend/detail', $path);
        self::assertSame('rou-fen-shan', (string)Context::current()->query('slug'));
    }
}
