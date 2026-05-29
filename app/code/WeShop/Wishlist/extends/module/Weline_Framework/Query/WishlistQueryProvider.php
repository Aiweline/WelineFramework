<?php
declare(strict_types=1);

namespace WeShop\Wishlist\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Cart\Service\CartService;
use WeShop\Wishlist\Service\WishlistService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class WishlistQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly WishlistService $wishlistService,
        private readonly CartService $cartService,
        private readonly Url $url,
        private readonly ?CustomerSession $customerSession = null
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
                (string)__('心愿单查询器不支持的操作：%{1}', $operation)
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
                'message' => (string)__('缺少商品 ID。'),
            ];
        }

        $item = $this->wishlistService->addToWishlist($customerId, $productId);

        return [
            'success' => true,
            'message' => (string)__('已加入心愿单。'),
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
                'message' => (string)__('缺少购物车条目 ID。'),
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
                'message' => (string)__('未找到对应购物车条目。'),
            ];
        }

        $item = $this->wishlistService->addToWishlist($customerId, $productId);
        $this->cartService->removeFromCart($cartItemId, $customerId);

        return [
            'success' => true,
            'message' => (string)__('已移至稍后购买。'),
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
                'message' => (string)__('缺少心愿单条目 ID。'),
            ];
        }

        $removed = $this->wishlistService->removeFromWishlist($wishlistId, $customerId);
        if (!$removed) {
            return [
                'success' => false,
                'message' => (string)__('无法移除该心愿单条目。'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('已从心愿单移除。'),
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
            'message' => (string)__('心愿单数量已加载。'),
            'data' => [
                'wishlist_count' => $customerId > 0
                    ? $this->wishlistService->getCustomerWishlistCount($customerId)
                    : 0,
            ],
        ];
    }

    private function getCustomerId(): int
    {
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId > 0) {
            return $customerId;
        }

        try {
            return (int)($this->getCustomerSession()->getUserId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getCustomerSession(): CustomerSession
    {
        return $this->customerSession ?? ObjectManager::getInstance(CustomerSession::class);
    }

    private function loginRequired(): array
    {
        return [
            'success' => false,
            'message' => (string)__('请先登录。'),
            'data' => [
                'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'wishlist',
            'name' => __('心愿单查询'),
            'description' => __('通过 Worker API 提供前台心愿单操作。'),
            'module' => 'WeShop_Wishlist',
            'operations' => [
                [
                    'name' => 'add',
                    'description' => __('前台心愿单添加操作。'),
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
                    'description' => __('前台心愿单移除操作。'),
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
                    'description' => __('前台稍后购买（从购物车移入）操作。'),
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
                    'description' => __('前台心愿单数量查询操作。'),
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
