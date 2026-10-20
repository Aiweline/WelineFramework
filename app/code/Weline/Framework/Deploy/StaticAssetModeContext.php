<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * Opaque inputs for a static-asset mode publish.
 * Subjects are caller-owned theme handles; Framework does not name theme types.
 */
final class StaticAssetModeContext
{
    /**
     * @param list<mixed> $subjects
     * @param list<string> $modules
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $channel,
        public readonly string $staticRoot,
        public readonly array $subjects = [],
        public readonly array $modules = [],
    ) {
    }
}
