<?php
declare(strict_types=1);

namespace WeShop\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Cart\Controller\Frontend\Cart\Index as CartPageController;
use WeShop\Cart\Service\CartApiPayloadService;
use WeShop\Cart\Service\CartIdentityService;
use WeShop\Cart\Service\CartService;

class CartQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ?CartApiPayloadService $cartApiPayloadService = null,
        private readonly ?CartIdentityService $cartIdentityService = null
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

        $payload['data']['html'] = $this->renderMiniItemsHtml($payload['data']);
        return $payload;
    }

    private function count(): array
    {
        $customerId = $this->getFrontendCustomerId(false);
        $count = $customerId > 0 ? $this->cartService->getCartItemCount($customerId) : 0;

        return [
            'code' => 200,
            'msg' => (string)__('Cart count loaded successfully.'),
            'data' => [
                'success' => true,
                'message' => (string)__('Cart count loaded successfully.'),
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
                    'description' => __('Render frontend cart page through Weline_Cart root route'),
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
                    'description' => __('Frontend cart add operation'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                        'qty' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 999],
                        'selected_options' => ['type' => 'list', 'required' => false, 'max_items' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Add product to cart',
                ],
                [
                    'name' => 'options',
                    'description' => __('Frontend product options operation'),
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
                    'description' => __('Frontend mini cart items operation'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [
                        'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Mini cart items',
                ],
                [
                    'name' => 'count',
                    'description' => __('Frontend cart count operation'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cart item count',
                ],
                [
                    'name' => 'update',
                    'description' => __('Frontend cart update operation'),
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
                    'description' => __('Frontend cart remove operation, moves item to cart trash'),
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
                    'description' => __('Frontend cart trash operation'),
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
                    'description' => __('Frontend cart trash restore operation'),
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
                : '<div class="mini-cart-item__placeholder"><span class="material-symbols-outlined">image</span></div>';
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
                . '<button type="button" class="qty-btn" data-action="decrease-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('Decrease quantity')) . '">'
                . '<span class="material-symbols-outlined">remove</span>'
                . '</button>'
                . '<span class="qty-value">' . $quantityText . '</span>'
                . '<button type="button" class="qty-btn" data-action="increase-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('Increase quantity')) . '">'
                . '<span class="material-symbols-outlined">add</span>'
                . '</button>'
                . '</div>'
                . '</div>'
                . '<button type="button" class="mini-cart-item__remove" data-action="remove-item" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string)__('Remove item')) . '">'
                . '<span class="material-symbols-outlined">delete_outline</span>'
                . '</button>'
                . '</div>';
        }

        return $html !== '' ? $html : $this->renderEmptyCartHtml();
    }

    private function renderEmptyCartHtml(): string
    {
        return '<div class="mini-cart-empty" id="mini-cart-empty">'
            . '<div class="empty-state">'
            . '<span class="material-symbols-outlined empty-icon">shopping_cart</span>'
            . '<p class="empty-message">' . $this->escapeHtml((string)__('购物车是空的')) . '</p>'
            . '<a href="/" class="start-shopping-link" data-action="close-mini-cart">' . $this->escapeHtml((string)__('开始购物')) . '</a>'
            . '</div>'
            . '</div>';
    }

    private function escapeHtml(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
