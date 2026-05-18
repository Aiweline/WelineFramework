<?php
declare(strict_types=1);

namespace WeShop\Wishlist\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Cart\Service\CartService;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class WishlistQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly WishlistService $wishlistService,
        private readonly CartService $cartService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'wishlist';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'add' => $this->add($params),
            'addFromCart' => $this->addFromCart($params),
            'remove' => $this->remove($params),
            'count' => $this->count(),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported wishlist provider operation: %{1}', $operation)
            ),
        };
    }

    private function add(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $productId = (int)($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Product ID is required.'),
            ];
        }

        $item = $this->wishlistService->addToWishlist($customerId, $productId);

        return [
            'success' => true,
            'message' => (string)__('Added to wishlist.'),
            'data' => [
                'item_id' => (int)($item->getId() ?? 0),
                'product_id' => $productId,
                'wishlist_count' => $this->wishlistService->getCustomerWishlistCount($customerId),
            ],
        ];
    }

    private function addFromCart(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $cartItemId = (int)($params['item_id'] ?? $params['cart_id'] ?? 0);
        if ($cartItemId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Cart item ID is required.'),
            ];
        }

        $productId = 0;
        foreach ($this->cartService->getCartItems($customerId) as $item) {
            $candidateItemId = (int)($item['cart_id'] ?? $item['item_id'] ?? 0);
            if ($candidateItemId === $cartItemId) {
                $productId = (int)($item['product_id'] ?? 0);
                break;
            }
        }

        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Cart item could not be found.'),
            ];
        }

        $item = $this->wishlistService->addToWishlist($customerId, $productId);
        $this->cartService->removeFromCart($cartItemId, $customerId);

        return [
            'success' => true,
            'message' => (string)__('Saved for later.'),
            'data' => [
                'item_id' => (int)($item->getId() ?? 0),
                'product_id' => $productId,
                'wishlist_count' => $this->wishlistService->getCustomerWishlistCount($customerId),
                'cart_count' => $this->cartService->getCartItemCount($customerId),
            ],
        ];
    }

    private function remove(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $wishlistId = (int)($params['wishlist_id'] ?? $params['item_id'] ?? 0);
        if ($wishlistId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Wishlist item ID is required.'),
            ];
        }

        $removed = $this->wishlistService->removeFromWishlist($wishlistId, $customerId);
        if (!$removed) {
            return [
                'success' => false,
                'message' => (string)__('Wishlist item could not be removed.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Removed from wishlist.'),
            'data' => [
                'wishlist_count' => $this->wishlistService->getCustomerWishlistCount($customerId),
            ],
        ];
    }

    private function count(): array
    {
        $customerId = $this->getCustomerId();

        return [
            'success' => true,
            'message' => (string)__('Wishlist count loaded.'),
            'data' => [
                'wishlist_count' => $customerId > 0
                    ? $this->wishlistService->getCustomerWishlistCount($customerId)
                    : 0,
            ],
        ];
    }

    private function getCustomerId(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function loginRequired(): array
    {
        return [
            'success' => false,
            'message' => (string)__('Please log in to continue.'),
            'data' => [
                'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'wishlist',
            'name' => __('Wishlist Query'),
            'description' => __('Provides frontend wishlist operations through the worker API.'),
            'module' => 'WeShop_Wishlist',
            'operations' => [
                [
                    'name' => 'add',
                    'description' => __('Frontend wishlist add operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Add product to wishlist',
                ],
                [
                    'name' => 'remove',
                    'description' => __('Frontend wishlist remove operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'wishlist_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Remove wishlist item',
                ],
                [
                    'name' => 'addFromCart',
                    'description' => __('Frontend save-for-later operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'item_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Move cart item to wishlist',
                ],
                [
                    'name' => 'count',
                    'description' => __('Frontend wishlist count operation.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Wishlist item count',
                ],
            ],
        ];
    }
}
