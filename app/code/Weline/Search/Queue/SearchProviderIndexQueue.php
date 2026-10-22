<?php

declare(strict_types=1);

namespace Weline\Search\Queue;

use Weline\Framework\App\SystemUpdateActivity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;
use Weline\Queue\Exception\QueueDeferredCompletionException;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Model\SearchProviderDocument;
use Weline\Search\Service\SearchProviderIndexService;

/**
 * Applies one coalesced provider-index change.
 * Full replacement stays on the repair command.
 */
final class SearchProviderIndexQueue implements QueueConsumerInterface
{
    public const CONTRACT = 'search.provider_index.v1';

    public function __construct(
        private readonly SearchProviderIndexService $indexService,
    ) {
    }

    public function name(): string
    {
        return (string)__('Search 内容索引变更');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('按版本写入或撤回一条内容索引；系统更新期间挂起');
    }

    public static function shouldApply(int $storedVersion, int $incomingRevision): bool
    {
        return $incomingRevision >= $storedVersion;
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        try {
            $this->decode($queue);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        if (SystemUpdateActivity::inProgress()) {
            $notBefore = gmdate('Y-m-d H:i:s', time() + 60);
            throw new QueueDeferredCompletionException(
                $queue->getContent(),
                (string)__('系统正在更新，内容索引变更挂起。'),
                'deferred_system_update',
                $notBefore,
            );
        }

        $payload = $this->decode($queue);
        $op = (string)$payload['op'];
        $indexer = (string)$payload['indexer'];
        $websiteId = (int)$payload['website_id'];
        if ($op === 'rebuild') {
            $count = $this->indexService->rebuild($indexer, $websiteId);

            return 'QUEUE_DONE: provider_index_rebuild count=' . $count;
        }

        $entityId = (string)$payload['entity_id'];
        $revision = (int)$payload['revision'];
        $stored = $this->storedVersion($indexer, $websiteId, $entityId);
        if (!self::shouldApply($stored, $revision)) {
            return 'QUEUE_DONE: provider_index_stale stored=' . $stored . ' incoming=' . $revision;
        }

        if ($op === 'delete') {
            $this->indexService->upsert($this->tombstone($indexer, $websiteId, $entityId, $revision));

            return 'QUEUE_DONE: provider_index_delete';
        }

        $document = IndexDocument::fromArray($payload['document']);
        $this->indexService->upsert($document->withVersion($revision));

        return 'QUEUE_DONE: provider_index_upsert';
    }

    /** @return array<string,mixed> */
    private function decode(QueueTaskContextInterface $queue): array
    {
        $content = json_decode($queue->getContent(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($content) || array_is_list($content)) {
            throw new \InvalidArgumentException('provider_index_content_invalid');
        }
        if (($content['contract'] ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('provider_index_contract_invalid');
        }
        $op = (string)($content['op'] ?? '');
        if (!in_array($op, ['upsert', 'delete', 'rebuild'], true)) {
            throw new \InvalidArgumentException('provider_index_op_invalid');
        }
        if (trim((string)($content['indexer'] ?? '')) === '') {
            throw new \InvalidArgumentException('provider_index_indexer_invalid');
        }
        if ($op !== 'rebuild' && trim((string)($content['entity_id'] ?? '')) === '') {
            throw new \InvalidArgumentException('provider_index_entity_invalid');
        }
        if ($op === 'upsert' && !is_array($content['document'] ?? null)) {
            throw new \InvalidArgumentException('provider_index_document_invalid');
        }

        return $content;
    }

    private function storedVersion(string $indexer, int $websiteId, string $entityId): int
    {
        $model = ObjectManager::getInstance(SearchProviderDocument::class);
        $rows = $model->reset()->clearData()
            ->where(SearchProviderDocument::schema_fields_INDEXER, $indexer)
            ->where(SearchProviderDocument::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SearchProviderDocument::schema_fields_ENTITY_ID, $entityId)
            ->select()
            ->fetchArray();
        $max = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $max = max($max, (int)($row[SearchProviderDocument::schema_fields_DOCUMENT_VERSION] ?? 0));
        }

        return $max;
    }

    private function tombstone(string $indexer, int $websiteId, string $entityId, int $revision): IndexDocument
    {
        return new IndexDocument(
            indexer: $indexer,
            entityType: 'tombstone',
            entityId: $entityId,
            websiteId: $websiteId,
            storeId: 0,
            channelId: 0,
            locale: '',
            currency: '',
            title: '',
            keywords: [],
            url: '',
            payload: [],
            status: SearchProviderDocument::STATUS_DISABLED,
            updatedAt: date('Y-m-d H:i:s'),
            documentVersion: $revision,
            payloadHash: '',
        );
    }
}
