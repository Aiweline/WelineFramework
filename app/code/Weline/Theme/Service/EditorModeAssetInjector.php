<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;

class EditorModeAssetInjector
{
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
        $jsUrl = $this->template->fetchTagSource('statics', 'Weline_Theme::js/editor-mode.js');

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

        if (!str_contains($html, $jsUrl)) {
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $editorJs . "\n</body>", $html);
            } else {
                $html .= "\n" . $editorJs;
            }
        }

        return $html;
    }
}
