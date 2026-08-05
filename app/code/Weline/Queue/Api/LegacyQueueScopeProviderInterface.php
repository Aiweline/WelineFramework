<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

use Weline\Framework\Runtime\ScopeEnvelope;

/**
 * Optional, migration-only declaration for a legacy Queue handler.
 *
 * Implementations must recover Scope exclusively from immutable task payload
 * or an already-frozen aggregate snapshot. The methods must be deterministic,
 * read-only and free of external side effects. Returning null quarantines the
 * unfinished row; it never falls back to Global or website_id=0.
 */
interface LegacyQueueScopeProviderInterface
{
    public static function legacyScopeProducerKey(): string;

    /**
     * @param array<string, mixed> $queueRow
     */
    public static function recoverLegacyScopeEnvelope(array $queueRow): ?ScopeEnvelope;
}
