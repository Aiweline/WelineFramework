<?php

declare(strict_types=1);

namespace WeShop\QA\Service;

use WeShop\Customer\Model\Customer;
use WeShop\Notification\Service\NotificationService;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\QA\Model\Question;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * 问答服务
 */
class QAService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_ORDER = 'order';
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_ANONYMOUS = 'anonymous';

    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 50;
    private const MAX_MENTIONED_CUSTOMERS = 20;

    public function __construct(
        private readonly ?NotificationService $notificationService = null,
        private readonly ?Url $url = null,
        private readonly ?Question $questionModel = null,
        private readonly ?OrderItem $orderItemModel = null,
        private readonly ?Customer $customerModel = null
    ) {
    }

    /**
     * 创建问题
     * 
     * @param array $questionData 问题数据
     * @return Question
     */
    public function createQuestion(array $questionData): Question
    {
        $productId = (int) ($questionData['product_id'] ?? 0);
        $customerId = (int) ($questionData['customer_id'] ?? 0);
        $questionText = trim((string) ($questionData['question'] ?? ''));
        $purchaseContext = $this->resolvePurchaseContext($customerId, $productId);
        $sourceType = $this->resolveSourceType($questionData, $customerId, $purchaseContext);
        $isAnonymous = $sourceType === self::SOURCE_ANONYMOUS || !empty($questionData['is_anonymous']);
        $displayName = $this->resolveDisplayName($questionData, $customerId, $sourceType, $isAnonymous);
        $mentionedCustomerIds = $this->extractMentionedCustomerIds(
            $questionText,
            $questionData['mentioned_customer_ids'] ?? [],
            $customerId
        );
        $status = trim((string) ($questionData['status'] ?? ''));
        if ($status === '') {
            $status = self::STATUS_APPROVED;
        }
        $isRecommended = (int) (
            $questionData['is_recommended']
            ?? ($sourceType === self::SOURCE_SYSTEM ? 1 : 0)
        );
        $orderId = $sourceType === self::SOURCE_ORDER
            ? (int) ($purchaseContext['order_id'] ?? 0)
            : (int) ($questionData['order_id'] ?? 0);

        $question = $this->createQuestionModel();
        
        $question->clearData()
            ->setData(Question::schema_fields_PRODUCT_ID, $productId)
            ->setData(Question::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Question::schema_fields_ORDER_ID, $orderId)
            ->setData(Question::schema_fields_SOURCE_TYPE, $sourceType)
            ->setData(Question::schema_fields_IS_ANONYMOUS, $isAnonymous ? 1 : 0)
            ->setData(Question::schema_fields_IS_RECOMMENDED, $isRecommended > 0 ? 1 : 0)
            ->setData(Question::schema_fields_DISPLAY_NAME, $displayName)
            ->setData(Question::schema_fields_MENTIONED_CUSTOMER_IDS, json_encode($mentionedCustomerIds))
            ->setData(Question::schema_fields_QUESTION, $questionText)
            ->setData(Question::schema_fields_ANSWER, (string) ($questionData['answer'] ?? ''))
            ->setData(Question::schema_fields_STATUS, $status)
            ->setData(Question::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->save();

        if ($customerId > 0 && $mentionedCustomerIds !== [] && (int) $question->getId() > 0) {
            $this->notifyMentionedCustomers($question, $mentionedCustomerIds, $displayName);
        }
        
        return $question;
    }

    public function createSystemQuestion(array $questionData): Question
    {
        $questionData['source_type'] = self::SOURCE_SYSTEM;
        $questionData['status'] = $questionData['status'] ?? self::STATUS_APPROVED;
        $questionData['is_recommended'] = $questionData['is_recommended'] ?? 1;
        $questionData['customer_id'] = $questionData['customer_id'] ?? 0;

        return $this->createQuestion($questionData);
    }
    
    /**
     * 获取产品问题列表
     * 
     * @param int $productId 产品ID
     * @return array
     */
    public function getProductQuestions(int $productId, int $limit = 0): array
    {
        $question = $this->baseApprovedProductQuestionQuery($productId);
        if ($limit > 0) {
            $question->limit($limit);
        }
        
        return $this->applyQuestionSort($question)
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductQuestionsPage(int $productId, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE, int $targetQuestionId = 0): array
    {
        $pageSize = $this->normalizePageSize($pageSize);
        $page = max(1, $page);
        if ($targetQuestionId > 0) {
            $page = $this->resolveQuestionPage($productId, $targetQuestionId, $pageSize, $page);
        }

        $question = $this->baseApprovedProductQuestionQuery($productId);
        $this->applyQuestionSort($question)->pagination($page, $pageSize);
        $items = $question->select()->fetchArray();
        $total = (int) $question->getTotalCount();

        return [
            'items' => array_values(array_filter($items, 'is_array')),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => max(1, (int) ceil($total / $pageSize)),
            'target_question_id' => $targetQuestionId,
            'pagination' => $question->getPagination(),
        ];
    }

    public function resolveQuestionPage(int $productId, int $questionId, int $pageSize = self::DEFAULT_PAGE_SIZE, int $defaultPage = 1): int
    {
        $pageSize = $this->normalizePageSize($pageSize);
        $defaultPage = max(1, $defaultPage);
        if ($productId <= 0 || $questionId <= 0) {
            return $defaultPage;
        }

        $target = $this->createQuestionModel();
        $target->load($questionId);
        if (!$target->getId()
            || (int) $target->getData(Question::schema_fields_PRODUCT_ID) !== $productId
            || (string) $target->getData(Question::schema_fields_STATUS) !== self::STATUS_APPROVED
        ) {
            return $defaultPage;
        }

        $isRecommended = (int) $target->getData(Question::schema_fields_IS_RECOMMENDED);
        $createdAt = (string) $target->getData(Question::schema_fields_CREATED_AT);
        $aheadCount = 0;

        if ($isRecommended < 1) {
            $aheadCount += $this->baseApprovedProductQuestionQuery($productId)
                ->where(Question::schema_fields_IS_RECOMMENDED, 1)
                ->count();
        }

        if ($createdAt !== '') {
            $aheadCount += $this->baseApprovedProductQuestionQuery($productId)
                ->where(Question::schema_fields_IS_RECOMMENDED, $isRecommended)
                ->where(Question::schema_fields_CREATED_AT, $createdAt, '>')
                ->count();

            $aheadCount += $this->baseApprovedProductQuestionQuery($productId)
                ->where(Question::schema_fields_IS_RECOMMENDED, $isRecommended)
                ->where(Question::schema_fields_CREATED_AT, $createdAt)
                ->where(Question::schema_fields_ID, $questionId, '>')
                ->count();
        } else {
            $aheadCount += $this->baseApprovedProductQuestionQuery($productId)
                ->where(Question::schema_fields_IS_RECOMMENDED, $isRecommended)
                ->where(Question::schema_fields_ID, $questionId, '>')
                ->count();
        }

        return (int) floor($aheadCount / $pageSize) + 1;
    }

    public function removeQuestion(int $questionId, int $customerId): bool
    {
        /** @var Question $question */
        $question = $this->createQuestionModel();
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        if ((int) $question->getData(Question::schema_fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }

        return (bool) $question->delete()->fetch();
    }

    /**
     * 获取所有待审核问题列表
     *
     * @param string|null $status 状态筛选
     * @param int $page 页码
     * @param int $size 每页数量
     * @return array
     */
    public function getPendingQuestions(?string $status = null, int $page = 1, int $size = 20): array
    {
        $question = $this->createQuestionModel();

        $question->clear();

        if ($status !== null) {
            $question->where(Question::schema_fields_STATUS, $status);
        }

        return $question
            ->pagination($page, $size)
            ->order(Question::schema_fields_CREATED_AT, 'DESC')
            ->order(Question::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取问题详情
     *
     * @param int $questionId 问题ID
     * @return Question|null
     */
    public function getQuestion(int $questionId): ?Question
    {
        $question = $this->createQuestionModel();
        $question->load($questionId);

        if (!$question->getId()) {
            return null;
        }

        return $question;
    }

    /**
     * 审核通过问题
     *
     * @param int $questionId 问题ID
     * @param string|null $answer 回复内容
     * @return bool
     */
    public function approveQuestion(int $questionId, ?string $answer = null): bool
    {
        $question = $this->createQuestionModel();
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        $question->setData(Question::schema_fields_STATUS, self::STATUS_APPROVED);

        if ($answer !== null) {
            $question->setData(Question::schema_fields_ANSWER, $answer);
        }

        $question->setData(Question::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        return (bool) $question->save()->fetch();
    }

    /**
     * 拒绝问题
     *
     * @param int $questionId 问题ID
     * @return bool
     */
    public function rejectQuestion(int $questionId): bool
    {
        $question = $this->createQuestionModel();
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        $question->setData(Question::schema_fields_STATUS, self::STATUS_REJECTED);
        $question->setData(Question::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        return (bool) $question->save()->fetch();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function updateQuestionMetadata(int $questionId, array $metadata): bool
    {
        $question = $this->createQuestionModel();
        $question->load($questionId);

        if (!$question->getId()) {
            return false;
        }

        $sourceType = trim((string) ($metadata['source_type'] ?? $question->getData(Question::schema_fields_SOURCE_TYPE)));
        if (!array_key_exists($sourceType, $this->getSourceTypeOptions())) {
            $sourceType = self::SOURCE_CUSTOMER;
        }

        $isRecommended = !empty($metadata['is_recommended']) ? 1 : 0;
        if ($sourceType === self::SOURCE_SYSTEM) {
            $isRecommended = 1;
        }

        $displayName = trim((string) ($metadata['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $sourceType === self::SOURCE_SYSTEM
                ? (string) __('系统推荐')
                : (string) $question->getData(Question::schema_fields_DISPLAY_NAME);
        }

        $question
            ->setData(Question::schema_fields_SOURCE_TYPE, $sourceType)
            ->setData(Question::schema_fields_IS_ANONYMOUS, $sourceType === self::SOURCE_ANONYMOUS ? 1 : 0)
            ->setData(Question::schema_fields_IS_RECOMMENDED, $isRecommended)
            ->setData(Question::schema_fields_DISPLAY_NAME, mb_substr($displayName, 0, 120))
            ->setData(Question::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        return (bool) $question->save()->fetch();
    }

    /**
     * 获取待审核问题数量
     *
     * @param string|null $status 状态筛选
     * @return int
     */
    public function getPendingQuestionsCount(?string $status = null): int
    {
        $question = $this->createQuestionModel();

        $question->clear();

        if ($status !== null) {
            $question->where(Question::schema_fields_STATUS, $status);
        }

        $result = $question->select()->fetch();

        return is_array($result) ? count($result) : 0;
    }

    public function getSourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            self::SOURCE_SYSTEM => (string) __('系统推荐'),
            self::SOURCE_ORDER => (string) __('已下单用户'),
            self::SOURCE_ANONYMOUS => (string) __('匿名用户'),
            default => (string) __('客户问答'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSourceTypeOptions(): array
    {
        return [
            self::SOURCE_CUSTOMER => $this->getSourceTypeLabel(self::SOURCE_CUSTOMER),
            self::SOURCE_ORDER => $this->getSourceTypeLabel(self::SOURCE_ORDER),
            self::SOURCE_ANONYMOUS => $this->getSourceTypeLabel(self::SOURCE_ANONYMOUS),
            self::SOURCE_SYSTEM => $this->getSourceTypeLabel(self::SOURCE_SYSTEM),
        ];
    }

    private function baseApprovedProductQuestionQuery(int $productId): Question
    {
        return $this->createQuestionModel()
            ->clear()
            ->where(Question::schema_fields_PRODUCT_ID, $productId)
            ->where(Question::schema_fields_STATUS, self::STATUS_APPROVED);
    }

    private function applyQuestionSort(Question $question): Question
    {
        return $question
            ->order(Question::schema_fields_IS_RECOMMENDED, 'DESC')
            ->order(Question::schema_fields_CREATED_AT, 'DESC')
            ->order(Question::schema_fields_ID, 'DESC');
    }

    private function normalizePageSize(int $pageSize): int
    {
        return min(self::MAX_PAGE_SIZE, max(1, $pageSize));
    }

    /**
     * @return array{has_order: bool, order_id: int}
     */
    private function resolvePurchaseContext(int $customerId, int $productId): array
    {
        if ($customerId <= 0 || $productId <= 0) {
            return ['has_order' => false, 'order_id' => 0];
        }

        $orderItem = $this->createOrderItemModel();
        $rows = $orderItem->clear()
            ->joinModel(Order::class, 'o', 'main_table.order_id=o.order_id', 'inner', 'o.order_id as ordered_order_id')
            ->where('main_table.' . OrderItem::schema_fields_PRODUCT_ID, $productId)
            ->where('o.' . Order::schema_fields_customer_id, $customerId)
            ->order('o.' . Order::schema_fields_created_at, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        $row = is_array($rows[0] ?? null) ? $rows[0] : [];
        $orderId = (int) ($row['ordered_order_id'] ?? $row[OrderItem::schema_fields_ORDER_ID] ?? 0);

        return [
            'has_order' => $orderId > 0,
            'order_id' => $orderId,
        ];
    }

    /**
     * @param array<string, mixed> $questionData
     * @param array{has_order: bool, order_id: int} $purchaseContext
     */
    private function resolveSourceType(array $questionData, int $customerId, array $purchaseContext): string
    {
        $requested = trim((string) ($questionData['source_type'] ?? ''));
        if (in_array($requested, [self::SOURCE_SYSTEM, self::SOURCE_ORDER, self::SOURCE_CUSTOMER, self::SOURCE_ANONYMOUS], true)) {
            return $requested;
        }

        if ($customerId <= 0 || !empty($questionData['is_anonymous'])) {
            return self::SOURCE_ANONYMOUS;
        }

        return !empty($purchaseContext['has_order'])
            ? self::SOURCE_ORDER
            : self::SOURCE_CUSTOMER;
    }

    /**
     * @param array<string, mixed> $questionData
     */
    private function resolveDisplayName(array $questionData, int $customerId, string $sourceType, bool $isAnonymous): string
    {
        $explicit = trim((string) ($questionData['display_name'] ?? ''));
        if ($explicit !== '') {
            return mb_substr($explicit, 0, 120);
        }

        if ($sourceType === self::SOURCE_SYSTEM) {
            return (string) __('系统推荐');
        }

        if ($isAnonymous || $customerId <= 0) {
            return (string) __('匿名用户');
        }

        $customer = $this->createCustomerModel();
        $customer->load($customerId);
        if ($customer->getId()) {
            $name = trim((string) $customer->getFullName());
            if ($name !== '') {
                return $name;
            }
        }

        return (string) __('客户 #%{1}', [$customerId]);
    }

    /**
     * @return array<int, int>
     */
    private function extractMentionedCustomerIds(string $text, mixed $explicitMentions, int $actorCustomerId): array
    {
        $ids = [];
        if (is_array($explicitMentions)) {
            $ids = array_merge($ids, array_map('intval', $explicitMentions));
        } elseif (is_string($explicitMentions) && trim($explicitMentions) !== '') {
            $ids = array_merge($ids, array_map('intval', preg_split('/\s*,\s*/', $explicitMentions) ?: []));
        }

        if (preg_match_all('/@(?:customer:|客户|用户|#)?(\d{1,10})/u', $text, $matches)) {
            $ids = array_merge($ids, array_map('intval', $matches[1] ?? []));
        }

        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0 && $id !== $actorCustomerId
        )));

        return array_slice($ids, 0, self::MAX_MENTIONED_CUSTOMERS);
    }

    /**
     * @param array<int, int> $mentionedCustomerIds
     */
    private function notifyMentionedCustomers(Question $question, array $mentionedCustomerIds, string $actorName): void
    {
        $notificationService = $this->getNotificationService();
        if (!$notificationService) {
            return;
        }

        $productId = (int) $question->getData(Question::schema_fields_PRODUCT_ID);
        $questionId = (int) $question->getId();
        $targetUrl = $this->buildQuestionTargetUrl($productId, $questionId);

        foreach ($mentionedCustomerIds as $customerId) {
            try {
                $notificationService->sendNotification([
                    'customer_id' => $customerId,
                    'type' => 'qa_mention',
                    'title' => (string) __('您在商品问答中被提及'),
                    'content' => (string) __('客户 %{1} 在商品问答中提到了您。', [$actorName]),
                    'target_url' => $targetUrl,
                ]);
            } catch (\Throwable $throwable) {
                w_log_warning('QA mention notification failed: ' . $throwable->getMessage(), [
                    'question_id' => $questionId,
                    'customer_id' => $customerId,
                ], 'notification');
            }
        }
    }

    private function buildQuestionTargetUrl(int $productId, int $questionId): string
    {
        $path = '/qa?product_id=' . $productId . '&question_id=' . $questionId;
        $url = $path;
        $urlBuilder = $this->getUrlBuilder();
        if ($urlBuilder) {
            try {
                $url = (string) $urlBuilder->getUrl('qa', [
                    'product_id' => $productId,
                    'question_id' => $questionId,
                ]);
            } catch (\Throwable) {
                $url = $path;
            }
        }

        return $url . '#qa-question-' . $questionId;
    }

    private function createQuestionModel(): Question
    {
        return $this->questionModel ? clone $this->questionModel : ObjectManager::getInstance(Question::class);
    }

    private function createOrderItemModel(): OrderItem
    {
        return $this->orderItemModel ? clone $this->orderItemModel : ObjectManager::getInstance(OrderItem::class);
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
