<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

final class PreviewBootstrapAssetInjector
{
    public function __construct(
        private readonly Template $template,
        private readonly Url $url,
        private readonly ?PreviewTokenService $previewTokenService = null,
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

        // 仅预览模式需要 Bootstrap 资源；普通店面页不得加载（head 预算）。
        if (!$this->isPreviewRequest()) {
            return $html;
        }

        $bootstrapUrl = \htmlspecialchars(
            $this->url->getFrontendUrl('theme/frontend/theme-preview/bootstrap'),
            ENT_QUOTES,
            'UTF-8',
        );
        $scriptUrl = \htmlspecialchars(
            (string)$this->template->fetchTagSource('statics', 'Weline_Theme::ui/pages/weline-preview-bootstrap.js'),
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
    private function isPreviewRequest(): bool
    {
        try {
            $service = $this->previewTokenService ?? ObjectManager::getInstance(PreviewTokenService::class);

            return $service->isPreviewMode();
        } catch (\Throwable) {
            return false;
        }
    }

}
