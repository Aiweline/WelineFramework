<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Service;

use Weline\Framework\Http\Cookie;

/**
 * Guest/logged-in recently-viewed product ids (MRU) persisted in a scoped cookie.
 */
class RecentlyViewedSessionStore
{
    public const COOKIE_NAME = 'weline_recently_viewed';
    public const MAX_ITEMS = 24;

    /**
     * @return list<int> Newest first.
     */
    public function listIds(): array
    {
        $raw = trim($this->readRawCookieValue());
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

    public function record(int $productId): void
    {
        $productId = max(0, $productId);
        if ($productId <= 0) {
            return;
        }
        $ids = array_values(array_filter(
            $this->listIds(),
            static fn(int $id): bool => $id !== $productId,
        ));
        array_unshift($ids, $productId);
        $this->saveIds($ids);
    }

    /**
     * @param list<int> $ids Newest first.
     */
    public function saveIds(array $ids): void
    {
        $normalized = [];
        foreach ($ids as $id) {
            $productId = (int)$id;
            if ($productId > 0 && !in_array($productId, $normalized, true)) {
                $normalized[] = $productId;
            }
        }
        if (count($normalized) > self::MAX_ITEMS) {
            $normalized = array_slice($normalized, 0, self::MAX_ITEMS);
        }
        if ($normalized === []) {
            Cookie::delete(self::COOKIE_NAME);

            return;
        }
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return;
        }
        // Skip no-op rewrites: identical Set-Cookie still blocks FPC publish.
        if ($encoded === $this->readRawCookieValue()) {
            return;
        }
        // httponly=false: PDP FPC HIT never runs SSR observers; client JS
        // updates the MRU from data-product-id without a document Set-Cookie.
        Cookie::set(
            self::COOKIE_NAME,
            $encoded,
            60 * 60 * 24 * 180,
            [
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax',
            ],
        );
    }

    private function readRawCookieValue(): string
    {
        try {
            $raw = trim((string)Cookie::get(self::COOKIE_NAME));
            if ($raw !== '') {
                return $raw;
            }
        } catch (\Throwable) {
        }

        return $this->readRawCookieFromSuperglobal();
    }

    private function readRawCookieFromSuperglobal(): string
    {
        $cookies = is_array($_COOKIE ?? null) ? $_COOKIE : [];
        $prefix = self::COOKIE_NAME;
        foreach ($cookies as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }
            if ($name !== $prefix && !str_starts_with($name, $prefix . '_')) {
                continue;
            }
            $candidate = trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
