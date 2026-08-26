<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

use Weline\Framework\Http\Cookie;

class CompareSessionStore
{
    public const COOKIE_NAME = 'weline_compare';
    public const MAX_ITEMS = 4;

    /**
     * @return list<int>
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
            $normalized = array_slice($normalized, 0, self::MAX_ITEMS);
        }
        if ($normalized === []) {
            Cookie::delete(self::COOKIE_NAME);
            return;
        }
        Cookie::set(
            self::COOKIE_NAME,
            json_encode($normalized, JSON_UNESCAPED_UNICODE),
            60 * 60 * 24 * 30,
            [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        );
    }
}
