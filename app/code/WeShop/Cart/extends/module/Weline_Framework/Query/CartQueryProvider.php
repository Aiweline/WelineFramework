<?php
declare(strict_types=1);

namespace WeShop\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Cart\Controller\Frontend\Cart\Index as CartPageController;
use WeShop\Cart\Service\CartApiPayloadService;
use WeShop\Cart\Service\CartCountCookieService;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;

class CartQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ?CartApiPayloadService $cartApiPayloadService = null,
        private readonly ?CartIdentityService $cartIdentityService = null,
        private readonly ?CartCountCookieService $cartCountCookieService = null
    ) {
    }

    public function getProviderName(): string
    {
        return 'cart';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'renderPage' => $this->renderPage(),
            'add' => $this->add($params),
            'options' => $this->options($params),
            'miniItems' => $this->miniItems($params),
            'count' => $this->count(),
            'update' => $this->update($params),
            'remove' => $this->remove($params),
            'trash' => $this->trash($params),
            'restore' => $this->restore($params),
            'getCartItems' => $this->getCartItems($params),
            'calculateTotals' => $this->calculateTotals($params),
            'clearCart' => $this->clearCart($params),
            default => throw new \InvalidArgumentException(
                (string) __('Cart 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function renderPage(): string
    {
        /** @var CartPageController $controller */
        $controller = ObjectManager::getInstance(CartPageController::class, [], false);
        return $controller->index();
    }

    private function add(array $params): array
    {
        return $this->getCartApiPayloadService()->buildAddResponse($this->getFrontendCustomerId(), [
            'product_id' => (int)($params['product_id'] ?? 0),
            'qty' => (int)($params['qty'] ?? 1),
            'selected_options' => $params['selected_options'] ?? [],
            'selected_option_labels' => $params['selected_option_labels'] ?? [],
            'selected_option_details' => $params['selected_option_details'] ?? [],
        ]);
    }

    private function options(array $params): array
    {
        return $this->getCartApiPayloadService()->buildOptionsResponse((int)($params['product_id'] ?? 0));
    }

    private function miniItems(array $params): array
    {
        $payload = $this->getCartApiPayloadService()->buildMiniItemsResponse($this->getFrontendCustomerId());
        if (!\is_array($payload['data'] ?? null)) {
            return $payload;
        }

        $limit = (int)($params['limit'] ?? 0);
        if ($limit > 0 && \is_array($payload['data']['items'] ?? null)) {
            $payload['data']['items'] = \array_slice($payload['data']['items'], 0, $limit);
        }

        return $payload;
    }

    private function count(): array
    {
        $customerId = $this->getFrontendCustomerId(false);
        $count = $customerId > 0 ? $this->cartService->getCartItemCount($customerId) : 0;

        if ($customerId > 0) {
            $this->getCartCountCookieService()->sync($count);
        }

        return [
            'code' => 200,
            'msg' => (string)__('购物车数量加载成功。'),
            'data' => [
                'success' => true,
                'message' => (string)__('购物车数量加载成功。'),
                'count' => $count,
            ],
        ];
    }

    private function update(array $params): array
    {
        return $this->getCartApiPayloadService()->buildUpdateResponse(
            $this->getFrontendCustomerId(),
            (int)($params['item_id'] ?? $params['cart_id'] ?? 0),
            (int)($params['quantity'] ?? 1)
        );
    }

    private function remove(array $params): array
    {
        return $this->getCartApiPayloadService()->buildRemoveResponse(
            $this->getFrontendCustomerId(),
            (int)($params['item_id'] ?? $params['cart_id'] ?? 0)
        );
    }

    private function trash(array $params): array
    {
        return $this->getCartApiPayloadService()->buildTrashResponse(
            $this->getFrontendCustomerId(),
            (int)($params['limit'] ?? 6)
        );
    }

    private function restore(array $params): array
    {
        return $this->getCartApiPayloadService()->buildRestoreResponse(
            $this->getFrontendCustomerId(),
            (int)($params['item_id'] ?? $params['cart_id'] ?? 0)
        );
    }

    private function getFrontendCustomerId(bool $createGuest = true): int
    {
        return $this->getCartIdentityService()->getCartCustomerId($createGuest);
    }

    private function getCartApiPayloadService(): CartApiPayloadService
    {
        return $this->cartApiPayloadService ?? ObjectManager::getInstance(CartApiPayloadService::class);
    }

    private function getCartIdentityService(): CartIdentityService
    {
        return $this->cartIdentityService ?? ObjectManager::getInstance(CartIdentityService::class);
    }

    private function getCartCountCookieService(): CartCountCookieService
    {
        return $this->cartCountCookieService ?? ObjectManager::getInstance(CartCountCookieService::class);
    }

    private function getCartItems(array $params): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [];
        }

        return $this->cartService->getCartItems($customerId);
    }

    private function calculateTotals(array $params): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'discount' => 0,
                'total' => 0,
            ];
        }

        return $this->cartService->calculateTotals($customerId);
    }

    private function clearCart(array $params): bool
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return false;
        }

        return $this->cartService->clearCart($customerId);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'cart',
            'name' => __('购物车查询'),
            'description' => __('提供购物车读取与总额计算能力'),
            'module' => 'WeShop_Cart',
            'operations' => [
                [
                    'name' => 'renderPage',
                    'description' => __('通过 Weline_Cart 根路由渲染前台购物车页面'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [],
                    'returns' => ['type' => 'string'],
                    'summary' => 'Render cart page',
                ],
                [
                    'name' => 'add',
                    'description' => __('前台购物车加购操作'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'qty' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 999],
                        'selected_options' => ['type' => 'list', 'required' => false, 'max_items' => 20],
                        'selected_option_labels' => ['type' => 'list', 'required' => false, 'max_items' => 20],
                        'selected_option_details' => ['type' => 'list', 'required' => false, 'max_items' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Add product to cart',
                ],
                [
                    'name' => 'options',
                    'description' => __('前台商品规格读取操作'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 30,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load configurable product options',
                ],
                [
                    'name' => 'miniItems',
                    'description' => __('前台迷你购物车商品列表操作'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 0,
                    'params' => [
                        'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Mini cart items',
                ],
                [
                    'name' => 'count',
                    'description' => __('前台购物车数量读取操作'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 0,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cart item count',
                ],
                [
                    'name' => 'update',
                    'description' => __('前台购物车更新操作'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'item_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'quantity' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 999],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Update cart item quantity',
                ],
                [
                    'name' => 'remove',
                    'description' => __('前台购物车移除操作（移至回收站）'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'item_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Move cart item to trash',
                ],
                [
                    'name' => 'trash',
                    'description' => __('前台购物车回收站读取操作'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [
                        'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cart trash items',
                ],
                [
                    'name' => 'restore',
                    'description' => __('前台购物车回收站恢复操作'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'item_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Restore cart item from trash',
                ],
                [
                    'name' => 'getCartItems',
                    'description' => __('获取客户购物车商品列表'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'calculateTotals',
                    'description' => __('计算客户购物车总额'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'clearCart',
                    'description' => __('清空客户购物车'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderMiniItemsHtml(array $data): string
    {
        $items = $data['items'] ?? [];
        if (!\is_array($items) || $items === []) {
            return $this->renderEmptyCartHtml();
        }

        $html = '';
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $cartId = (int)($item['cart_id'] ?? $item['item_id'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            $name = $this->escapeHtml((string)($item['name'] ?? ''));
            $url = $this->escapeHtml((string)($item['url'] ?? '#'));
            $priceFormatted = $this->escapeHtml((string)($item['price_formatted'] ?? ''));
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $options = trim((string)($item['options'] ?? ''));
            $image = trim((string)($item['image'] ?? ''));

            $imageHtml = $image !== ''
                ? '<img src="' . $this->escapeHtml($image) . '" alt="' . $name . '" loading="lazy"/>'
                : '<div class="mini-cart-item__placeholder"><span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('image') . '</span></div>';
            $optionsHtml = $options !== ''
                ? '<div class="mini-cart-item__options">' . $this->escapeHtml($options) . '</div>'
                : '';
            $cartIdAttr = $this->escapeHtml((string)$cartId);
            $productIdAttr = $this->escapeHtml((string)$productId);
            $quantityText = $this->escapeHtml((string)$quantity);

            $html .= '<div class="mini-cart-item" data-item-id="' . $cartIdAttr . '" data-product-id="' . $productIdAttr . '">'
                . '<div class="mini-cart-item__image">' . $imageHtml . '</div>'
                . '<div class="mini-cart-item__details">'
                . '<a href="' . $url . '" class="mini-cart-item__name">' . $name . '</a>'
                . $optionsHtml
                . '<div class="mini-cart-item__price">' . $priceFormatted . '</div>'
                . '<div class="mini-cart-item__qty">'
                . '<button type="button" class="mini-cart-item__qty-btn" data-action="decrease-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('减少数量')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('minus') . '</span>'
                . '</button>'
                . '<span class="mini-cart-item__qty-value">' . $quantityText . '</span>'
                . '<button type="button" class="mini-cart-item__qty-btn" data-action="increase-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('增加数量')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('plus') . '</span>'
                . '</button>'
                . '</div>'
                . '</div>'
                . '<button type="button" class="mini-cart-item__remove" data-action="remove-item" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('删除商品')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('trash') . '</span>'
                . '</button>'
                . '</div>';
        }

        return $html !== '' ? $html : $this->renderEmptyCartHtml();
    }

    private function renderEmptyCartHtml(): string
    {
        return '<div class="mini-cart-empty" id="mini-cart-empty">'
            . '<div class="empty-state">'
            . '<span class="mini-cart-empty__icon mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('cart') . '</span>'
            . '<p class="empty-message">' . $this->escapeHtml((string)__('购物车是空的')) . '</p>'
            . '<a href="/" class="start-shopping-link" data-action="close-mini-cart">' . $this->escapeHtml((string)__('开始购物')) . '</a>'
            . '</div>'
            . '</div>';
    }

    private function renderMiniCartIconHtml(string $name): string
    {
        return match ($name) {
            'minus' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 11a1 1 0 0 0 0 2h12a1 1 0 1 0 0-2H6Z" fill="currentColor"/></svg>',
            'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M11 6a1 1 0 1 1 2 0v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H6a1 1 0 0 1 0-2h5V6Z" fill="currentColor"/></svg>',
            'trash' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3a1 1 0 0 0-.8.4L7.4 4H5a1 1 0 1 0 0 2h.5l1 11.1A2 2 0 0 0 8.5 19h7a2 2 0 0 0 2-1.9l1-11.1H19a1 1 0 1 0 0-2h-2.4l-.8-.6A1 1 0 0 0 15 3H9Zm-.5 3h7l-.9 10.9H9.4L8.5 6Zm1.5 2a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Zm4 0a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Z" fill="currentColor"/></svg>',
            'image' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 5a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6Zm0 2h12a1 1 0 0 1 1 1v5.4l-2.8-2.8a1 1 0 0 0-1.4 0L10 15.4l-1.8-1.8a1 1 0 0 0-1.4 0L5 15.4V8a1 1 0 0 1 1-1Zm0 10 1.5-1.5 1.8 1.8a1 1 0 0 0 1.4 0l4.8-4.8 3.5 3.5a1 1 0 0 1-1 1H6Zm9-7a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" fill="currentColor"/></svg>',
            'cart' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4a1 1 0 0 1 1-1h1.3a2 2 0 0 1 2 1.6L7.5 6H20a1 1 0 0 1 1 .8 1 1 0 0 1-.1.7l-2.4 5.8A2 2 0 0 1 16.6 15H9a2 2 0 0 1-2-1.6L5.1 5H4a1 1 0 0 1-1-1Zm5 4 .9 4.2H16.6L18.2 8H8ZM9 20a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm8 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z" fill="currentColor"/></svg>',
            default => '',
        };
    }

    private function escapeHtml(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
