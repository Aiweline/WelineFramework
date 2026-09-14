<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

/**
 * Optional provider capability: localize remote category path labels before shell ensure.
 *
 * Dropship never hard-depends on a concrete supplier localizer; providers that own EN→ZH
 * maps implement this Interface so publish can write zh display names correctly.
 */
interface DropshipCategoryPathLocalizerInterface
{
    /**
     * Localize a remote category path (segments separated by " / " or "/") for the locale.
     */
    public function localizeCategoryPath(string $path, string $locale): string;
}
