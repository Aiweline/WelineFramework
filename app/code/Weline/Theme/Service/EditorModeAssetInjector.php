<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;
use Weline\Theme\Service\Ui\IconRegistry;

final class EditorModeAssetInjector
{
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

        $editorJs = <<<HTML
<script type="module" src="{$jsUrl}" data-w-editor-preview-asset="script"></script>
HTML;

        if (!str_contains($html, 'data-w-editor-preview-asset="style"')) {
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $editorCss . "\n</head>", $html);
            } else {
                $html = $editorCss . "\n" . $html;
            }
        }

        $notice = $this->previewNotice($previewExitUrl);
        if (!str_contains($html, 'data-w-editor-preview-asset="script"')) {
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $notice . "\n" . $editorJs . "\n</body>", $html);
            } else {
                $html .= "\n" . $notice . "\n" . $editorJs;
            }
        }

        return $html;
    }

    private function assetUrl(string $relative): string
    {
        if (preg_match('#^[a-z0-9][a-z0-9/.-]+$#', $relative) !== 1 || str_contains($relative, '..')) {
            throw new \InvalidArgumentException(__('Weline UI 预览资源路径无效'));
        }

        return htmlspecialchars(
            $this->template->fetchTagSource('statics', 'Weline_Theme::ui/' . $relative)
                . '?v=20260826-slot-toolbar-resolve-v1',
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
    <a class="w-button w-theme-preview-notice__exit" data-tone="neutral" data-size="sm" href="{$url}" target="_top">
        <span>{$exit}</span>{$arrow}
    </a>
</aside>
HTML;
    }
}
