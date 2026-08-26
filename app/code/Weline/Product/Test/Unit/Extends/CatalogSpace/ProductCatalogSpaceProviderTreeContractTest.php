<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\CatalogSpace;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Extends\Module\Weline_Framework\Query\CatalogQueryProvider;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Catalog\Service\CatalogScopeGuard;
use Weline\Catalog\Service\CatalogSpaceRegistry;

final class ProductCatalogSpaceProviderTreeContractTest extends TestCase
{
    public function testCatalogQueryTreeMatchesProviderTreeJson(): void
    {
        $tree = [['category_id' => 1, 'name' => 'Root', 'nodes' => []]];
        $provider = new class($tree) implements \Weline\Catalog\Api\CatalogSpaceProviderInterface {
            /** @param list<array<string, mixed>> $tree */
            public function __construct(private readonly array $tree) {}
            public function code(): string { return 'product'; }
            public function label(): string { return 'product'; }
            public function sortOrder(): int { return 10; }
            public function icon(): string { return ''; }
            public function normalizeScope(array $params): array { return $params; }
            public function tree(array $scope): array { return $this->tree; }
            public function view(array $scope, int $nodeId): ?array { return null; }
            public function save(array $scope, array $payload): array { return []; }
            public function delete(array $scope, int $nodeId): void {}
            public function reorder(array $scope, array $payload): array { return []; }
            public function readDisplaySelection(array $scope): array { return []; }
            public function saveDisplaySelection(array $scope, array $payload): array { return []; }
            public function searchNodes(array $scope, string $query): array { return []; }
            public function resolveNodeUrl(array $scope, int $nodeId): string { return ''; }
            public function listNavCandidates(array $scope): array { return []; }
            public function eavEntityCode(): string { return 'category'; }
            public function attributeEditorCatalog(): array { return []; }
            public function readAttributes(array $scope, int $nodeId): array { return []; }
            public function writeAttributes(array $scope, int $nodeId, array $rows): array { return []; }
            public function externalTaxonomyRequired(): bool { return false; }
            public function validateExternalTaxonomyId(string $externalId): bool { return true; }
            public function listExternalTaxonomyPicker(array $scope, string $query): array { return []; }
            public function invalidateAfterMutation(array $scope, string $reason, int $nodeId = 0): void {}
        };

        $registry = $this->createMock(CatalogSpaceRegistry::class);
        $registry->method('get')->with('product')->willReturn($provider);
        $query = new CatalogQueryProvider(new CatalogHubService($registry, new CatalogScopeGuard()));

        $viaQuery = $query->execute('tree', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
        ]);
        $viaProvider = $provider->tree(['website_id' => 0, 'locale' => '']);

        self::assertSame(json_encode($tree), json_encode($viaQuery));
        self::assertSame(json_encode($viaProvider), json_encode($viaQuery));
    }
}
