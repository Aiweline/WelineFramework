<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Exception\ProductQuoteRequestStateTransitionException;
use Weline\Product\Model\ProductQuoteRequest;

/**
 * Product quote-request status machine (Order-style can/before/changed events).
 */
final class ProductQuoteRequestStateMachine
{
    public const ERROR_NOT_FOUND = 'quote_request_not_found';
    public const ERROR_ILLEGAL_TRANSITION = 'quote_request_transition_invalid';
    public const ERROR_BLOCKED = 'quote_request_transition_blocked';

    public const EVENT_CAN_TRANSITION = 'Weline_Product::quote_request_status_can_transition';
    public const EVENT_CHANGE_BEFORE = 'Weline_Product::quote_request_status_change_before';
    public const EVENT_CHANGED = 'Weline_Product::quote_request_status_changed';

    /**
     * @var array<string, list<string>>
     */
    private array $transitions = [
        ProductQuoteRequest::STATUS_NEW => [
            ProductQuoteRequest::STATUS_PROCESSING,
            ProductQuoteRequest::STATUS_REPLIED,
            ProductQuoteRequest::STATUS_PROCESSED,
            ProductQuoteRequest::STATUS_CANCELLED,
        ],
        ProductQuoteRequest::STATUS_PROCESSING => [
            ProductQuoteRequest::STATUS_REPLIED,
            ProductQuoteRequest::STATUS_PROCESSED,
            ProductQuoteRequest::STATUS_CANCELLED,
        ],
        ProductQuoteRequest::STATUS_REPLIED => [
            ProductQuoteRequest::STATUS_PROCESSING,
            ProductQuoteRequest::STATUS_PROCESSED,
            ProductQuoteRequest::STATUS_CANCELLED,
        ],
        ProductQuoteRequest::STATUS_PROCESSED => [],
        ProductQuoteRequest::STATUS_CANCELLED => [],
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly EventsManager $eventsManager,
        private readonly ?ProductQuoteRequest $quoteRequestModel = null,
    ) {
    }

    public function canTransition(string $from, string $to): bool
    {
        $from = $this->normalizeStatus($from);
        $to = $this->normalizeStatus($to);
        if ($from === '' || $to === '') {
            return false;
        }
        if ($from === $to) {
            return true;
        }

        $canTransition = isset($this->transitions[$from])
            && in_array($to, $this->transitions[$from], true);

        $eventData = [
            'from_status' => $from,
            'to_status' => $to,
            'can_transition' => $canTransition,
            'transitions' => $this->transitions,
        ];
        $this->eventsManager->dispatch(self::EVENT_CAN_TRANSITION, $eventData);

        return ($eventData['can_transition'] ?? $canTransition) === true;
    }

    /**
     * @return list<string>
     */
    public function getAvailableTransitions(string $currentStatus): array
    {
        $currentStatus = $this->normalizeStatus($currentStatus);

        return array_values($this->transitions[$currentStatus] ?? []);
    }

    /**
     * @return array{quote_request_id:int,old_status:string,new_status:string,admin_reply_at:?string}
     */
    public function transition(int $quoteRequestId, string $newStatus, ?string $comment = null): array
    {
        $quoteRequestId = max(0, $quoteRequestId);
        $newStatus = $this->normalizeStatus($newStatus);
        if ($quoteRequestId <= 0 || $newStatus === '') {
            throw new ProductQuoteRequestStateTransitionException(
                self::ERROR_NOT_FOUND,
                (string)__('询价单不存在'),
                ['quote_request_id' => $quoteRequestId],
            );
        }

        $model = $this->newQuote()->load($quoteRequestId);
        if (!(int)$model->getId()) {
            throw new ProductQuoteRequestStateTransitionException(
                self::ERROR_NOT_FOUND,
                (string)__('询价单不存在'),
                ['quote_request_id' => $quoteRequestId],
            );
        }

        $oldStatus = $this->normalizeStatus((string)$model->getData(ProductQuoteRequest::schema_fields_STATUS));
        if ($oldStatus === $newStatus) {
            return [
                'quote_request_id' => $quoteRequestId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'admin_reply_at' => $this->nullableTimestamp($model->getData(ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT)),
            ];
        }

        if (!$this->canTransition($oldStatus, $newStatus)) {
            throw new ProductQuoteRequestStateTransitionException(
                self::ERROR_ILLEGAL_TRANSITION,
                (string)__('询价单状态不能从 %{1} 转换到 %{2}', [$oldStatus, $newStatus]),
                ['quote_request_id' => $quoteRequestId, 'from' => $oldStatus, 'to' => $newStatus],
            );
        }

        $eventData = [
            'quote_request' => $model,
            'quote_request_id' => $quoteRequestId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'comment' => $comment,
            'can_change' => true,
        ];
        $this->eventsManager->dispatch(self::EVENT_CHANGE_BEFORE, $eventData);
        if (($eventData['can_change'] ?? true) !== true) {
            throw new ProductQuoteRequestStateTransitionException(
                self::ERROR_BLOCKED,
                (string)__('询价单状态转换被阻止'),
                ['quote_request_id' => $quoteRequestId, 'from' => $oldStatus, 'to' => $newStatus],
            );
        }

        $now = date('Y-m-d H:i:s');
        $model->setData(ProductQuoteRequest::schema_fields_STATUS, $newStatus);
        $model->setData(ProductQuoteRequest::schema_fields_UPDATED_AT, $now);

        // Unread badge: customer sees admin reply/close as a signal.
        if (in_array($newStatus, [
            ProductQuoteRequest::STATUS_REPLIED,
            ProductQuoteRequest::STATUS_PROCESSED,
        ], true)) {
            $existingReply = trim((string)($model->getData(ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT) ?? ''));
            if ($existingReply === '') {
                $model->setData(ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT, $now);
            }
        }

        $model->save();

        $eventData['quote_request'] = $model;
        $eventData['admin_reply_at'] = $this->nullableTimestamp(
            $model->getData(ProductQuoteRequest::schema_fields_ADMIN_REPLY_AT)
        );
        $this->eventsManager->dispatch(self::EVENT_CHANGED, $eventData);

        return [
            'quote_request_id' => $quoteRequestId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_reply_at' => $eventData['admin_reply_at'],
        ];
    }

    private function newQuote(): ProductQuoteRequest
    {
        $model = $this->quoteRequestModel
            ?? $this->objectManager->getInstance(ProductQuoteRequest::class);

        return $model->clear();
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }
}
