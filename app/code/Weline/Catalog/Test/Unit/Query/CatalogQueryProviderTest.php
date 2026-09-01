<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Extends\Module\Weline_Framework\Query\CatalogQueryProvider;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Catalog\Service\CatalogScopeGuard;
use Weline\Catalog\Service\CatalogSpaceRegistry;
use Weline\Framework\Manager\ObjectManager;

final class CatalogQueryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    private function hub(): CatalogHubService
    {
        return new CatalogHubService(
            new CatalogSpaceRegistry($this->createMock(ObjectManager::class)),
            new CatalogScopeGuard(),
        );
    }

    public function testSpacesOperationReturnsEmptyList(): void
    {
        $provider = new CatalogQueryProvider($this->hub());

        $result = $provider->execute('spaces');
        self::assertTrue($result['success']);
        self::assertSame([], $result['spaces']);
    }

    public function testDescriptorIncludesSpacesOperation(): void
    {
        $provider = new CatalogQueryProvider($this->hub());
        $names = array_column($provider->getDescriptor()['operations'], 'name');
        self::assertContains('spaces', $names);
        self::assertContains('googleTaxonomyTree', $names);
        self::assertContains('enqueueGoogleTaxonomyAiTranslation', $names);
        self::assertSame('catalog', $provider->getProviderName());
    }

    public function testStructureMutationsRequireWebsiteScope(): void
    {
        $provider = new CatalogQueryProvider($this->hub());
        $base = [
            'space' => 'product',
            'website_id' => 0,
            'category_id' => 1,
        ];

        foreach (['save', 'delete', 'reorder'] as $operation) {
            $storeResult = $provider->execute($operation, $base + ['scope_level' => 'store', 'store_id' => 1]);
            self::assertIsArray($storeResult);
            self::assertFalse($storeResult['success']);
            self::assertSame('catalog_scope_forbidden', $storeResult['error_code'] ?? '');
        }
    }

    public function testWebsiteScopeBlocksDisplaySelectionWrite(): void
    {
        $provider = new CatalogQueryProvider($this->hub());
        $result = $provider->execute('saveDisplaySelection', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
            'rows' => [],
        ]);
        self::assertIsArray($result);
        self::assertFalse($result['success']);
        self::assertSame('catalog_scope_forbidden', $result['error_code'] ?? '');
    }
}
