<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Exception\CatalogScopeForbiddenException;
use Weline\Catalog\Service\CatalogScopeGuard;

final class CatalogScopeGuardTest extends TestCase
{
    private CatalogScopeGuard $guard;

    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
        $this->guard = new CatalogScopeGuard();
    }

    protected function tearDown(): void
    {
        unset($this->guard);
    }

    public function testWebsiteScopeAllowsStructureSave(): void
    {
        $scope = $this->guard->resolve([
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 1,
        ]);
        $this->guard->assertOperationAllowed('save', $scope);
        self::assertTrue($scope->isWebsiteStructureScope());
    }

    public function testStoreScopeBlocksStructureSave(): void
    {
        $scope = $this->guard->resolve([
            'space' => 'product',
            'scope_level' => 'store',
            'website_id' => 1,
            'store_id' => 2,
        ]);
        $this->expectException(CatalogScopeForbiddenException::class);
        $this->guard->assertOperationAllowed('save', $scope);
    }

    public function testWebsiteScopeBlocksDisplaySelectionSave(): void
    {
        $scope = $this->guard->resolve([
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 1,
        ]);
        $this->expectException(CatalogScopeForbiddenException::class);
        $this->guard->assertOperationAllowed('saveDisplaySelection', $scope);
    }

    public function testDomainAliasMapsToSpace(): void
    {
        $scope = $this->guard->resolve([
            'domain' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
        ]);
        self::assertSame('product', $scope->space);
    }
}
