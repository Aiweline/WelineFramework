<?php

declare(strict_types=1);

namespace Weline\Cart\Controller\Backend;

use Weline\Cart\Service\CartV2CacheStore;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Cart::cart_workspace', '购物车工作台', 'mdi-cart-search', '购物车检查', 'Weline_Backend::order_group')]
final class Inspection extends BackendController
{
    #[Acl('Weline_Cart::cart_inspection', '购物车检查', 'mdi-cart-search', '按 Scope 检查真实 Cart V2 持久缓存')]
    public function index(): string
    {
        $scopeKey = trim((string)$this->request->getParam('scope_key', ''));
        $carts = [];
        $loadError = '';

        if ($scopeKey !== '') {
            try {
                /** @var CartV2CacheStore $store */
                $store = ObjectManager::getInstance(CartV2CacheStore::class);
                foreach ($store->listByScopeKey($scopeKey) as $cart) {
                    $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
                    $carts[] = [
                        'scope_key' => (string)($cart['scope_key'] ?? ''),
                        'currency' => (string)($cart['currency'] ?? ''),
                        'owner_kind' => (string)($cart['owner_kind'] ?? ''),
                        'owner_id' => $this->maskIdentity((string)($cart['owner_id'] ?? '')),
                        'item_count' => count($items),
                    ];
                }
            } catch (\Throwable $exception) {
                $loadError = $exception->getMessage();
            }
        }

        $this->assign('scope_key', $scopeKey);
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
