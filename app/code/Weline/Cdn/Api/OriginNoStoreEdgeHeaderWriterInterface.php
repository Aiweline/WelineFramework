<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

/**
 * CDN vendor writers that mirror origin Cache-Control: no-store onto edge headers.
 *
 * Framework only sets browser/shared Cache-Control; each provider implements its
 * own CDN-* / vendor headers when the shared-cache-forbidden event fires.
 */
interface OriginNoStoreEdgeHeaderWriterInterface
{
    /**
     * Apply vendor edge no-store headers for the current response.
     * Called only when origin Cache-Control already contains no-store.
     */
    public function applyOriginNoStoreEdgeHeaders(): void;
}
