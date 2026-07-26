<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 页面类型分类器（与巡检 Diff 共用）。
 */
class PixelPageTypeClassifier
{
    public const TYPE_HOME = 'home';
    public const TYPE_CONTENT = 'content';
    public const TYPE_CHECKOUT = 'checkout';
    public const TYPE_ACCOUNT = 'account';
    public const TYPE_UNKNOWN = 'page_type_unknown';

    /**
     * @param array<string, mixed> $pageMeta keys: page_type|type, handle, url|path, is_home, status
     */
    public function classify(array $pageMeta): string
    {
        if (!empty($pageMeta['is_home'])) {
            return self::TYPE_HOME;
        }

        $type = \strtolower(\trim((string)($pageMeta['page_type'] ?? $pageMeta['type'] ?? '')));
        $handle = \strtolower(\trim((string)($pageMeta['handle'] ?? '')));
        $url = \strtolower(\trim((string)($pageMeta['url'] ?? $pageMeta['path'] ?? '')));
        $haystack = $type . ' ' . $handle . ' ' . $url;

        if ($this->containsAny($haystack, ['checkout', 'cart', 'order', 'payment', 'pay'])) {
            return self::TYPE_CHECKOUT;
        }
        if ($this->containsAny($haystack, ['account', 'login', 'register', 'signup', 'sign-up', 'user/profile', 'member'])) {
            return self::TYPE_ACCOUNT;
        }

        if (
            $type === 'home'
            || $type === 'home_page'
            || $handle === 'home'
            || $handle === 'index'
            || $url === '/'
            || \preg_match('#^https?://[^/]+/?$#i', $url) === 1
        ) {
            return self::TYPE_HOME;
        }

        if ($type !== '' || $handle !== '' || $url !== '') {
            return self::TYPE_CONTENT;
        }

        return self::TYPE_UNKNOWN;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && \str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
