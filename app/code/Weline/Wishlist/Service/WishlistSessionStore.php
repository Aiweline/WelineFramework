<?php

declare(strict_types=1);

namespace Weline\Wishlist\Service;

use Weline\Framework\Http\Cookie;

/**
 * Guest wishlist persisted in a signed cookie (product id list).
 */
class WishlistSessionStore
{
    public const COOKIE_NAME = 'weline_wishlist';
    public const MAX_ITEMS = 100;

    /**
     * @return list<int>
     */
    public function listIds(): array
    {
        $raw = trim((string)Cookie::get(self::COOKIE_NAME));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id) {
            $productId = (int)$id;
            if ($productId > 0) {
                $out[] = $productId;
            }
        }

        return array_values(array_unique($out));
    }

  /**
   * @param list<int> $ids
   */
    public function saveIds(array $ids): void
    {
        $normalized = [];
        foreach ($ids as $id) {
            $productId = (int)$id;
            if ($productId > 0) {
                $normalized[] = $productId;
            }
        }
        $normalized = array_values(array_unique($normalized));
        if (count($normalized) > self::MAX_ITEMS) {
            $normalized = array_slice($normalized, -self::MAX_ITEMS);
        }
        if ($normalized === []) {
            Cookie::delete(self::COOKIE_NAME);
            return;
        }
        Cookie::set(
            self::COOKIE_NAME,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            60 * 60 * 24 * 180,
            [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );
    }
}
