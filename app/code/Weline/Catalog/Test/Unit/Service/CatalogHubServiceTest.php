<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Api\CatalogSpaceProviderInterface;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Catalog\Service\CatalogScopeGuard;
use Weline\Catalog\Service\CatalogSpaceRegistry;

final class CatalogHubServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testExecuteTreeRoutesToProductProvider(): void
    {
        $tree = [['category_id' => 3, 'name' => 'A', 'nodes' => []]];
        $provider = new class($tree) implements CatalogSpaceProviderInterface {
            /** @param list<array<string, mixed>> $tree */
            public function __construct(private readonly array $tree)
            {
            }

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

        $hub = new CatalogHubService($registry, new CatalogScopeGuard());
        $result = $hub->execute('tree', [
            'space' => 'product',
            'scope_level' => 'website',
            'website_id' => 0,
        ]);

        self::assertSame($tree, $result);
    }
}
