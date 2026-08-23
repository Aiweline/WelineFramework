<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

/**
 * Normalize theme:css / theme:js source paths (same shape as theme:font).
 *
 * Sources resolve under `{Module}/view/theme/` via fetchTagSource(THEME).
 * - `Vendor_Module::frontend/assets/css/x.css` — explicit module
 * - `frontend/assets/css/x.css` — defaults to Weline_Theme
 * - `Vendor_Module::theme/frontend/...` — optional `theme/` prefix (stripped by fetchTagSource)
 */
final class ThemeAssetSource
{
    public const DEFAULT_MODULE = 'Weline_Theme';

    public static function normalize(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        $source = str_replace('\\', '/', $source);

        if (!str_contains($source, '::')) {
            return self::DEFAULT_MODULE . '::' . ltrim($source, '/');
        }

        [$module, $relative] = array_pad(explode('::', $source, 2), 2, '');
        $module = trim($module);
        $relative = ltrim(trim((string)$relative), '/');
        if ($module === '' || $relative === '') {
            return '';
        }

        return $module . '::' . $relative;
    }
}
