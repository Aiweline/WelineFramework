<?php
declare(strict_types=1);

namespace WeShop\Review\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class ReviewQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ReviewService $reviewService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'review';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'create' => $this->create($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported review provider operation: %{1}', $operation)
            ),
        };
    }

    private function create(array $params): array
    {
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please log in to write a review.'),
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ];
        }

        $productId = (int)($params['product_id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        if ($productId <= 0 || $content === '') {
            return [
                'success' => false,
                'message' => (string)__('Product and content are required.'),
            ];
        }

        $review = $this->reviewService->createReview([
            'product_id' => $productId,
            'customer_id' => $customerId,
            'rating' => max(1, min(5, (int)($params['rating'] ?? 5))),
            'title' => trim((string)($params['title'] ?? '')),
            'content' => $content,
        ]);

        return [
            'success' => true,
            'message' => (string)__('Thank you for submitting your review.'),
            'data' => ['review_id' => (int)($review->getId() ?? 0)],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'review',
            'name' => __('Review Query'),
            'description' => __('Provides frontend review operations through the worker API.'),
            'module' => 'WeShop_Review',
            'operations' => [
                [
                    'name' => 'create',
                    'description' => __('Submit a frontend product review.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'rating' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 5],
                        'title' => ['type' => 'string', 'required' => false, 'max_length' => 120],
                        'content' => ['type' => 'string', 'required' => true, 'max_length' => 2000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit product review',
                ],
            ],
        ];
    }
}
