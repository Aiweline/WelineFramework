<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Frontend\Review;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Create extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ReviewService $reviewService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            if ($this->shouldReturnJson()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Please log in to write a review.'),
                    'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
                ]);
            }

            $this->getMessageManager()->addError(__('Please log in to write a review.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $productId = $this->readProductId();
        $rating = $this->readRating();
        $title = $this->readString('title');
        $content = $this->readString('content');

        if ($productId <= 0 || !$content) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Product and content are required.'),
            ]);
        }

        $reviewData = [
            'product_id' => $productId,
            'customer_id' => $customerId,
            'rating' => $rating,
            'title' => $title,
            'content' => $content,
        ];

        try {
            $review = $this->reviewService->createReview($reviewData);
        } catch (\Throwable $exception) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Unable to submit your review right now.'),
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('Thank you for submitting your review.'),
            'data' => ['review_id' => (int) ($review->getId() ?? 0)],
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
        return (string) (
            $this->request->body($field)
            ?? $this->request->getPost($field)
            ?? $this->request->getParam($field)
            ?? ''
        );
    }
}
