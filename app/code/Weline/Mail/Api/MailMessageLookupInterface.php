<?php

declare(strict_types=1);

namespace Weline\Mail\Api;

/**
 * Read-only lookup over persisted mail messages by their business source.
 */
interface MailMessageLookupInterface
{
    /**
     * Latest persisted message id for the given source/source-id, 0 when absent.
     */
    public function latestIdBySource(string $source, int $sourceId): int;
}
