<?php

declare(strict_types=1);

namespace WeShop\Search\Api;

interface SearchDocumentProviderInterface
{
    public function getProviderCode(): string;

    public function getDocumentType(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBatchDocuments(int $page = 1, int $pageSize = 100): array;

    /**
     * @param int|string $entityId
     * @return array<string, mixed>|null
     */
    public function getDocumentByEntityId(int|string $entityId): ?array;

    public function getDocumentId(int|string $entityId): string;

    /**
     * @return array<string, mixed>
     */
    public function getIndexConfiguration(): array;

    /**
     * @return array<string, mixed>
     */
    public function getDescriptor(): array;
}
