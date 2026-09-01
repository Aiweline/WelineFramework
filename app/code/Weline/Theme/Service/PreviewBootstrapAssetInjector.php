<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\View\Template;

final class PreviewBootstrapAssetInjector
{
    public function __construct(
        private readonly Template $template,
        private readonly Url $url,
    ) {
    }

    public function inject(string $html): string
    {
        if ($html === '' || !\str_contains($html, '</head>')) {
            return $html;
        }

        if (\str_contains($html, 'data-w-preview-bootstrap')) {
            return $html;
        }

        $bootstrapUrl = \htmlspecialchars(
            $this->url->getFrontendUrl('theme/frontend/theme-preview/bootstrap'),
            ENT_QUOTES,
            'UTF-8',
        );
        $scriptUrl = \htmlspecialchars(
            $this->template->fetchTagSource('statics', 'Weline_Theme::ui/pages/weline-preview-bootstrap.js')
                . '?v=20260827-live-preview-token-v1',
            ENT_QUOTES,
            'UTF-8',
        );

        $snippet = <<<HTML
<script data-w-preview-bootstrap="true">
document.documentElement.dataset.wPreviewBootstrapUrl = "{$bootstrapUrl}";
</script>
<script type="module" src="{$scriptUrl}" data-w-preview-bootstrap="true"></script>
HTML;

        return \str_ireplace('</head>', $snippet . "\n</head>", $html);
    }
}
