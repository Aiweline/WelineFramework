<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Product\Controller\Router;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductControllerContractsPass().
 */
final class ProductControllerContractsTest extends TestCase
{
    public function testAllProductControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProductRouterDetailQuery::testBareProductWithIdQueryClaimsDetailAndWritesContext' => function () {
            $this->setUp_ProductRouterDetailQuery();
            try {
                $this->assert_ProductRouterDetailQuery_testBareProductWithIdQueryClaimsDetailAndWritesContext();
            } finally {
                $this->tearDown_ProductRouterDetailQuery();
            }
        },
            'ProductRouterDetailQuery::testBareProductWithSlugQueryClaimsDetailAndWritesContext' => function () {
            $this->setUp_ProductRouterDetailQuery();
            try {
                $this->assert_ProductRouterDetailQuery_testBareProductWithSlugQueryClaimsDetailAndWritesContext();
            } finally {
                $this->tearDown_ProductRouterDetailQuery();
            }
        },
            'ProductRouterDetailQuery::testBareProductWithoutIdentityDoesNotClaim' => function () {
            $this->setUp_ProductRouterDetailQuery();
            try {
                $this->assert_ProductRouterDetailQuery_testBareProductWithoutIdentityDoesNotClaim();
            } finally {
                $this->tearDown_ProductRouterDetailQuery();
            }
        },
            'ProductRouterDetailQuery::testPathSlugWritesContextOnce' => function () {
            $this->setUp_ProductRouterDetailQuery();
            try {
                $this->assert_ProductRouterDetailQuery_testPathSlugWritesContextOnce();
            } finally {
                $this->tearDown_ProductRouterDetailQuery();
            }
        },
        ];
    }

private function setUp_ProductRouterDetailQuery(): void
{
    $_GET = [];
    $ctx = Context::current();
    $ctx->remove('input.query.id');
    $ctx->remove('input.query.slug');
}

private function tearDown_ProductRouterDetailQuery(): void
{
    $_GET = [];
    $ctx = Context::current();
    $ctx->remove('input.query.id');
    $ctx->remove('input.query.slug');
}

private function assert_ProductRouterDetailQuery_testBareProductWithIdQueryClaimsDetailAndWritesContext(): void
{
    $_GET['id'] = '311';
    $path = 'product';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('weline_product/frontend/detail', $path);
    self::assertSame('Weline_Product', $rule['module'] ?? null);
    self::assertSame(311, (int) Context::current()->query('id'));
}

private function assert_ProductRouterDetailQuery_testBareProductWithSlugQueryClaimsDetailAndWritesContext(): void
{
    $_GET['slug'] = 'Rou-Fen-Shan';
    $path = 'product';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('weline_product/frontend/detail', $path);
    self::assertSame('Weline_Product', $rule['module'] ?? null);
    self::assertSame('rou-fen-shan', (string) Context::current()->query('slug'));
}

private function assert_ProductRouterDetailQuery_testBareProductWithoutIdentityDoesNotClaim(): void
{
    $path = 'product';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('product', $path);
    self::assertArrayNotHasKey('module', $rule);
}

private function assert_ProductRouterDetailQuery_testPathSlugWritesContextOnce(): void
{
    $path = 'product/rou-fen-shan';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('weline_product/frontend/detail', $path);
    self::assertSame('rou-fen-shan', (string) Context::current()->query('slug'));
}
}
