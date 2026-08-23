<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;

/**
 * Backend-specific Queue listing projection and partial renderer.
 *
 * This presentation object owns pagination HTML and template rendering; Queue
 * business mutations and invariants remain in QueueAdminService.
 */
final class QueueAdminListingView
{
    public const PAGE_SIZE = 10;
    public const SNAPSHOT_TEXT_LIMIT = 240;

    private const ALLOWED_STATUSES = [
        Queue::status_pending,
        Queue::status_running,
        Queue::status_done,
        Queue::status_error,
        Queue::status_stop,
    ];

    public function __construct(private readonly Template $template)
    {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{
     *   queues:array<int,mixed>,module:string,status:string,q:string,biz_key:string,
     *   queue_id:int,page:int,stats:array<string,int>,pagination:array<string,mixed>
     * }
     */
    public function state(array $params, bool $snapshot = false): array
    {
        $page = \max(1, (int)($params['page'] ?? 1));
        $module = $this->boundedString($params['module'] ?? '', 128, 'module');
        $status = $this->boundedString($params['status'] ?? '', 12, 'status');
        $search = $this->boundedString($params['q'] ?? '', 200, 'q');
        $queueId = (int)($params['queue_id'] ?? $params['id'] ?? 0);
        $bizKey = $this->boundedString($params['biz_key'] ?? '', 191, 'biz_key');
        if ($status !== '' && !\in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException((string)__('队列状态筛选值无效。'));
        }
        if ($queueId < 0) {
            throw new \InvalidArgumentException((string)__('queue_id 不能小于 0。'));
        }
        $paginationParams = \array_filter(
            [
                'module' => $module,
                'status' => $status,
                'q' => $search,
                'biz_key' => $bizKey,
                'queue_id' => $queueId,
            ],
            static fn (mixed $value): bool => $value !== '' && $value !== 0,
        );

        $queueListing = $this->newQueue();
        $queueListing->joinModel(Type::class, 't', 'main_table.type_id=t.type_id', 'left');
        if ($snapshot) {
            $queueListing->fields([
                'queue_id' => 'main_table.queue_id',
                'type_id' => 'main_table.type_id',
                'pid' => 'main_table.pid',
                'name' => 'main_table.name',
                'result' => 'SUBSTRING(main_table.result,1,' . self::SNAPSHOT_TEXT_LIMIT . ')',
                'status' => 'main_table.status',
                'finished' => 'main_table.finished',
                'auto' => 'main_table.auto',
                'module' => 'main_table.module',
                'biz_key' => 'main_table.biz_key',
                'create_time' => 'main_table.create_time',
                'end_at' => 'main_table.end_at',
            ]);
        }

        if ($module !== '') {
            $queueListing->where('t.module_name', $module);
        }
        if ($search !== '') {
            $queueListing->where(
                'CONCAT(main_table.name,main_table.content,main_table.result)',
                '%' . $search . '%',
                'LIKE',
            );
        }
        if ($queueId > 0) {
            $queueListing->where('main_table.' . Queue::schema_fields_ID, $queueId);
        }
        if ($bizKey !== '') {
            $queueListing->where('main_table.' . Queue::schema_fields_BIZ_KEY, $bizKey);
        }
        if ($status !== '') {
            $queueListing->where('main_table.' . Queue::schema_fields_status, $status);
        }

        $queueListing->additional('AND (t.enable = 1 OR t.enable IS NULL)')
            ->order('main_table.queue_id', 'DESC')
            ->pagination($page, self::PAGE_SIZE, $paginationParams)
            ->select()
            ->fetch();
        $items = $queueListing->getItems();
        if ($snapshot) {
            $this->compactListingItems($items);
        }
        $pagination = $queueListing->getPaginationState();

        return [
            'queues' => $items,
            'module' => $module,
            'status' => $status,
            'q' => $search,
            'biz_key' => $bizKey,
            'queue_id' => $queueId,
            'page' => $page,
            'stats' => $this->queueStats(),
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{success:true,changed:bool,revision:string,stats_html:string,listing_html:string}
     */
    public function snapshot(array $params): array
    {
        $state = $this->state($params, true);
        $knownRevision = $this->boundedString($params['known_revision'] ?? '', 64, 'known_revision');

        try {
            $this->template->unsetData();
            $statsHtml = (string)$this->template->fetchHtml(
                'Weline_Queue::templates/Backend/Queue/partials/stats.phtml',
                ['stats' => $state['stats']],
            );
            $this->template->unsetData();
            $listingHtml = (string)$this->template->fetchHtml(
                'Weline_Queue::templates/Backend/Queue/partials/listing.phtml',
                $state,
            );
        } finally {
            // Template is a WLS singleton. Never retain one request's backend
            // dictionary in the next request.
            $this->template->unsetData();
        }

        $revision = \hash('sha256', $statsHtml . "\0" . $listingHtml);
        $changed = $knownRevision === '' || !\hash_equals($revision, $knownRevision);

        return [
            'success' => true,
            'changed' => $changed,
            'revision' => $revision,
            'stats_html' => $changed ? $statsHtml : '',
            'listing_html' => $changed ? $listingHtml : '',
        ];
    }

    /** @return array<string,int> */
    private function queueStats(): array
    {
        $queue = $this->newQueue();

        return [
            'all' => (int)$queue->reset()->count(Queue::schema_fields_ID),
            'pending' => (int)$queue->reset()->where(Queue::schema_fields_status, Queue::status_pending)->count(Queue::schema_fields_ID),
            'running' => (int)$queue->reset()->where(Queue::schema_fields_status, Queue::status_running)->count(Queue::schema_fields_ID),
            'done' => (int)$queue->reset()->where(Queue::schema_fields_status, Queue::status_done)->count(Queue::schema_fields_ID),
            'error' => (int)$queue->reset()->where(Queue::schema_fields_status, Queue::status_error)->count(Queue::schema_fields_ID),
            'stop' => (int)$queue->reset()->where(Queue::schema_fields_status, Queue::status_stop)->count(Queue::schema_fields_ID),
        ];
    }

    /** @param array<int,mixed> $items */
    private function compactListingItems(array &$items): void
    {
        foreach ($items as &$item) {
            if (!\is_array($item)) {
                continue;
            }
            foreach (['result', 'content', 'process'] as $field) {
                if (\array_key_exists($field, $item)) {
                    $item[$field] = $this->limitSnapshotText((string)$item[$field]);
                }
            }
        }
        unset($item);
    }

    private function limitSnapshotText(string $text): string
    {
        $text = $this->normalizeQueueText($text);
        if ($text === '') {
            return '';
        }
        if (\function_exists('mb_strlen') && \mb_strlen($text, 'UTF-8') > self::SNAPSHOT_TEXT_LIMIT) {
            return \mb_substr($text, 0, self::SNAPSHOT_TEXT_LIMIT, 'UTF-8') . '...';
        }
        if (!\function_exists('mb_strlen') && \strlen($text) > self::SNAPSHOT_TEXT_LIMIT) {
            return \substr($text, 0, self::SNAPSHOT_TEXT_LIMIT) . '...';
        }

        return $text;
    }

    private function normalizeQueueText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = (string)\preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
        $text = (string)\preg_replace('/\x1B\][^\x07]*(\x07|\x1B\\\\)/', '', $text);
        if (\function_exists('mb_check_encoding') && !\mb_check_encoding($text, 'UTF-8')) {
            $detected = \mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'GB2312', 'BIG5'], true);
            if (\is_string($detected) && $detected !== '' && \strtoupper($detected) !== 'UTF-8') {
                $converted = @\mb_convert_encoding($text, 'UTF-8', $detected);
                if (\is_string($converted) && $converted !== '') {
                    $text = $converted;
                }
            }
        }

        return $text;
    }

    private function newQueue(): Queue
    {
        /** @var Queue $queue */
        $queue = ObjectManager::make(Queue::class);
        $queue->clearData()->clearQuery();

        return $queue;
    }

    private function boundedString(mixed $value, int $maxLength, string $field): string
    {
        $value = \trim((string)$value);
        if (\strlen($value) > $maxLength) {
            throw new \InvalidArgumentException((string)__('%{1} 长度不能超过 %{2}。', [$field, $maxLength]));
        }

        return $value;
    }
}
