<?php

declare(strict_types=1);

namespace WeShop\Search\Api;

interface SearchDocumentExtenderInterface
{
    public function getTargetProviderCode(): string;

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function extendDocument(array $document, array $context = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getIndexConfiguration(): array;
}
