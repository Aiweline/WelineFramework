<?php
declare(strict_types=1);

namespace WeShop\QA\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\QA\Service\QAService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class QaQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly QAService $qaService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'qa';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'add', 'ask' => $this->add($params),
            'remove' => $this->remove($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported QA provider operation: %{1}', $operation)
            ),
        };
    }

    private function add(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired((string)__('Please log in to ask a question.'));
        }

        $productId = (int)($params['product_id'] ?? 0);
        $question = trim((string)($params['question'] ?? ''));
        if ($productId <= 0 || $question === '') {
            return [
                'success' => false,
                'message' => (string)__('Product ID and question text are required.'),
            ];
        }

        $this->qaService->createQuestion([
            'product_id' => $productId,
            'customer_id' => $customerId,
            'question' => $question,
        ]);

        return [
            'success' => true,
            'message' => (string)__('Your question has been submitted and is pending review.'),
        ];
    }

    private function remove(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired((string)__('Please log in to manage your questions.'));
        }

        $questionId = (int)($params['question_id'] ?? $params['item_id'] ?? 0);
        if ($questionId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Question ID is required.'),
            ];
        }

        if (!$this->qaService->removeQuestion($questionId, $customerId)) {
            return [
                'success' => false,
                'message' => (string)__('Cannot remove this question.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Question removed.'),
        ];
    }

    private function getCustomerId(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function loginRequired(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'qa',
            'name' => __('Product QA Query'),
            'description' => __('Provides frontend product Q&A operations through the worker API.'),
            'module' => 'WeShop_QA',
            'operations' => [
                [
                    'name' => 'add',
                    'description' => __('Submit a frontend product question.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'question' => ['type' => 'string', 'required' => true, 'max_length' => 1000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit product question',
                ],
                [
                    'name' => 'remove',
                    'description' => __('Remove a frontend product question owned by the customer.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'question_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Remove product question',
                ],
            ],
        ];
    }
}
