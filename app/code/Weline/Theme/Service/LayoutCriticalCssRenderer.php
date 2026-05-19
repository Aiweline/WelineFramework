<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\View\Template;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\WelineTheme;

final class LayoutCriticalCssRenderer
{
    public function __construct(
        private LayoutCriticalCssExtractor $extractor,
    ) {
    }

    public function renderCurrent(Template $template): string
    {
        $layoutTemplates = $template->getData('layoutCriticalLayoutTemplates');
        if (!is_array($layoutTemplates) || $layoutTemplates === []) {
            $layoutTemplates = [
                $template->getData('layoutCriticalCurrentLayoutTemplate')
                    ?: $template->getData('layoutTemplate'),
            ];
        }

        $layoutTemplates = array_values(array_unique(array_filter(array_map('strval', $layoutTemplates))));
        if ($layoutTemplates === []) {
            return '';
        }

        $html = [];
        $renderedSources = [];
        foreach ($layoutTemplates as $layoutTemplate) {
            $rendered = $this->renderLayout($template, $layoutTemplate, $renderedSources);
            if ($rendered !== '') {
                $html[] = $rendered;
            }
        }

        return implode(PHP_EOL, $html);
    }

    /**
     * @param array<string, true> $renderedSources
     */
    private function renderLayout(Template $template, string $layoutTemplate, array &$renderedSources): string
    {
        if ($layoutTemplate === '') {
            return '';
        }

        $themeData = $template->getData('theme');
        if (!is_array($themeData)) {
            throw new \RuntimeException('Layout critical CSS requires theme data for layout: ' . $layoutTemplate);
        }

        $area = (string)($themeData['area'] ?? $this->detectAreaFromLayoutTemplate($layoutTemplate));
        if ($area === '') {
            throw new \RuntimeException('Layout critical CSS requires area for layout: ' . $layoutTemplate);
        }

        $theme = $themeData['theme'] ?? null;
        if (!$theme instanceof WelineTheme) {
            throw new \RuntimeException('Layout critical CSS requires theme model for layout: ' . $layoutTemplate);
        }

        $sourceFile = LayoutPathResolver::getLayoutFilePath($layoutTemplate, $theme, $area);
        if (!$sourceFile || !is_file($sourceFile)) {
            throw new \RuntimeException('Layout critical CSS source layout not found: ' . $layoutTemplate);
        }
        if (!$this->extractor->shouldHandleSource($sourceFile)) {
            return '';
        }

        if (!$this->extractor->isMetadataFresh($sourceFile)) {
            throw new \RuntimeException('Layout critical CSS metadata is missing or stale: ' . $sourceFile);
        }

        $sourceHash = $this->extractor->getSourceHash($sourceFile);
        if (isset($renderedSources[$sourceHash])) {
            return '';
        }
        $renderedSources[$sourceHash] = true;

        $metadata = $this->extractor->loadMetadata($sourceFile);
        $blocks = $metadata['css'] ?? [];
        if (!is_array($blocks) || $blocks === []) {
            return '';
        }

        $sourceHash = (string)($metadata['source_hash'] ?? $sourceHash);
        $html = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $css = (string)($block['css'] ?? '');
            if (trim($css) === '') {
                continue;
            }

            $attrs = trim((string)($block['attrs'] ?? ''));
            $order = (int)($block['order'] ?? 0);
            $styleAttrs = trim(
                'data-weline-layout-critical="true"'
                . ' data-source-hash="' . htmlspecialchars($sourceHash, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-order="' . $order . '"'
                . ($attrs !== '' ? ' ' . $attrs : '')
            );
            $safeCss = str_ireplace('</style', '<\\/style', $css);

            $html[] = '<style ' . $styleAttrs . '>' . PHP_EOL . $safeCss . PHP_EOL . '</style>';
        }

        return implode(PHP_EOL, $html);
    }

    private function detectAreaFromLayoutTemplate(string $layoutTemplate): string
    {
        $path = str_replace('\\', '/', $layoutTemplate);
        if (str_contains($path, 'theme/backend/')) {
            return 'backend';
        }
        if (str_contains($path, 'theme/frontend/')) {
            return 'frontend';
        }

        return '';
    }
}
