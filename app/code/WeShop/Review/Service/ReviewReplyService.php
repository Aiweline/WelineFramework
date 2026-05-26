<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Customer\Model\Customer;
use WeShop\Notification\Service\NotificationService;
use WeShop\Review\Model\Review;
use WeShop\Review\Model\ReviewReply;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class ReviewReplyService
{
    private const MAX_MENTIONED_CUSTOMERS = 20;
    private const MAX_NOTIFICATION_RECIPIENTS = 40;

    public function __construct(
        private readonly ?NotificationService $notificationService = null,
        private readonly ?Url $url = null,
        private readonly ?ReviewReply $replyModel = null,
        private readonly ?Review $reviewModel = null,
        private readonly ?Customer $customerModel = null
    ) {
    }

    /**
     * @param array<string, mixed> $replyData
     */
    public function createReply(array $replyData): ReviewReply
    {
        $reviewId = (int) ($replyData['review_id'] ?? 0);
        $customerId = (int) ($replyData['customer_id'] ?? 0);
        $content = trim((string) ($replyData['content'] ?? ''));

        if ($reviewId <= 0 || $customerId <= 0 || $content === '') {
            throw new \InvalidArgumentException((string) __('Review, customer and reply content are required.'));
        }

        $review = $this->loadApprovedReview($reviewId);
        $productId = (int) $review->getData(Review::schema_fields_PRODUCT_ID);
        $parentReplyId = max(0, (int) ($replyData['parent_reply_id'] ?? 0));
        $parentReply = $this->loadParentReply($reviewId, $parentReplyId);
        $displayName = $this->resolveDisplayName($replyData, $customerId);
        $mentionedCustomerIds = $this->extractMentionedCustomerIds(
            $content,
            $replyData['mentioned_customer_ids'] ?? [],
            $customerId
        );
        $now = date('Y-m-d H:i:s');

        $reply = $this->createReplyModel();
        $reply->clearData()
            ->setData(ReviewReply::schema_fields_REVIEW_ID, $reviewId)
            ->setData(ReviewReply::schema_fields_PARENT_REPLY_ID, $parentReply ? (int) $parentReply->getId() : 0)
            ->setData(ReviewReply::schema_fields_PRODUCT_ID, $productId)
            ->setData(ReviewReply::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(ReviewReply::schema_fields_DISPLAY_NAME, $displayName)
            ->setData(ReviewReply::schema_fields_CONTENT, mb_substr($content, 0, 2000))
            ->setData(ReviewReply::schema_fields_MENTIONED_CUSTOMER_IDS, $this->encodeJson($mentionedCustomerIds))
            ->setData(ReviewReply::schema_fields_STATUS, ReviewReply::STATUS_APPROVED)
            ->setData(ReviewReply::schema_fields_CREATED_AT, $now)
            ->setData(ReviewReply::schema_fields_UPDATED_AT, $now)
            ->save();

        if ((int) $reply->getId() > 0) {
            $this->notifyRelatedCustomers($review, $reply, $parentReply, $mentionedCustomerIds, $displayName);
        }

        return $reply;
    }

    /**
     * @param array<int, int> $reviewIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getRepliesForReviews(array $reviewIds, bool $approvedOnly = true): array
    {
        $reviewIds = array_values(array_unique(array_filter(array_map('intval', $reviewIds))));
        if ($reviewIds === []) {
            return [];
        }

        $reply = $this->createReplyModel();
        $reply->clear()
            ->where(ReviewReply::schema_fields_REVIEW_ID, $reviewIds, 'IN');

        if ($approvedOnly) {
            $reply->where(ReviewReply::schema_fields_STATUS, ReviewReply::STATUS_APPROVED);
        }

        $rows = $reply
            ->order(ReviewReply::schema_fields_CREATED_AT, 'ASC')
            ->order(ReviewReply::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $payload = $this->buildReplyPayloadFromRow($row);
            $reviewId = (int) ($payload['review_id'] ?? 0);
            if ($reviewId <= 0) {
                continue;
            }

            $grouped[$reviewId][] = $payload;
        }

        return $grouped;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRepliesForReview(int $reviewId, bool $approvedOnly = true): array
    {
        return $this->getRepliesForReviews([$reviewId], $approvedOnly)[$reviewId] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClientReplyPayload(ReviewReply $reply): array
    {
        return $this->buildReplyPayloadFromRow($reply->getData());
    }

    /**
     * @return array<int, int>
     */
    public function extractMentionedCustomerIds(string $text, mixed $explicitMentions, int $actorCustomerId): array
    {
        $ids = [];
        if (is_array($explicitMentions)) {
            $ids = array_merge($ids, array_map('intval', $explicitMentions));
        } elseif (is_string($explicitMentions) && trim($explicitMentions) !== '') {
            $ids = array_merge($ids, array_map('intval', preg_split('/\s*,\s*/', $explicitMentions) ?: []));
        }

        if (preg_match_all('/@(?:customer:|\x{5ba2}\x{6237}|\x{7528}\x{6237}|#)?(\d{1,10})/iu', $text, $matches)) {
            $ids = array_merge($ids, array_map('intval', $matches[1] ?? []));
        }

        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0 && $id !== $actorCustomerId
        )));

        return array_slice($ids, 0, self::MAX_MENTIONED_CUSTOMERS);
    }

    private function loadApprovedReview(int $reviewId): Review
    {
        $review = $this->createReviewModel();
        $review->load($reviewId);

        if (!$review->getId() || (string) $review->getData(Review::schema_fields_STATUS) !== Review::STATUS_APPROVED) {
            throw new \InvalidArgumentException((string) __('Review does not exist or is not visible.'));
        }

        return $review;
    }

    private function loadParentReply(int $reviewId, int $parentReplyId): ?ReviewReply
    {
        if ($parentReplyId <= 0) {
            return null;
        }

        $parentReply = $this->createReplyModel();
        $parentReply->load($parentReplyId);
        if (!$parentReply->getId()
            || (int) $parentReply->getData(ReviewReply::schema_fields_REVIEW_ID) !== $reviewId
            || (string) $parentReply->getData(ReviewReply::schema_fields_STATUS) !== ReviewReply::STATUS_APPROVED
        ) {
            throw new \InvalidArgumentException((string) __('Reply target does not exist.'));
        }

        return $parentReply;
    }

    /**
     * @param array<string, mixed> $replyData
     */
    private function resolveDisplayName(array $replyData, int $customerId): string
    {
        $explicit = trim((string) ($replyData['display_name'] ?? ''));
        if ($explicit !== '') {
            return mb_substr($explicit, 0, 120);
        }

        $customer = $this->createCustomerModel();
        $customer->load($customerId);
        if ($customer->getId()) {
            $name = trim((string) $customer->getFullName());
            if ($name !== '') {
                return $name;
            }
        }

        return (string) __('Customer #%{1}', [$customerId]);
    }

    /**
     * @param array<int, int> $mentionedCustomerIds
     */
    private function notifyRelatedCustomers(
        Review $review,
        ReviewReply $reply,
        ?ReviewReply $parentReply,
        array $mentionedCustomerIds,
        string $actorName
    ): void {
        $notificationService = $this->getNotificationService();
        if (!$notificationService) {
            return;
        }

        $actorCustomerId = (int) $reply->getData(ReviewReply::schema_fields_CUSTOMER_ID);
        $recipientReasons = $this->collectNotificationRecipientReasons($review, $parentReply, $mentionedCustomerIds, $actorCustomerId);
        if ($recipientReasons === []) {
            return;
        }

        $reviewId = (int) $review->getId();
        $replyId = (int) $reply->getId();
        $targetUrl = $this->buildReplyTargetUrl(
            (int) $review->getData(Review::schema_fields_PRODUCT_ID),
            $reviewId,
            $replyId
        );

        foreach ($recipientReasons as $customerId => $reason) {
            $isMention = $reason === 'mention';
            try {
                $notificationService->sendNotification([
                    'customer_id' => $customerId,
                    'type' => 'review_reply',
                    'title' => $isMention
                        ? (string) __('You were mentioned in a product review reply')
                        : (string) __('Your review has a new reply'),
                    'content' => $isMention
                        ? (string) __('Customer %{1} mentioned you in a product review reply.', [$actorName])
                        : (string) __('Customer %{1} replied in a product review.', [$actorName]),
                    'target_url' => $targetUrl,
                ]);
            } catch (\Throwable $throwable) {
                w_log_warning('Review reply notification failed: ' . $throwable->getMessage(), [
                    'review_id' => $reviewId,
                    'reply_id' => $replyId,
                    'customer_id' => $customerId,
                ], 'notification');
            }
        }
    }

    /**
     * @param array<int, int> $mentionedCustomerIds
     * @return array<int, int>
     */
    private function collectNotificationRecipients(
        Review $review,
        ?ReviewReply $parentReply,
        array $mentionedCustomerIds,
        int $actorCustomerId
    ): array {
        $recipients = [];
        $reviewCustomerId = (int) $review->getData(Review::schema_fields_CUSTOMER_ID);
        if ($reviewCustomerId > 0) {
            $recipients[] = $reviewCustomerId;
        }

        if ($parentReply) {
            $parentCustomerId = (int) $parentReply->getData(ReviewReply::schema_fields_CUSTOMER_ID);
            if ($parentCustomerId > 0) {
                $recipients[] = $parentCustomerId;
            }
        }

        $recipients = array_merge($recipients, $mentionedCustomerIds);
        $recipients = array_values(array_unique(array_filter(
            array_map('intval', $recipients),
            static fn (int $customerId): bool => $customerId > 0 && $customerId !== $actorCustomerId
        )));

        return array_slice($recipients, 0, self::MAX_NOTIFICATION_RECIPIENTS);
    }

    /**
     * @param array<int, int> $mentionedCustomerIds
     * @return array<int, string>
     */
    private function collectNotificationRecipientReasons(
        Review $review,
        ?ReviewReply $parentReply,
        array $mentionedCustomerIds,
        int $actorCustomerId
    ): array {
        $recipients = [];
        $reviewCustomerId = (int) $review->getData(Review::schema_fields_CUSTOMER_ID);
        if ($reviewCustomerId > 0 && $reviewCustomerId !== $actorCustomerId) {
            $recipients[$reviewCustomerId] = 'reply';
        }

        if ($parentReply) {
            $parentCustomerId = (int) $parentReply->getData(ReviewReply::schema_fields_CUSTOMER_ID);
            if ($parentCustomerId > 0 && $parentCustomerId !== $actorCustomerId) {
                $recipients[$parentCustomerId] = 'reply';
            }
        }

        foreach ($mentionedCustomerIds as $mentionedCustomerId) {
            $mentionedCustomerId = (int) $mentionedCustomerId;
            if ($mentionedCustomerId > 0 && $mentionedCustomerId !== $actorCustomerId) {
                $recipients[$mentionedCustomerId] = 'mention';
            }
        }

        if (count($recipients) <= self::MAX_NOTIFICATION_RECIPIENTS) {
            return $recipients;
        }

        return array_slice($recipients, 0, self::MAX_NOTIFICATION_RECIPIENTS, true);
    }

    private function buildReplyTargetUrl(int $productId, int $reviewId, int $replyId): string
    {
        $path = '/' . ReviewService::FRONTEND_ROUTE
            . '?product_id=' . $productId
            . '&review_id=' . $reviewId
            . '&reply_id=' . $replyId;
        $url = $path;
        $urlBuilder = $this->getUrlBuilder();
        if ($urlBuilder) {
            try {
                $url = (string) $urlBuilder->getUrl(ReviewService::FRONTEND_ROUTE, [
                    'product_id' => $productId,
                    'review_id' => $reviewId,
                    'reply_id' => $replyId,
                ]);
                if (trim($url) === '') {
                    $url = $path;
                }
            } catch (\Throwable) {
                $url = $path;
            }
        }

        return $url . '#review-reply-' . $replyId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildReplyPayloadFromRow(array $row): array
    {
        $createdAt = (string) ($row[ReviewReply::schema_fields_CREATED_AT] ?? '');

        return [
            'reply_id' => (int) ($row[ReviewReply::schema_fields_ID] ?? $row['id'] ?? 0),
            'review_id' => (int) ($row[ReviewReply::schema_fields_REVIEW_ID] ?? 0),
            'parent_reply_id' => (int) ($row[ReviewReply::schema_fields_PARENT_REPLY_ID] ?? 0),
            'product_id' => (int) ($row[ReviewReply::schema_fields_PRODUCT_ID] ?? 0),
            'customer_id' => (int) ($row[ReviewReply::schema_fields_CUSTOMER_ID] ?? 0),
            'customer_name' => (string) ($row[ReviewReply::schema_fields_DISPLAY_NAME] ?? __('Customer')),
            'content' => (string) ($row[ReviewReply::schema_fields_CONTENT] ?? ''),
            'mentioned_customer_ids' => $this->decodeCustomerIds($row[ReviewReply::schema_fields_MENTIONED_CUSTOMER_IDS] ?? []),
            'status' => (string) ($row[ReviewReply::schema_fields_STATUS] ?? ReviewReply::STATUS_APPROVED),
            'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function decodeCustomerIds(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    /**
     * @param array<int, int> $value
     */
    private function encodeJson(array $value): string
    {
        return $value === [] ? '' : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function createReplyModel(): ReviewReply
    {
        return $this->replyModel ? clone $this->replyModel : ObjectManager::getInstance(ReviewReply::class);
    }

    private function createReviewModel(): Review
    {
        return $this->reviewModel ? clone $this->reviewModel : ObjectManager::getInstance(Review::class);
    }

    private function createCustomerModel(): Customer
    {
        return $this->customerModel ? clone $this->customerModel : ObjectManager::getInstance(Customer::class);
    }

    private function getNotificationService(): ?NotificationService
    {
        if ($this->notificationService) {
            return $this->notificationService;
        }

        try {
            return ObjectManager::getInstance(NotificationService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getUrlBuilder(): ?Url
    {
        if ($this->url) {
            return $this->url;
        }

        try {
            return ObjectManager::getInstance(Url::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
