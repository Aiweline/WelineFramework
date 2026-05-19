<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller\Frontend\Wishlist;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Add extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly WishlistService $wishlistService,
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
                    'message' => __('请先登录。'),
                    'data' => [
                        'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                    ],
                ]);
            }

            $this->getMessageManager()->addError(__('请先登录。'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $productId = $this->readProductId();
        if ($productId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('缺少商品 ID。'),
            ]);
        }

        $this->wishlistService->addToWishlist($customerId, $productId);
        $wishlistCount = $this->wishlistService->getCustomerWishlistCount($customerId);

        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => true,
                'message' => __('已加入心愿单。'),
                'data' => [
                    'product_id' => $productId,
                    'wishlist_count' => $wishlistCount,
                ],
            ]);
        }

        $this->getMessageManager()->addSuccess(__('已加入心愿单。'));
        $this->redirect('wishlist');
        return '';
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
}
