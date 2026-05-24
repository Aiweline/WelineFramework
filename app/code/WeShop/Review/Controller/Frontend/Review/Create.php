<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Frontend\Review;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewPurchaseEligibilityService;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Create extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ReviewService $reviewService,
        private readonly Url $url,
        private readonly ?ReviewConfigService $reviewConfigService = null,
        private readonly ?ReviewPurchaseEligibilityService $purchaseEligibilityService = null
    ) {
    }

    public function index(): string
    {
        $reviewMode = $this->resolveReviewMode($this->readString('review_mode'));
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($reviewMode === ReviewConfigService::MODE_ORDER && $customerId <= 0) {
            if ($this->shouldReturnJson()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请登录后再评价。'),
                    'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
                ]);
            }

            $this->getMessageManager()->addError(__('请登录后再评价。'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $productId = $this->readProductId();
        $rating = $this->readRating();
        $title = trim($this->readString('title'));
        $content = trim($this->readString('content'));
        $ratingScores = $this->readArray('rating_scores');
        $mediaItems = $this->readArray('media_items');

        if ($productId <= 0 || !$content) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('商品和评价内容不能为空。'),
            ]);
        }

        if ($reviewMode === ReviewConfigService::MODE_ORDER
            && !$this->getPurchaseEligibilityService()->customerCanReviewProduct($customerId, $productId)
        ) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('只有购买过该商品的客户才能评价。'),
            ]);
        }

        $reviewData = [
            'product_id' => $productId,
            'customer_id' => $reviewMode === ReviewConfigService::MODE_ORDER ? $customerId : 0,
            'rating' => $rating,
            'title' => $title,
            'content' => $content,
            'rating_scores' => $ratingScores,
            'media_items' => $mediaItems,
        ];

        try {
            $review = $this->reviewService->createReview($reviewData);
        } catch (\Throwable $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('暂时无法提交评价。'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('评价已提交，审核通过后会公开展示。'),
            'data' => [
                'review_id' => (int) ($review->getId() ?? 0),
                'review_mode' => $reviewMode,
                'review' => $this->reviewService->buildClientReviewPayload($review),
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function shouldReturnJson(): bool
    {
        return $this->request->isAjax() || strtoupper((string) $this->request->getMethod()) === 'POST';
    }

    protected function readProductId(): int
    {
        return (int) (
            $this->request->body('product_id')
            ?? $this->request->getPost('product_id')
            ?? $this->request->getParam('product_id')
            ?? 0
        );
    }

    protected function readRating(): int
    {
        $rating = (int) (
            $this->request->body('rating')
            ?? $this->request->getPost('rating')
            ?? 5
        );

        return max(1, min(5, $rating));
    }

    protected function readString(string $field): string
    {
        $value = $this->request->body($field);
        if ($value === null) {
            $value = $this->request->getPost($field);
        }
        if ($value === null) {
            $value = $this->request->getParam($field);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function readArray(string $field): array
    {
        $value = $this->request->body($field)
            ?? $this->request->getPost($field)
            ?? $this->request->getParam($field)
            ?? [];

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
}
