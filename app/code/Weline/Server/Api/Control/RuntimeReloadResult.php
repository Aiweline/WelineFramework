<?php

declare(strict_types=1);

namespace Weline\Server\Api\Control;

/**
 * Stable public result for an accepted runtime reload request.
 */
final readonly class RuntimeReloadResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {
    }
}
