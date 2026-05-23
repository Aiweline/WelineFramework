<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\Framework\Http\Cookie;

/**
 * 前台购物车数量 Cookie，供 SSR 与浏览器端按需拉取购物车 API 时使用。
 */
class CartCountCookieService
{
    public const COOKIE_KEY = 'weline_cart_item_count';

    private const COOKIE_TTL = 3600 * 24 * 365;

    public function sync(int $count): void
    {
        Cookie::set(
            self::COOKIE_KEY,
            (string) max(0, $count),
            self::COOKIE_TTL,
            ['httponly' => false, 'samesite' => 'Lax']
        );
    }

    public function read(): ?int
    {
        $raw = Cookie::get(self::COOKIE_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }

        return max(0, (int) $raw);
    }

    public function hasToken(): bool
    {
        return $this->read() !== null;
    }
}
