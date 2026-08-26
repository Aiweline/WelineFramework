<?php

declare(strict_types=1);

namespace Weline\Catalog\Api;

/**
 * Catalog space provider SPI — each space (product, blog, …) implements all methods.
 */
interface CatalogSpaceProviderInterface
{
    public function code(): string;

    public function label(): string;

    public function sortOrder(): int;

    public function icon(): string;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalizeScope(array $params): array;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function tree(array $scope): array;

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    public function view(array $scope, int $nodeId): ?array;

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $scope, array $payload): array;

    /**
     * @param array<string, mixed> $scope
     */
    public function delete(array $scope, int $nodeId): void;

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reorder(array $scope, array $payload): array;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function readDisplaySelection(array $scope): array;

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDisplaySelection(array $scope, array $payload): array;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function searchNodes(array $scope, string $query): array;

    /**
     * @param array<string, mixed> $scope
     */
    public function resolveNodeUrl(array $scope, int $nodeId): string;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listNavCandidates(array $scope): array;

    public function eavEntityCode(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function attributeEditorCatalog(): array;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function readAttributes(array $scope, int $nodeId): array;

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function writeAttributes(array $scope, int $nodeId, array $rows): array;

    public function externalTaxonomyRequired(): bool;

    public function validateExternalTaxonomyId(string $externalId): bool;

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listExternalTaxonomyPicker(array $scope, string $query): array;

    /**
     * @param array<string, mixed> $scope
     */
    public function invalidateAfterMutation(array $scope, string $reason, int $nodeId = 0): void;
}
