<?php

declare(strict_types=1);

namespace Weline\Admin\Api;

/**
 * Contributes unread/action counts for a backend sidebar menu source_id.
 *
 * Codes must equal menu ACL source_id (e.g. Weline_CustomerService::console).
 * Counts must never be SSR'd into cached sidebar HTML; clients fetch via admin.menuSignals.
 */
interface BackendMenuSignalProviderInterface
{
    /**
     * Stable menu signal code === menu source_id (Vendor_Module::name).
     */
    public function code(): string;

    /**
     * Unread / pending-action count for the signed-in backend user.
     */
    public function count(int $backendUserId): int;
}
