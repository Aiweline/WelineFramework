<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartStoreInterface;
use Weline\Cart\Model\Cart;

/**
 * 权威购物车存储：表 weline_cart（跨 Worker / 跨进程，非 cache/file）。
 *
 * - guest：expires_at = now + 15 天（读时过期删除）
 * - customer：expires_at = NULL（永久）
 */
final class CartDbStore implements CartStoreInterface
{
    public const ERROR_PERSIST = 'cart_persist_failed';

    public function __construct(
        private readonly Cart $model = new Cart(),
    ) {
    }

    public function get(string $cartKey): ?array
    {
        $key = trim($cartKey);
        if ($key === '') {
            return null;
        }
        $row = $this->findModel($key);
        if (!$row->getId()) {
            return null;
        }
        if ($this->isExpired($row)) {
            $this->delete($key);

            return null;
        }

        return $this->decodePayload($row);
    }

    public function set(string $cartKey, array $cart): void
    {
        $key = trim($cartKey);
        if ($key === '') {
            throw new CartConflictException(
                self::ERROR_PERSIST,
                (string)__('购物车保存失败，请重试'),
            );
        }
        $json = json_encode($cart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new CartConflictException(
                self::ERROR_PERSIST,
                (string)__('购物车保存失败，请重试'),
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = $this->findModel($key);
        if (!$row->getId()) {
            $row->setData(Cart::schema_fields_CREATED_AT, $now);
        }

        $ownerKind = strtolower(trim((string)($cart['owner_kind'] ?? '')));
        if ($ownerKind === '') {
            $ownerKind = str_contains($key, '|customer:')
                ? CartService::OWNER_CUSTOMER
                : CartService::OWNER_GUEST;
        }
        $ownerId = (string)($cart['owner_id'] ?? '');
        if ($ownerId === '') {
            $ownerId = $this->ownerIdFromKey($key);
        }
        $guestToken = $cart['guest_token'] ?? null;
        if ($ownerKind === CartService::OWNER_GUEST && !is_string($guestToken)) {
            $guestToken = $ownerId !== '' ? $ownerId : null;
        }
        if ($ownerKind !== CartService::OWNER_GUEST) {
            $guestToken = null;
        }

        try {
            $row->setData([
                Cart::schema_fields_CART_KEY => $key,
                Cart::schema_fields_SCOPE_KEY => (string)($cart['scope_key'] ?? ''),
                Cart::schema_fields_OWNER_KIND => $ownerKind,
                Cart::schema_fields_OWNER_ID => $ownerId,
                Cart::schema_fields_GUEST_TOKEN => is_string($guestToken) ? $guestToken : null,
                Cart::schema_fields_CART_TYPE => strtolower(trim((string)($cart['cart_type'] ?? 'toc'))) ?: 'toc',
                Cart::schema_fields_CURRENCY => (string)($cart['currency'] ?? 'CNY'),
                Cart::schema_fields_PAYLOAD_JSON => $json,
                Cart::schema_fields_EXPIRES_AT => CartPersistencePolicy::expiresAtForCart($cart, $key),
                Cart::schema_fields_UPDATED_AT => $now,
            ])->save();
        } catch (\Throwable $e) {
            throw new CartConflictException(
                self::ERROR_PERSIST,
                (string)__('购物车保存失败，请重试'),
                [],
                $e,
            );
        }
    }

    public function delete(string $cartKey): void
    {
        $key = trim($cartKey);
        if ($key === '') {
            return;
        }
        $row = $this->findModel($key);
        $id = (int)$row->getId();
        if ($id <= 0) {
            return;
        }
        $deleter = clone $this->model;
        $deleter->clear()
            ->where(Cart::schema_fields_ID, $id)
            ->delete();
    }

    public function touch(string $cartKey): bool
    {
        $cart = $this->get($cartKey);
        if (!is_array($cart)) {
            return false;
        }
        $this->set($cartKey, $cart);

        return true;
    }

    public function listByScopeKey(string $scopeKey): array
    {
        $scope = trim($scopeKey);
        if ($scope === '') {
            return [];
        }
        $query = clone $this->model;
        $query->clear()
            ->where(Cart::schema_fields_SCOPE_KEY, $scope)
            ->select()
            ->fetch();
        $rows = $query->getItems();
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!$row instanceof Cart) {
                continue;
            }
            $key = (string)$row->getData(Cart::schema_fields_CART_KEY);
            if ($this->isExpired($row)) {
                if ($key !== '') {
                    $this->delete($key);
                }
                continue;
            }
            $decoded = $this->decodePayload($row);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function findModel(string $cartKey): Cart
    {
        $model = clone $this->model;
        $model->clear();
        $model->where(Cart::schema_fields_CART_KEY, $cartKey);
        $hit = $model->find()->fetch();

        return $hit instanceof Cart ? $hit : $model;
    }

    private function isExpired(Cart $row): bool
    {
        $expires = $row->getData(Cart::schema_fields_EXPIRES_AT);
        if ($expires === null || $expires === '') {
            return false;
        }
        $ts = strtotime((string)$expires . ' UTC');

        return $ts !== false && $ts < time();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(Cart $row): ?array
    {
        $raw = (string)$row->getData(Cart::schema_fields_PAYLOAD_JSON);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function ownerIdFromKey(string $cartKey): string
    {
        if (preg_match('/\|(?:guest|customer):([^|]+)/', $cartKey, $m) === 1) {
            return (string)$m[1];
        }

        return '';
    }
}
