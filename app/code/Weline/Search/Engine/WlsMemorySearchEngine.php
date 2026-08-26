<?php

declare(strict_types=1);

namespace Weline\Search\Engine;

use Weline\Search\Api\SearchEngineInterface;
use Weline\Search\Api\SearchProviderInterface;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Service\SearchExpression;

/**
 * Optional in-process memory index (switchable; not default).
 */
class WlsMemorySearchEngine implements SearchEngineInterface
{
    /** @var array<string, IndexDocument> */
    private static array $docs = [];

    public function code(): string
    {
        return 'wls_memory';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return [
            'fulltext' => true,
            'suggest' => true,
            'in_memory' => true,
            'remote' => false,
        ];
    }

    public function search(
        SearchExpression $expression,
        SearchRequest $request,
        SearchProviderInterface $provider,
    ): array {
        $q = mb_strtolower(trim($request->q));
        if ($q === '') {
            return [];
        }

        $indexer = $provider->code();
        $hits = [];
        foreach (self::$docs as $doc) {
            if ($doc->indexer !== $indexer && $doc->entityType !== $indexer) {
                continue;
            }
            if ($request->websiteId > 0 && $doc->websiteId > 0 && $doc->websiteId !== $request->websiteId) {
                continue;
            }
            if ($request->storeId > 0 && $doc->storeId > 0 && $doc->storeId !== $request->storeId) {
                continue;
            }
            if ($request->channelId > 0 && $doc->channelId > 0 && $doc->channelId !== $request->channelId) {
                continue;
            }
            $hay = mb_strtolower($doc->title . ' ' . implode(' ', $doc->keywords));
            if (!str_contains($hay, $q)) {
                continue;
            }
            $hits[] = new SearchHit(
                indexer: $indexer,
                entityType: $doc->entityType !== '' ? $doc->entityType : $indexer,
                entityId: $doc->entityId,
                title: $doc->title,
                url: $doc->url,
                payload: $doc->payload,
                score: 1.0,
            );
        }

        return array_slice($hits, $expression->getOffset(), $expression->getLimit());
    }

    public function upsert(array $documents): int
    {
        $n = 0;
        foreach ($documents as $document) {
            if (!$document instanceof IndexDocument) {
                continue;
            }
            $key = $document->indexer . ':' . $document->websiteId . ':' . $document->entityId;
            self::$docs[$key] = $document;
            $n++;
        }

        return $n;
    }

    public function delete(array $keys): int
    {
        $n = 0;
        foreach ($keys as $key) {
            $indexer = (string)($key['indexer'] ?? $key['entity_type'] ?? '');
            $entityId = (string)($key['entity_id'] ?? '');
            $websiteId = (int)($key['website_id'] ?? 0);
            $mapKey = $indexer . ':' . $websiteId . ':' . $entityId;
            if (isset(self::$docs[$mapKey])) {
                unset(self::$docs[$mapKey]);
                $n++;
            }
        }

        return $n;
    }

    /** @internal tests */
    public static function resetForTests(): void
    {
        self::$docs = [];
    }
}
