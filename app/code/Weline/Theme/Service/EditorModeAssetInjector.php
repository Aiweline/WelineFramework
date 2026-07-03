<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;

class EditorModeAssetInjector
{
    private const EDITOR_MODE_ASSET_VERSION = '20260702-dashboard-slots';

    public function __construct(
        private readonly Template $template
    ) {
    }

    public function inject(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $cssUrl = $this->template->fetchTagSource('statics', 'Weline_Theme::css/editor-mode.css');
        $jsSrc = $this->template->fetchTagSource('statics', 'Weline_Theme::js/editor-mode.js');
        $jsUrl = $jsSrc . '?v=' . self::EDITOR_MODE_ASSET_VERSION;

        $editorCss = <<<HTML
<!-- Theme Editor Mode CSS -->
<link rel="stylesheet" href="{$cssUrl}">
HTML;

        $editorJs = <<<HTML
<!-- Theme Editor Mode JS -->
<script src="{$jsUrl}"></script>
HTML;

        if (!str_contains($html, $cssUrl)) {
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $editorCss . "\n</head>", $html);
            } else {
                $html = $editorCss . "\n" . $html;
            }
        }

        if (!str_contains($html, $jsSrc)) {
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $editorJs . "\n</body>", $html);
            } else {
                $html .= "\n" . $editorJs;
            }
        }

        return $html;
    }
}
