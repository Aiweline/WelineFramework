<?php

declare(strict_types=1);

namespace WeShop\Search\Api;

interface SearchWritableEngineInterface extends SearchEngineInterface
{
    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function upsertDocuments(array $documents, string $primaryKey = 'document_id'): bool;

    /**
     * @param array<int, string> $documentIds
     */
    public function deleteDocuments(array $documentIds): bool;

    public function deleteByDocumentType(string $documentType): bool;

    /**
     * @param array<string, mixed> $definition
     */
    public function configureIndex(array $definition = []): bool;
}
