<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Localization;

/**
 * Optional locale selector contribution for the backend top bar.
 *
 * Implementations belong to localization modules. Admin only owns the UI
 * contract and remains usable when no localization provider is installed.
 */
interface BackendLocaleCatalogInterface
{
    /** @return array<string,array{name:string,flag:string}> keyed by locale code */
    public function selectable(
        string $displayLocale,
        int $flagWidth = 24,
        int $flagHeight = 18,
        bool $autoSize = true,
    ): array;
}
