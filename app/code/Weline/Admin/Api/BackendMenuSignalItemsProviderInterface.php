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
 *     count?: int
 * }
 */
interface BackendMenuSignalItemsProviderInterface
{
    /**
     * Bounded list of deep-link targets for the signed-in backend user.
     * Empty list is allowed; count() remains authoritative for the badge total.
     *
     * @return list<SignalItem>
     */
    public function items(int $backendUserId): array;
}
