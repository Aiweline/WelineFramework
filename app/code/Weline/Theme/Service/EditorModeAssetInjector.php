<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;
use Weline\Theme\Service\Ui\IconRegistry;

final class EditorModeAssetInjector
{
    /** Cache-bust for preview CSS/JS; bump when health/report behavior changes. */
    private const ASSET_VERSION = '20260901-theme-editor-virtual-gate-v1';

    public function __construct(
        private readonly Template $template,
        private readonly IconRegistry $icons,
    ) {
    }

    public function inject(string $html, string $previewExitUrl = ''): string
    {
        if ($html === '') {
            return $html;
        }

        $cssUrl = $this->assetUrl('pages/weline-theme-preview.css');
        $jsUrl = $this->assetUrl('pages/weline-theme-preview.js');

        $editorCss = <<<HTML
<link rel="stylesheet" href="{$cssUrl}" data-w-editor-preview-asset="style">
HTML;

        // Module scripts defer by default; keep them in <head> so reportWidgetHtmlHealth
        // still runs when body HTML is broken and </body> never materializes cleanly.
        $editorJs = <<<HTML
<script type="module" src="{$jsUrl}" data-w-editor-preview-asset="script"></script>
HTML;

        $headBits = [];
        if (!str_contains($html, 'data-w-editor-preview-asset="style"')) {
            $headBits[] = $editorCss;
        }
        if (!str_contains($html, 'data-w-editor-preview-asset="script"')) {
            $headBits[] = $editorJs;
        }
        if ($headBits !== []) {
            $headInject = implode("\n", $headBits);
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $headInject . "\n</head>", $html);
            } else {
                $html = $headInject . "\n" . $html;
            }
        }

        $notice = $this->previewNotice($previewExitUrl);
        if ($notice !== '') {
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $notice . "\n</body>", $html);
            } else {
                $html .= "\n" . $notice;
            }
        }

        return $html;
    }

    private function assetUrl(string $relative): string
    {
        if (preg_match('#^[a-z0-9][a-z0-9/.-]+$#', $relative) !== 1 || str_contains($relative, '..')) {
            throw new \InvalidArgumentException(__('Weline UI 预览资源路径无效'));
        }

        $url = (string)$this->template->fetchTagSource('statics', 'Weline_Theme::ui/' . $relative);
        // fetchTagSource may already append ?v=preview_* — never produce ?v=a?v=b.
        $sep = str_contains($url, '?') ? '&' : '?';

        return htmlspecialchars(
            $url . $sep . 'v=' . self::ASSET_VERSION,
            ENT_QUOTES,
            'UTF-8',
        );
    }

    private function previewNotice(string $previewExitUrl): string
    {
        $previewExitUrl = trim($previewExitUrl);
        if ($previewExitUrl === '') {
            return '';
        }

        $url = htmlspecialchars($previewExitUrl, ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars((string)__('预览模式'), ENT_QUOTES, 'UTF-8');
        $hint = htmlspecialchars(
            (string)__('当前页面处于主题预览中，普通导航和提交已暂停。'),
            ENT_QUOTES,
            'UTF-8',
        );
        $exit = htmlspecialchars((string)__('退出预览'), ENT_QUOTES, 'UTF-8');
        $eye = $this->icons->render('eye', 'sm');
        $arrow = $this->icons->render('arrow-right', 'sm');

        return <<<HTML
<aside class="w-theme-preview-notice" data-editor-interactive aria-label="{$label}">
    {$eye}
    <span class="w-theme-preview-notice__copy">
        <strong>{$label}</strong>
        <small>{$hint}</small>
    </span>
    <button type="button" class="w-button w-theme-preview-notice__exit" data-tone="neutral" data-size="sm" data-w-preview-exit data-w-preview-exit-url="{$url}">
        <span>{$exit}</span>{$arrow}
    </button>
</aside>
HTML;
    }
}
