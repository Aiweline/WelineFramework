<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Optional compile-time contract that injects the global region anchors into
 * page-layout sources. Implemented by the theme module.
 */
interface GlobalRegionContractInterface
{
    /**
     * Returns the (possibly rewritten) source bytes; must be additive-only and
     * degrade to the original bytes when the layout must not be touched.
     */
    public function applyContract(string $originPath, string $sourceBytes): string;
}
