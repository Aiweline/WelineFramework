<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller\Frontend\Wishlist;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Remove extends FrontendController
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
            return $this->fetchJson([
                'success' => false,
                'message' => __('Please log in to continue.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
                ],
            ]);
        }

        $wishlistId = $this->readWishlistId();
        if ($wishlistId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Wishlist item ID is required.'),
            ]);
        }

        $this->wishlistService->removeFromWishlist($wishlistId, $customerId);

        return $this->fetchJson([
            'success' => true,
            'message' => __('Removed from wishlist.'),
            'data' => [
                'wishlist_count' => $this->wishlistService->getCustomerWishlistCount($customerId),
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    protected function readWishlistId(): int
    {
        return (int) (
            $this->request->body('wishlist_id')
            ?? $this->request->body('item_id')
            ?? $this->request->getPost('wishlist_id')
            ?? $this->request->getPost('item_id')
            ?? $this->request->getParam('wishlist_id')
            ?? $this->request->getParam('item_id')
            ?? 0
        );
    }
}
