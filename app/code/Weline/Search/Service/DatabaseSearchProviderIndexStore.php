<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Api\SearchProviderIndexStorageInterface;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Model\SearchProviderDocument;

final class DatabaseSearchProviderIndexStore implements SearchProviderIndexStorageInterface
{
    public function __construct(
        private readonly SearchProviderDocument $documentModel,
    ) {
    }

    public function upsert(IndexDocument $document): void
    {
        $model = clone $this->documentModel;
        // Identity is indexer + website + entity; wipe locale/store/channel variants first.
        $model->clearData()->reset()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $document->indexer)
            ->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $document->websiteId)
            ->where(SearchProviderDocument::schema_fields_ENTITY_ID, $document->entityId)
            ->delete()
            ->fetch();

        $model = clone $this->documentModel;
        $model->clearData();
        $row = $this->documentToRow($document);
        foreach ($row as $key => $value) {
            $model->setData($key, $value);
        }
        $model->save();
    }

    public function delete(string $indexer, int $websiteId, string $entityId): void
    {
        $indexer = trim($indexer);
        $entityId = trim($entityId);
        if ($indexer === '' || $entityId === '') {
            return;
        }

        $model = clone $this->documentModel;
        $query = $model->clearData()->reset()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $indexer)
            ->where(SearchProviderDocument::schema_fields_ENTITY_ID, $entityId);
        if ($websiteId >= 0) {
            $query->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $websiteId);
        }
        $query->delete()->fetch();
    }

    public function replaceIndexerWebsite(string $indexer, int $websiteId, array $documents): int
    {
        $indexer = trim($indexer);
        if ($indexer === '') {
            return 0;
        }

        $model = clone $this->documentModel;
        $model->clearData()->reset()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $indexer)
            ->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        $count = 0;
        foreach ($documents as $document) {
            if (!$document instanceof IndexDocument) {
                continue;
            }
            if ($document->indexer !== $indexer || $document->websiteId !== $websiteId) {
                continue;
            }
            $this->upsert($document);
            $count++;
        }

        return $count;
    }

    public function search(
        string $indexer,
        SearchRequest $request,
        SearchExpression $expression,
    ): array {
        $indexer = trim($indexer);
        $queryText = trim(mb_strtolower($request->q));
        if ($indexer === '' || $queryText === '') {
            return [];
        }

        $websiteIds = $request->websiteId > 0 ? [$request->websiteId, 0] : [0];
        $model = clone $this->documentModel;
        $dbQuery = $model->clearData()->reset()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $indexer)
            ->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $websiteIds, 'IN')
            ->where(SearchProviderDocument::schema_fields_STATUS, SearchProviderDocument::STATUS_PUBLISHED);

        if ($request->storeId > 0) {
            $dbQuery->where(
                SearchProviderDocument::schema_fields_STORE_ID,
                [0, $request->storeId],
                'IN',
            );
        }
        if ($request->channelId > 0) {
            $dbQuery->where(
                SearchProviderDocument::schema_fields_CHANNEL_ID,
                [0, $request->channelId],
                'IN',
            );
        }
        if ($request->locale !== '') {
            $dbQuery->where(
                SearchProviderDocument::schema_fields_LOCALE,
                ['', $request->locale],
                'IN',
            );
        }

        $like = '%' . $queryText . '%';
        $dbQuery->where([
            ['LOWER(main_table.title)', 'like', $like, 'OR'],
            ['LOWER(COALESCE(main_table.keywords, \'\'))', 'like', $like, 'OR'],
            ['LOWER(COALESCE(main_table.payload, \'\'))', 'like', $like, 'OR'],
            ['LOWER(main_table.url)', 'like', $like, 'OR'],
        ]);

        $rows = $dbQuery
            ->order(SearchProviderDocument::schema_fields_UPDATED_AT, 'DESC')
            ->limit(max(1, $expression->getLimit()))
            ->select()
            ->fetchArray();

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = json_decode((string)($row[SearchProviderDocument::schema_fields_PAYLOAD] ?? ''), true);
            $normalized[] = [
                'indexer' => (string)($row[SearchProviderDocument::schema_fields_INDEXER] ?? ''),
                'entity_type' => (string)($row[SearchProviderDocument::schema_fields_ENTITY_TYPE] ?? ''),
                'entity_id' => (string)($row[SearchProviderDocument::schema_fields_ENTITY_ID] ?? ''),
                'title' => (string)($row[SearchProviderDocument::schema_fields_TITLE] ?? ''),
                'url' => (string)($row[SearchProviderDocument::schema_fields_URL] ?? ''),
                'payload' => is_array($payload) ? $payload : [],
                'updated_at' => (string)($row[SearchProviderDocument::schema_fields_UPDATED_AT] ?? ''),
            ];
        }

        return array_slice($normalized, $expression->getOffset(), $expression->getLimit());
    }

    public function countIndexer(string $indexer, int $websiteId = -1): int
    {
        $indexer = trim($indexer);
        if ($indexer === '') {
            return 0;
        }

        $model = clone $this->documentModel;
        $query = $model->clearData()->reset()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $indexer);
        if ($websiteId >= 0) {
            $query->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $websiteId);
        }

        return (int)$query->count();
    }

    /** @return array<string,mixed> */
    private function documentToRow(IndexDocument $document): array
    {
        return [
            SearchProviderDocument::schema_fields_INDEXER => $document->indexer,
            SearchProviderDocument::schema_fields_ENTITY_TYPE => $document->entityType,
            SearchProviderDocument::schema_fields_ENTITY_ID => $document->entityId,
            SearchProviderDocument::schema_fields_WEBSITE_ID => $document->websiteId,
            SearchProviderDocument::schema_fields_STORE_ID => $document->storeId,
            SearchProviderDocument::schema_fields_CHANNEL_ID => $document->channelId,
            SearchProviderDocument::schema_fields_LOCALE => $document->locale,
            SearchProviderDocument::schema_fields_CURRENCY => $document->currency,
            SearchProviderDocument::schema_fields_TITLE => $document->title,
            SearchProviderDocument::schema_fields_KEYWORDS => json_encode(
                $document->keywords,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            SearchProviderDocument::schema_fields_URL => $document->url,
            SearchProviderDocument::schema_fields_PAYLOAD => json_encode(
                $document->payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            SearchProviderDocument::schema_fields_STATUS => $document->status,
            SearchProviderDocument::schema_fields_UPDATED_AT => $document->updatedAt !== ''
                ? $document->updatedAt
                : date('Y-m-d H:i:s'),
            SearchProviderDocument::schema_fields_DOCUMENT_VERSION => max(1, $document->documentVersion),
            SearchProviderDocument::schema_fields_PAYLOAD_HASH => $document->payloadHash,
        ];
    }
}
