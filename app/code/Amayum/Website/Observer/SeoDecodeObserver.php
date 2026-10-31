<?php

declare(strict_types=1);

namespace Amayum\Website\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 在 seo_decode（早于所有 Router 与 layout_resolve）把 Amayum 的两个公开路径
 * 改写为模块控制器，避免默认主题同名布局（contact / sitemap）抢占。
 *
 * 只对 Amayum 站点生效（website_id、Host 或 /~site/amayum 预览前缀任一命中）。
 */
final class SeoDecodeObserver implements ObserverInterface
{
    private const REWRITES = [
        'contact' => 'amayum/frontend/contact',
        'sitemap' => 'amayum/frontend/sitemap-page',
    ];

    private static ?int $amayumWebsiteId = null;

    public function execute(Event &$event): void
    {
        if (!$this->isAmayumRequest((int)($event->getData('website_id') ?? 0))) {
            return;
        }

        $url = $event->getData('url');
        if (!\is_string($url) || $url === '') {
            return;
        }

        $path = \strtolower(\trim((string)\parse_url($url, \PHP_URL_PATH), '/'));
        $segments = $path === '' ? [] : \explode('/', $path);
        while ($segments !== [] && \preg_match('/^[a-z]{2}(_[A-Za-z]+)?(_[A-Z]{2})?$/', (string)$segments[0]) === 1) {
            \array_shift($segments);
        }
        $candidate = \count($segments) === 1 ? (string)$segments[0] : '';

        $target = self::REWRITES[$candidate] ?? null;
        if ($target === null) {
            return;
        }

        $query = (string)(\parse_url($url, \PHP_URL_QUERY) ?? '');
        $event->setData('url', $target . ($query !== '' ? '?' . $query : ''));
        if ($event->getData('lookup_path') !== null) {
            $event->setData('lookup_path', $target);
        }
    }

    private function isAmayumRequest(int $payloadWebsiteId): bool
    {
        $currentId = $payloadWebsiteId;
        if ($currentId <= 0) {
            $currentId = (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0);
        }

        // Cheap, DB-free checks first: this observer runs on EVERY storefront's
        // seo_decode, so resolving the Amayum website id up front would put a
        // full website lookup (cold DB connect + ORM + query, ~142 ms first hit)
        // on the first request of every site just to learn "not Amayum".
        $host = \strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $hostLooksAmayum = $host !== ''
            && \preg_match('/(^|\.)amayum\.com(:\d+)?$/', $host) === 1;
        if ($currentId <= 0) {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $uriLooksAmayum = \str_contains($uri, '/~site/amayum/')
                || \str_ends_with($uri, '/~site/amayum');

            return $hostLooksAmayum || $uriLooksAmayum;
        }

        // A concrete website id is known. The id lookup is still worth paying
        // only when this request could possibly be Amayum: either the host says
        // so, or the Amayum id was already resolved earlier in this process.
        // A non-Amayum host whose id is still unknown cannot match.
        if (self::$amayumWebsiteId !== null) {
            $amayumId = self::$amayumWebsiteId;
        } elseif ($hostLooksAmayum) {
            $amayumId = $this->amayumWebsiteId();
        } else {
            return false;
        }

        return $amayumId > 0 && $currentId === $amayumId;
    }

    private function amayumWebsiteId(): int
    {
        if (self::$amayumWebsiteId !== null) {
            return self::$amayumWebsiteId;
        }

        self::$amayumWebsiteId = 0;
        try {
            $website = w_query('websites', 'getWebsiteByCode', ['code' => 'amayum']);
            if (\is_array($website)) {
                self::$amayumWebsiteId = (int)($website['website_id'] ?? 0);
            }
        } catch (\Throwable) {
            self::$amayumWebsiteId = 0;
        }

        return self::$amayumWebsiteId;
    }
}
