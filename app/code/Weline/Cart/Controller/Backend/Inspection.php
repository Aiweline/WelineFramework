<?php

declare(strict_types=1);

namespace Weline\Cart\Controller\Backend;

use Weline\Cart\Api\CartStoreInterface;
use Weline\Cart\Service\CommerceCartTypeRegistry;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Cart::cart_workspace', '购物车工作台', 'search', '购物车检查', 'Weline_Backend::order_group')]
final class Inspection extends BackendController
{
    #[Acl('Weline_Cart::cart_inspection', '购物车检查', 'search', '按 Scope 检查真实 Cart 持久库')]
    public function index(): string
    {
        $scopeKey = trim((string)$this->request->getParam('scope_key', ''));
        /** @var CommerceCartTypeRegistry $typeRegistry */
        $typeRegistry = ObjectManager::getInstance(CommerceCartTypeRegistry::class);
        $typeRows = $typeRegistry->labelRows();
        $registeredCodes = $typeRegistry->codes();
        $cartTypeFilter = strtolower(trim((string)$this->request->getParam('cart_type', '')));
        if ($cartTypeFilter !== '' && !in_array($cartTypeFilter, $registeredCodes, true)) {
            $cartTypeFilter = '';
        }

        $carts = [];
        $loadError = '';

        if ($scopeKey !== '') {
            try {
                /** @var CartStoreInterface $store */
                $store = ObjectManager::getInstance(CartStoreInterface::class);
                foreach ($store->listByScopeKey($scopeKey) as $cart) {
                    $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
                    $cartType = strtolower(trim((string)($cart['cart_type'] ?? '')));
                    if ($cartType === '') {
                        $cartType = CommerceCartTypeRegistry::CODE_TOC;
                    }
                    if ($cartTypeFilter !== '' && $cartType !== $cartTypeFilter) {
                        continue;
                    }
                    $carts[] = [
                        'scope_key' => (string)($cart['scope_key'] ?? ''),
                        'currency' => (string)($cart['currency'] ?? ''),
                        'owner_kind' => (string)($cart['owner_kind'] ?? ''),
                        'owner_id' => $this->maskIdentity((string)($cart['owner_id'] ?? '')),
                        'item_count' => count($items),
                        'cart_type' => $cartType,
                        'cart_type_label' => $typeRegistry->resolveLabel($cartType),
                        'cart_type_tone' => $typeRegistry->resolveBadgeTone($cartType),
                    ];
                }
            } catch (\Throwable $exception) {
                $loadError = $exception->getMessage();
            }
        }

        $this->assign('scope_key', $scopeKey);
        $this->assign('cart_type', $cartTypeFilter);
        $this->assign('cart_type_rows', $typeRows);
        $this->assign('carts', $carts);
        $this->assign('load_error', $loadError);
        $this->assign('title', __('购物车检查'));

        return $this->fetch();
    }

    private function maskIdentity(string $identity): string
    {
        $length = strlen($identity);
        if ($length <= 4) {
            return $identity === '' ? '' : str_repeat('*', $length);
        }

        return substr($identity, 0, 2) . '***' . substr($identity, -2);
    }
}
