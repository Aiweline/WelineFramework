<?php
declare(strict_types=1);

namespace Weline\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CartV2ConflictException;
use Weline\Cart\Service\CartV2Service;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CartQueryProvider implements QueryProviderInterface
{
    private readonly CartScopeResolver $scopeResolver;

    private readonly CartCurrentCustomerResolver $currentCustomer;

    public function __construct(
        private readonly CartService $cartService,
        ?CartScopeResolver $scopeResolver = null,
        ?CartCurrentCustomerResolver $currentCustomer = null,
    ) {
        $this->scopeResolver = $scopeResolver ?? new CartScopeResolver();
        $this->currentCustomer = $currentCustomer ?? new CartCurrentCustomerResolver();
    }

    public function getProviderName(): string
    {
        return 'cart';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'summary' => $this->success('Cart summary loaded.', $this->storefrontSummaryPayload()),
            'count' => $this->success('Cart count loaded.', $this->cartCountPayload()),
            'items', 'miniItems' => $this->success('Cart items loaded.', $this->cartItemsPayload($params)),
            'add' => $this->successFromSummary($this->cartService->add(
                $this->withTrustedCustomer($params),
            )),
            'addV2' => $this->successFromSummary($this->cartService->add(
                $this->withTrustedCustomer(
                    $params + ['provider_code' => $params['provider_code'] ?? 'product'],
                ),
            )),
            'mergeGuest' => $this->mergeGuest($params),
            'getV2Cart' => $this->getV2Cart($params),
            'updateV2' => $this->updateV2($params),
            'removeV2' => $this->removeV2($params),
            'clearV2' => $this->clearV2($params),
            'issueGuestToken' => $this->issueGuestToken(),
            'update' => $this->successFromSummary($this->cartService->update($params)),
            'remove' => $this->successFromSummary($this->cartService->remove($params)),
            'clear' => $this->successFromSummary($this->cartService->clear()),
            'options' => $this->success('Cart options loaded.', ['options' => []]),
            default => throw new \InvalidArgumentException((string)__('Cart 查询器不支持的 operation：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mergeGuest(array $params): array
    {
        $v2 = $this->cartService->cartV2();
        if ($v2 === null) {
            return $this->success('Cart V2 unavailable.', ['success' => false, 'message' => (string)__('Cart V2 未启用')]);
        }
        try {
            $customerId = $this->currentCustomer->currentCustomerId();
            if ($customerId === null) {
                return [
                    'success' => false,
                    'message' => (string)__('登录后才能合并游客购物车。'),
                    'error_code' => CartCurrentCustomerResolver::ERROR_AUTH_REQUIRED,
                ];
            }
            $scope = $this->scopeResolver->fromParams($params);
            $guestToken = trim((string)($params['guest_token'] ?? ''));
            if ($guestToken === '') {
                $guestToken = trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE));
            }
            $summary = $v2->mergeGuestIntoCustomer(
                $scope,
                $guestToken,
                $customerId,
            );
            return $this->successFromSummary($summary);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e instanceof CartV2ConflictException ? $e->errorCode() : 'cart_merge_failed',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function issueGuestToken(): array
    {
        $v2 = $this->cartService->cartV2();
        $token = $v2?->issueGuestToken() ?? bin2hex(random_bytes(16));
        Cookie::set(
            CartV2Service::GUEST_TOKEN_COOKIE,
            $token,
            3600 * 24 * 7,
            [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );
        return $this->success('Guest token issued.', ['guest_token' => $token, 'success' => true]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getV2Cart(array $params): array
    {
        $v2 = $this->cartService->cartV2();
        if ($v2 === null) {
            return $this->success('Cart V2 unavailable.', ['success' => false, 'message' => (string)__('Cart V2 未启用')]);
        }
        try {
            $scope = $this->scopeResolver->fromParams($params);
            $guestToken = isset($params['guest_token']) ? (string)$params['guest_token'] : null;
            $customerId = $this->currentCustomer->currentCustomerId();
            if ($customerId === null && ($guestToken === null || trim($guestToken) === '')) {
                $guestToken = (string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE);
            }
            $summary = $v2->getCart($scope, $guestToken, $customerId);
            return $this->successFromSummary($summary);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e instanceof CartV2ConflictException ? $e->errorCode() : 'cart_get_failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function updateV2(array $params): array
    {
        return $this->mutateV2($params, false);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function removeV2(array $params): array
    {
        return $this->mutateV2($params, true);
    }

    /**
     * Resolve owner identity on the server; browser input can never choose a customer cart.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mutateV2(array $params, bool $remove): array
    {
        $v2 = $this->cartService->cartV2();
        if ($v2 === null) {
            return [
                'success' => false,
                'message' => (string)__('Cart V2 未启用'),
                'error_code' => 'cart_v2_unavailable',
            ];
        }

        try {
            $scope = $this->scopeResolver->fromParams($params);
            $customerId = $this->currentCustomer->currentCustomerId();
            $guestToken = null;
            if ($customerId === null) {
                $guestToken = trim((string)($params['guest_token'] ?? ''));
                if ($guestToken === '') {
                    $guestToken = trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE));
                }
            }
            $itemId = trim((string)($params['item_id'] ?? ''));
            $summary = $remove
                ? $v2->removeItem($scope, $itemId, $guestToken, $customerId)
                : $v2->updateItem(
                    $scope,
                    $itemId,
                    max(1, min(999, (int)($params['qty'] ?? 1))),
                    $guestToken,
                    $customerId,
                );

            return $this->successFromSummary($summary);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e instanceof CartV2ConflictException
                    ? $e->errorCode()
                    : ($remove ? 'cart_v2_remove_failed' : 'cart_v2_update_failed'),
            ];
        }
    }

    /**
     * Clear only the server-owned current Cart V2 for the trusted identity.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function clearV2(array $params): array
    {
        $v2 = $this->cartService->cartV2();
        if ($v2 === null) {
            return [
                'success' => false,
                'message' => (string)__('Cart V2 未启用'),
                'error_code' => 'cart_v2_unavailable',
            ];
        }
        try {
            $scope = $this->scopeResolver->fromParams($params);
            $customerId = $this->currentCustomer->currentCustomerId();
            $guestToken = $customerId === null
                ? trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE))
                : null;

            return $this->successFromSummary(
                $v2->clearCart($scope, $guestToken, $customerId),
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e instanceof CartV2ConflictException
                    ? $e->errorCode()
                    : 'cart_v2_clear_failed',
            ];
        }
    }

    /**
     * Browser input can select a guest token, but never a customer cart owner.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withTrustedCustomer(array $params): array
    {
        unset($params['customer_id']);
        $customerId = $this->currentCustomer->currentCustomerId();
        if ($customerId !== null) {
            $params['customer_id'] = $customerId;
        }
        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function storefrontSummaryPayload(): array
    {
        $summary = $this->cartService->storefrontSummary();
        $summary['success'] = (bool)($summary['success'] ?? true);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartCountPayload(): array
    {
        $summary = $this->storefrontSummaryPayload();

        return [
            'success' => true,
            'cart_count' => (int)($summary['cart_count'] ?? 0),
            'item_count' => (int)($summary['item_count'] ?? 0),
            'distinct_count' => (int)($summary['distinct_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartItemsPayload(array $params): array
    {
        $summary = $this->storefrontSummaryPayload();
        $items = \is_array($summary['items'] ?? null) ? $summary['items'] : [];
        $limit = \max(1, \min(50, (int)($params['limit'] ?? $params['max_items'] ?? 5)));

        return [
            'success' => true,
            'items' => \array_slice($items, 0, $limit),
        ] + $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function successFromSummary(array $summary): array
    {
        return [
            'success' => (bool)($summary['success'] ?? false),
            'message' => (string)($summary['message'] ?? ''),
            'data' => $summary,
        ] + $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function success(string $message, array $data): array
    {
        $data['success'] = $data['success'] ?? true;

        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ] + $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDescriptor(): array
    {
        $commonReturns = ['type' => 'array'];

        return [
            'provider' => 'cart',
            'name' => __('Frontend cart API'),
            'description' => __('Storefront cart session operations exposed through Weline.Api.'),
            'module' => 'Weline_Cart',
            'operations' => [
                [
                    'name' => 'summary',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart summary',
                ],
                [
                    'name' => 'count',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart item count',
                ],
                [
                    'name' => 'items',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
                        'max_items' => ['type' => 'int', 'min' => 1, 'max' => 50],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart items',
                ],
                [
                    'name' => 'miniItems',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
                        'max_items' => ['type' => 'int', 'min' => 1, 'max' => 50],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read mini cart items',
                ],
                [
                    'name' => 'add',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'qty' => ['type' => 'int', 'min' => 1, 'max' => 999],
                        'selected_options' => ['type' => 'array', 'max_items' => 50],
                        'options' => ['type' => 'array', 'max_items' => 50],
                        'name' => ['type' => 'string', 'max_length' => 160],
                        'sku' => ['type' => 'string', 'max_length' => 80],
                        'image' => ['type' => 'string', 'max_length' => 512],
                        'price' => ['type' => 'number', 'min' => 0],
                        'provider_code' => ['type' => 'string', 'max_length' => 64],
                        'global_offer_uuid' => ['type' => 'string', 'max_length' => 64],
                        'offer_uuid' => ['type' => 'string', 'max_length' => 64],
                        'offer_id' => ['type' => 'int', 'min' => 1],
                        'website_id' => ['type' => 'int', 'min' => 0],
                        'store_id' => ['type' => 'int', 'min' => 0],
                        'currency' => ['type' => 'string', 'max_length' => 8],
                        'selection_hash' => ['type' => 'string', 'max_length' => 128],
                        'guest_token' => ['type' => 'string', 'max_length' => 64],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Add item to cart session (V1 product_id or V2 OfferIdentity)',
                ],
                [
                    'name' => 'addV2',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'provider_code' => ['type' => 'string', 'max_length' => 64],
                        'global_offer_uuid' => ['type' => 'string', 'max_length' => 64],
                        'qty' => ['type' => 'int', 'min' => 1, 'max' => 999],
                        'selection' => ['type' => 'array', 'max_items' => 50],
                        'selection_hash' => ['type' => 'string', 'max_length' => 128],
                        'guest_token' => ['type' => 'string', 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'min' => 0],
                        'website_code' => ['type' => 'string', 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'max_length' => 32],
                        'scope' => ['type' => 'array', 'max_items' => 7],
                        'currency' => ['type' => 'string', 'max_length' => 8],
                        'legacy_product_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Add Cart V2 OfferIdentity line',
                ],
                [
                    'name' => 'mergeGuest',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'guest_token' => ['type' => 'string', 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'min' => 0],
                        'website_code' => ['type' => 'string', 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'max_length' => 32],
                        'scope' => ['type' => 'array', 'max_items' => 7],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Merge guest cart into the authenticated customer cart (same Scope)',
                ],
                [
                    'name' => 'getV2Cart',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'guest_token' => ['type' => 'string', 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'min' => 0],
                        'website_code' => ['type' => 'string', 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'max_length' => 32],
                        'scope' => ['type' => 'array', 'max_items' => 7],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read the current guest or authenticated customer Cart V2 cart',
                ],
                [
                    'name' => 'updateV2',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => $this->v2MutationParams(includeQty: true),
                    'returns' => $commonReturns,
                    'summary' => 'Update an item in the current trusted Cart V2',
                ],
                [
                    'name' => 'removeV2',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => $this->v2MutationParams(),
                    'returns' => $commonReturns,
                    'summary' => 'Remove an item from the current trusted Cart V2',
                ],
                [
                    'name' => 'issueGuestToken',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Issue opaque guest cart token',
                ],
                [
                    'name' => 'clearV2',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'website_id' => ['type' => 'int', 'min' => 0],
                        'website_code' => ['type' => 'string', 'max_length' => 64],
                        'store_code' => ['type' => 'string', 'max_length' => 64],
                        'channel_code' => ['type' => 'string', 'max_length' => 64],
                        'store_mode' => ['type' => 'string', 'max_length' => 32],
                        'scope' => ['type' => 'array', 'max_items' => 7],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Clear the current trusted Cart V2',
                ],
                [
                    'name' => 'update',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'item_id' => ['type' => 'string', 'max_length' => 64],
                        'cart_item_id' => ['type' => 'string', 'max_length' => 64],
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'qty' => ['type' => 'int', 'min' => 0, 'max' => 999],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Update cart item quantity',
                ],
                [
                    'name' => 'remove',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'item_id' => ['type' => 'string', 'max_length' => 64],
                        'cart_item_id' => ['type' => 'string', 'max_length' => 64],
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Remove cart item',
                ],
                [
                    'name' => 'clear',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Clear cart session',
                ],
                [
                    'name' => 'options',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'product_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart option metadata',
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function v2MutationParams(bool $includeQty = false): array
    {
        $params = [
            'item_id' => ['type' => 'string', 'max_length' => 64],
            'guest_token' => ['type' => 'string', 'max_length' => 64],
            'website_id' => ['type' => 'int', 'min' => 0],
            'website_code' => ['type' => 'string', 'max_length' => 64],
            'store_code' => ['type' => 'string', 'max_length' => 64],
            'channel_code' => ['type' => 'string', 'max_length' => 64],
            'store_mode' => ['type' => 'string', 'max_length' => 32],
            'scope' => ['type' => 'array', 'max_items' => 7],
        ];
        if ($includeQty) {
            $params['qty'] = ['type' => 'int', 'min' => 1, 'max' => 999];
        }
        return $params;
    }
}
