<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\View\Template;

/**
 * Emits the DataTable route component assets once per request.
 *
 * The request resetter clears this state for long-running WLS workers.
 */
final class UiAssets
{
    private static bool $rendered = false;

    public static function render(Template $template): string
    {
        if (self::$rendered) {
            return '';
        }

        self::$rendered = true;
        $cssUrl = htmlspecialchars(
            $template->fetchTagSource('statics', 'Weline_Theme::ui/components/weline-datatable.css'),
            ENT_QUOTES,
            'UTF-8'
        );
        $jsUrl = htmlspecialchars(
            $template->fetchTagSource('statics', 'Weline_Theme::ui/components/weline-datatable.js'),
            ENT_QUOTES,
            'UTF-8'
        );

        return '<link rel="stylesheet" href="' . $cssUrl . '" data-w-asset="datatable">'
            . '<script type="module" src="' . $jsUrl . '" data-w-asset="datatable"></script>';
    }

    public static function resetRequestState(): void
    {
        self::$rendered = false;
    }
}
