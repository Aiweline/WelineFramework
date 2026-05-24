<?php
declare(strict_types=1);

namespace WeShop\Review\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewPurchaseEligibilityService;
use WeShop\Review\Service\ReviewReplyService;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class ReviewQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ReviewService $reviewService,
        private readonly Url $url,
        private readonly ?ReviewConfigService $reviewConfigService = null,
        private readonly ?ReviewPurchaseEligibilityService $purchaseEligibilityService = null,
        private readonly ?ReviewReplyService $reviewReplyService = null
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
            'reply' => $this->reply($params),
            'resolveMode' => $this->resolveMode($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported review provider operation: %{1}', $operation)
            ),
        };
    }

    private function create(array $params): array
    {
        $reviewMode = $this->resolveReviewMode((string)($params['review_mode'] ?? ''));
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($reviewMode === ReviewConfigService::MODE_ORDER && $customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请登录后再评价。'),
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ];
        }

        $productId = (int)($params['product_id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        if ($productId <= 0 || $content === '') {
            return [
                'success' => false,
                'message' => (string)__('商品和评价内容不能为空。'),
            ];
        }

        if ($reviewMode === ReviewConfigService::MODE_ORDER
            && !$this->getPurchaseEligibilityService()->customerCanReviewProduct($customerId, $productId)
        ) {
            return [
                'success' => false,
                'message' => (string)__('只有购买过该商品的客户才能评价。'),
            ];
        }

        try {
            $review = $this->reviewService->createReview([
                'product_id' => $productId,
                'customer_id' => $reviewMode === ReviewConfigService::MODE_ORDER ? $customerId : 0,
                'rating' => max(1, min(5, (int)($params['rating'] ?? 5))),
                'title' => trim((string)($params['title'] ?? '')),
                'content' => $content,
                'rating_scores' => $this->extractStructuredParam($params, 'rating_scores'),
                'media_items' => $this->extractStructuredParam($params, 'media_items'),
            ]);
        } catch (\Throwable) {
            return [
                'success' => false,
                'message' => (string)__('暂时无法提交评价。'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('评价已提交，审核通过后会公开展示。'),
            'data' => [
                'review_id' => (int)($review->getId() ?? 0),
                'review_mode' => $reviewMode,
                'review' => $this->reviewService->buildClientReviewPayload($review),
            ],
        ];
    }

    private function reply(array $params): array
    {
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please sign in before replying.'),
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ];
        }

        $reviewId = (int)($params['review_id'] ?? 0);
        $content = trim((string)($params['content'] ?? ''));
        if ($reviewId <= 0 || $content === '') {
            return [
                'success' => false,
                'message' => (string)__('Review and reply content are required.'),
            ];
        }

        try {
            $reply = $this->getReviewReplyService()->createReply([
                'review_id' => $reviewId,
                'parent_reply_id' => (int)($params['parent_reply_id'] ?? 0),
                'customer_id' => $customerId,
                'content' => $content,
                'mentioned_customer_ids' => $this->extractStructuredParam($params, 'mentioned_customer_ids'),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return [
                'success' => false,
                'message' => (string)$exception->getMessage(),
            ];
        } catch (\Throwable) {
            return [
                'success' => false,
                'message' => (string)__('Unable to submit reply right now.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Reply submitted.'),
            'data' => [
                'reply_id' => (int)($reply->getId() ?? 0),
                'reply' => $this->getReviewReplyService()->buildClientReplyPayload($reply),
            ],
        ];
    }

    private function resolveMode(array $params): array
    {
        $productId = (int)($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('商品不能为空。'),
            ];
        }

        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'success' => true,
                'message' => (string)__('已选择匿名评论。'),
                'data' => [
                    'selected_mode' => ReviewConfigService::MODE_ANONYMOUS,
                    'is_logged_in' => false,
                    'can_order_review' => false,
                ],
            ];
        }

        $canOrderReview = $this->getPurchaseEligibilityService()->customerCanReviewProduct($customerId, $productId);

        return [
            'success' => true,
            'message' => $canOrderReview
                ? (string)__('已选择下单后评论。')
                : (string)__('未查询到该商品订单，已选择匿名评论。'),
            'data' => [
                'selected_mode' => $canOrderReview ? ReviewConfigService::MODE_ORDER : ReviewConfigService::MODE_ANONYMOUS,
                'is_logged_in' => true,
                'can_order_review' => $canOrderReview,
            ],
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
                    'name' => 'resolveMode',
                    'description' => __('点击写评论时自动判断本次评价方式；未登录前端直接选择匿名，已登录才查询是否购买过该商品。'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Resolve review mode',
                ],
                [
                    'name' => 'reply',
                    'description' => __('Reply to a product review. Mentions such as @customer:123 notify the mentioned customer.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'review_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'parent_reply_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                        'content' => ['type' => 'string', 'required' => true, 'max_length' => 2000],
                        'mentioned_customer_ids' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Reply to review',
                ],
                [
                    'name' => 'create',
                    'description' => __('提交商品评价，匿名模式允许游客提交，下单后评论模式要求登录并购买过该商品。'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'rating' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 5],
                        'review_mode' => ['type' => 'string', 'required' => false],
                        'title' => ['type' => 'string', 'required' => false, 'max_length' => 120],
                        'content' => ['type' => 'string', 'required' => true, 'max_length' => 2000],
                        'rating_scores' => ['type' => 'object', 'required' => false],
                        'media_items' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit product review',
                ],
            ],
        ];
    }

    private function extractStructuredParam(array $params, string $field): array
    {
        $value = $params[$field] ?? [];
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [$value];
        }

        return [];
    }

    private function getReviewConfigService(): ReviewConfigService
    {
        return $this->reviewConfigService ?? new ReviewConfigService();
    }

    private function resolveReviewMode(string $requestedMode): string
    {
        $configService = $this->getReviewConfigService();
        $requestedMode = trim($requestedMode);

        return $configService->normalizeReviewMode(
            $requestedMode !== '' ? $requestedMode : $configService->getReviewMode()
        );
    }

    private function getPurchaseEligibilityService(): ReviewPurchaseEligibilityService
    {
        return $this->purchaseEligibilityService ?? new ReviewPurchaseEligibilityService();
    }

    private function getReviewReplyService(): ReviewReplyService
    {
        return $this->reviewReplyService ?? new ReviewReplyService();
    }
}
