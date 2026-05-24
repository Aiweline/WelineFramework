<?php

declare(strict_types=1);

namespace WeShop\QA\Service;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Product\Service\ProductService;
use Weline\Framework\Http\Url;

class QAQuestionPageDataService
{
    public function __construct(
        private readonly QAService $qaService,
        private readonly ProductService $productService,
        private readonly CustomerContextInterface $customerContext,
        private readonly Url $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $productId, int $page = 1, int $pageSize = 10, int $targetQuestionId = 0): array
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        $questionsResult = $this->qaService->getProductQuestionsPage($productId, $page, $pageSize, $targetQuestionId);
        $questions = (array) ($questionsResult['items'] ?? []);
        $product = $this->productService->getProduct($productId);
        $resolvedPage = (int) ($questionsResult['page'] ?? max(1, $page));
        $resolvedPageSize = (int) ($questionsResult['page_size'] ?? max(1, $pageSize));
        $questionCount = (int) ($questionsResult['total'] ?? count($questions));

        return [
            'product' => $product ? $product->getData() : ['product_id' => $productId],
            'product_id' => $productId,
            'qa_list' => array_map(
                fn(array $question): array => $this->normalizeQuestion($question, $customerId, $targetQuestionId),
                array_values(array_filter($questions, 'is_array'))
            ),
            'question_count' => $questionCount,
            'page' => $resolvedPage,
            'page_size' => $resolvedPageSize,
            'page_count' => (int) ($questionsResult['page_count'] ?? max(1, (int) ceil($questionCount / max(1, $resolvedPageSize)))),
            'has_previous' => $resolvedPage > 1,
            'has_next' => $resolvedPage < (int) ($questionsResult['page_count'] ?? 1),
            'target_question_id' => $targetQuestionId,
            'can_ask' => true,
            'ask_action' => $this->url->getUrl('qa/add'),
            'remove_action' => $this->url->getUrl('qa/remove'),
            'login_url' => $this->url->getUrl('customer/account/login'),
            'previous_page_url' => $resolvedPage > 1 ? $this->url->getUrl('qa', [
                'product_id' => $productId,
                'page' => $resolvedPage - 1,
                'page_size' => $resolvedPageSize,
            ]) : '',
            'next_page_url' => $resolvedPage < (int) ($questionsResult['page_count'] ?? 1) ? $this->url->getUrl('qa', [
                'product_id' => $productId,
                'page' => $resolvedPage + 1,
                'page_size' => $resolvedPageSize,
            ]) : '',
        ];
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    protected function normalizeQuestion(array $question, int $customerId, int $targetQuestionId = 0): array
    {
        $questionCustomerId = (int) ($question['customer_id'] ?? 0);
        $sourceType = (string) ($question['source_type'] ?? QAService::SOURCE_CUSTOMER);
        $isAnonymous = (int) ($question['is_anonymous'] ?? 0) === 1;
        $displayName = trim((string) ($question['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $isAnonymous
                ? (string) __('匿名用户')
                : ($questionCustomerId > 0 ? (string) __('客户 #%{1}', [$questionCustomerId]) : (string) __('客户问答'));
        }
        $questionId = (int) ($question['question_id'] ?? 0);

        return [
            'question_id' => $questionId,
            'customer_id' => $questionCustomerId,
            'order_id' => (int) ($question['order_id'] ?? 0),
            'source_type' => $sourceType,
            'source_label' => $this->qaService->getSourceTypeLabel($sourceType),
            'is_anonymous' => $isAnonymous,
            'is_recommended' => (int) ($question['is_recommended'] ?? 0) === 1,
            'display_name' => $displayName,
            'mentioned_customer_ids' => $this->decodeMentionedCustomerIds($question['mentioned_customer_ids'] ?? ''),
            'question' => (string) ($question['question'] ?? ''),
            'answer' => (string) ($question['answer'] ?? ''),
            'answered_by' => (string) ($question['answered_by'] ?? __('Store team')),
            'status' => (string) ($question['status'] ?? 'approved'),
            'created_at' => (string) ($question['created_at'] ?? ''),
            'is_target' => $targetQuestionId > 0 && $questionId === $targetQuestionId,
            'is_owner' => $customerId > 0 && $customerId === $questionCustomerId,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function decodeMentionedCustomerIds(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $decoded))));
    }
}
