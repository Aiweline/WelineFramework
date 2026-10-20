<?php

declare(strict_types=1);

namespace Weline\Admin\Api;

/**
 * Optional deep-link items for a backend sidebar menu signal.
 *
 * Implement alongside BackendMenuSignalProviderInterface when the leaf menu
 * should expose message/session-level hrefs (authored by the owning module).
 * Admin JS must never invent business URLs — only apply hrefs from this list.
 *
 * @phpstan-type SignalItem array{
 *     id: string,
 *     title: string,
 *     href: string,
 *     unread: true,
 *     count?: int
 * }
 */
interface BackendMenuSignalItemsProviderInterface
{
    /**
     * Unread deep links only. Each row must set unread=true.
     * Read rows and rows without the flag are dropped and do not paint a badge.
     *
     * @return list<SignalItem>
     */
    public function items(int $backendUserId): array;
}
