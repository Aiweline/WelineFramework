<?php

declare(strict_types=1);

namespace Weline\Framework\View;

/**
 * Optional storefront inline-phrase rendering hooks supplied by the i18n module.
 */
interface InlineEditRenderingInterface
{
    /**
     * Session-gated inline marker for translated phrases inside HTML attributes.
     */
    public function markAttribute(string $source, string $translated): string;

    /**
     * Whether an inline-edit session is active for the current request.
     */
    public function isActive($request): bool;
}
