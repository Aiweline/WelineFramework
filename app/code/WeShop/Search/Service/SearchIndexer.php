<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchDocumentProviderInterface;
use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Api\SearchWritableEngineInterface;

class SearchIndexer
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly SearchDocumentProviderRegistry $providerRegistry,
        private readonly SearchDocumentExtenderRegistry $extenderRegistry
    ) {
    }

    public function configure(string $scope = 'default'): bool
    {
        $engine = $this->createEngine($scope);
        if (!$engine instanceof SearchWritableEngineInterface) {
            w_log_warning('Search engine does not support index configuration.');
            return false;
        }

        return $engine->configureIndex($this->getMergedIndexConfiguration());
    }

    public function rebuild(
        ?string $providerCode = null,
        bool $forceReindex = false,
        int $pageSize = self::DEFAULT_BATCH_SIZE,
        string $scope = 'default'
    ): bool {
        $engine = $this->createEngine($scope);
        if (!$engine instanceof SearchWritableEngineInterface) {
            w_log_warning('Search engine does not support index rebuild.');
            return false;
        }

        $providers = $this->resolveProviders($providerCode);
        if ($providers === []) {
            w_log_warning('No search document providers were resolved for rebuild.');
            return false;
        }

        if (!$engine->configureIndex($this->getMergedIndexConfiguration())) {
            return false;
        }

        foreach ($providers as $provider) {
            if ($forceReindex) {
                $engine->deleteByDocumentType($provider->getDocumentType());
            }

            $page = 1;
            while (true) {
                $documents = $provider->getBatchDocuments($page, $pageSize);
                if ($documents === []) {
                    break;
                }

                if (!$engine->upsertDocuments($this->normalizeDocuments($provider, $documents))) {
                    return false;
                }

                if (count($documents) < $pageSize) {
                    break;
                }

                $page++;
            }
        }

        return true;
    }

    public function indexEntity(string $providerCode, int|string $entityId, string $scope = 'default'): bool
    {
        $provider = $this->providerRegistry->getProvider($providerCode);
        if (!$provider) {
            w_log_warning('Search document provider not found: ' . $providerCode);
            return false;
        }

        $engine = $this->createEngine($scope);
        if (!$engine instanceof SearchWritableEngineInterface) {
            w_log_warning('Search engine does not support entity indexing.');
            return false;
        }

        if (!$engine->configureIndex($this->getMergedIndexConfiguration())) {
            return false;
        }

        $document = $provider->getDocumentByEntityId($entityId);
        if ($document === null) {
            return false;
        }

        return $engine->upsertDocuments([$this->normalizeDocument($provider, $document)]);
    }

    public function deleteEntity(string $providerCode, int|string $entityId, string $scope = 'default'): bool
    {
        $provider = $this->providerRegistry->getProvider($providerCode);
        if (!$provider) {
            w_log_warning('Search document provider not found: ' . $providerCode);
            return false;
        }

        $engine = $this->createEngine($scope);
        if (!$engine instanceof SearchWritableEngineInterface) {
            w_log_warning('Search engine does not support entity deletion.');
            return false;
        }

        return $engine->deleteDocuments([$provider->getDocumentId($entityId)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderDescriptors(): array
    {
        $descriptors = [];

        foreach ($this->providerRegistry->getAllProviders() as $provider) {
            $descriptors[] = $provider->getDescriptor();
        }

        return $descriptors;
    }

    protected function createEngine(string $scope = 'default'): ?SearchEngineInterface
    {
        return SearchEngineFactory::create($scope);
    }

    /**
     * @return array<int, SearchDocumentProviderInterface>
     */
    private function resolveProviders(?string $providerCode = null): array
    {
        if ($providerCode === null || trim($providerCode) === '') {
            return array_values($this->providerRegistry->getAllProviders());
        }

        $provider = $this->providerRegistry->getProvider($providerCode);
        if ($provider === null) {
            return [];
        }

        return [$provider];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMergedIndexConfiguration(): array
    {
        return $this->mergeIndexDefinitions(
            $this->providerRegistry->getMergedIndexConfiguration(),
            $this->extenderRegistry->getMergedIndexConfiguration()
        );
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeIndexDefinitions(array $left, array $right): array
    {
        $merged = [
            'searchable_fields' => [],
            'filterable_fields' => [],
            'sortable_fields' => [],
        ];

        foreach (['searchable_fields', 'filterable_fields', 'sortable_fields'] as $key) {
            foreach ([$left[$key] ?? [], $right[$key] ?? []] as $items) {
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $item = trim((string) $item);
                    if ($item === '' || in_array($item, $merged[$key], true)) {
                        continue;
                    }

                    $merged[$key][] = $item;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDocuments(SearchDocumentProviderInterface $provider, array $documents): array
    {
        $normalized = [];
        foreach ($documents as $document) {
            $normalized[] = $this->normalizeDocument($provider, $document);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function normalizeDocument(SearchDocumentProviderInterface $provider, array $document): array
    {
        $entityId = (int) ($document['entity_id'] ?? $document['product_id'] ?? $document['category_id'] ?? 0);
        if (!isset($document['document_id'])) {
            $document['document_id'] = $provider->getDocumentId($entityId);
        }

        if (!isset($document['document_type'])) {
            $document['document_type'] = $provider->getDocumentType();
        }

        if (!isset($document['entity_id'])) {
            $document['entity_id'] = $entityId;
        }

        if (!isset($document['url'])) {
            $document['url'] = '';
        }

        return $this->applyExtenders($provider, $document);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function applyExtenders(SearchDocumentProviderInterface $provider, array $document): array
    {
        $context = [
            'provider_code' => $provider->getProviderCode(),
            'document_type' => $provider->getDocumentType(),
            'entity_id' => (int) ($document['entity_id'] ?? 0),
            'provider_descriptor' => $provider->getDescriptor(),
        ];

        foreach ($this->extenderRegistry->getExtendersForProvider($provider->getProviderCode()) as $extender) {
            $document = $extender->extendDocument($document, $context);
        }

        return $document;
    }
}
