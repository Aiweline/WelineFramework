<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Public boundary for encoding Theme's canonical Store-mode layout scope. */
interface LayoutScopeNormalizerInterface
{
    public function encodeStorageScope(string $storageScope, string $storeMode): string;
}
