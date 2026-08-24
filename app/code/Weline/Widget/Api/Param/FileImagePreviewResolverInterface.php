<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Param;

/**
 * Resolve transient editor preview URLs for typed file-image nodes.
 *
 * Persisted widget config must keep identity-only file-image usage; preview URLs
 * are form/runtime companions and must never be written back into config JSON.
 */
interface FileImagePreviewResolverInterface
{
    /** @param array{type:string,usage:array<string,mixed>} $node */
    public function resolvePreviewUrl(array $node): string;
}
