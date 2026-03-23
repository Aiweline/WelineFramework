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
    public function build(int $productId): array
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        $questions = $this->qaService->getProductQuestions($productId);
        $product = $this->productService->getProduct($productId);

        return [
            'product' => $product ? $product->getData() : ['product_id' => $productId],
            'product_id' => $productId,
            'qa_list' => array_map(
                fn(array $question): array => $this->normalizeQuestion($question, $customerId),
                array_values(array_filter($questions, 'is_array'))
            ),
            'question_count' => count($questions),
            'can_ask' => $customerId > 0,
            'ask_action' => $this->url->getUrl('qa/add'),
            'remove_action' => $this->url->getUrl('qa/remove'),
            'login_url' => $this->url->getUrl('customer/account/login'),
        ];
    }

    /**
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    protected function normalizeQuestion(array $question, int $customerId): array
    {
        $questionCustomerId = (int) ($question['customer_id'] ?? 0);

        return [
            'question_id' => (int) ($question['question_id'] ?? 0),
            'customer_id' => $questionCustomerId,
            'question' => (string) ($question['question'] ?? ''),
            'answer' => (string) ($question['answer'] ?? ''),
            'answered_by' => (string) ($question['answered_by'] ?? __('Store team')),
            'status' => (string) ($question['status'] ?? 'approved'),
            'created_at' => (string) ($question['created_at'] ?? ''),
            'is_owner' => $customerId > 0 && $customerId === $questionCustomerId,
        ];
    }
}
