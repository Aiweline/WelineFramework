<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Service\PreparedContentStore;

/**
 * Keep layout rendering lifecycle unified for controller template fetch.
 *
 * Flow:
 * 1) render original content template
 * 2) inject backend content by request-scoped key, frontend content by direct HTML
 * 3) render layout template
 * 4) if layout requested wrapper via `$this->setLayout(...)`, continue wrapping
 */
class ControllerFetchFileAfter implements ObserverInterface
{
    private const MAX_LAYOUT_WRAP_DEPTH = 4;

    protected function getTemplateInstance(): Template
    {
        return Template::getInstance();
    }

    public function execute(Event &$event): void
    {
        /** @var DataObject|mixed $eventData */
        $eventData = $event->getData('data');
        if (!$eventData instanceof DataObject) {
            return;
        }

        $layoutType = (string)$eventData->getData('layoutType');
        $contentTemplate = (string)$eventData->getData('contentTemplate');
        $layoutTemplate = (string)($eventData->getData('layoutTemplate') ?: $eventData->getData('fileName'));
        if ($layoutType === '' || $contentTemplate === '' || $layoutTemplate === '') {
            return;
        }

        $template = $this->getTemplateInstance();
        $fallbackContent = (string)$eventData->getData('content');

        try {
            $contentHtml = $this->renderContentTemplate($template, $contentTemplate, $fallbackContent);
            [$renderedHtml, $finalLayoutTemplate] = $this->renderLayoutChain(
                $template,
                $layoutTemplate,
                $contentTemplate,
                $contentHtml
            );

            $eventData->setData('content', $renderedHtml);
            $eventData->setData('fileName', $finalLayoutTemplate);
        } catch (\Throwable) {
            // Keep original event content when wrapping fails.
        }
    }

    private function renderContentTemplate(Template $template, string $contentTemplate, string $fallbackContent): string
    {
        if ($fallbackContent !== '') {
            return $fallbackContent;
        }

        try {
            $rendered = (string)$template->fetch($contentTemplate);
            if ($rendered !== '') {
                return $rendered;
            }
        } catch (\Throwable) {
        }

        return $fallbackContent;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function renderLayoutChain(
        Template $template,
        string $layoutTemplate,
        string $contentTemplate,
        string $contentHtml
    ): array {
        $currentLayoutTemplate = $layoutTemplate;
        $currentContentTemplate = $contentTemplate;
        $currentContentHtml = $contentHtml;
        $renderedHtml = $contentHtml;

        for ($depth = 0; $depth < self::MAX_LAYOUT_WRAP_DEPTH; $depth++) {
            $contentRenderKey = $this->primeTemplateData(
                $template,
                $currentLayoutTemplate,
                $currentContentTemplate,
                $currentContentHtml
            );

            // Clear previous layout directive before rendering.
            $template->setData('layout', null);
            $renderedHtml = (string)$template->fetch(
                $currentLayoutTemplate,
                $this->buildLayoutFetchData($currentLayoutTemplate, $currentContentHtml, $contentRenderKey)
            );

            $nextLayout = trim((string)$template->getData('layout'));
            if ($nextLayout === '') {
                return [$renderedHtml, $currentLayoutTemplate];
            }

            $nextLayoutTemplate = $this->resolveNextLayoutTemplate($currentLayoutTemplate, $nextLayout);
            if ($nextLayoutTemplate === '' || $nextLayoutTemplate === $currentLayoutTemplate) {
                return [$renderedHtml, $currentLayoutTemplate];
            }

            // Next wrapper uses current layout output as its content.
            $currentContentTemplate = $currentLayoutTemplate;
            $currentContentHtml = $renderedHtml;
            $currentLayoutTemplate = $nextLayoutTemplate;
        }

        return [$renderedHtml, $currentLayoutTemplate];
    }

    private function primeTemplateData(
        Template $template,
        string $layoutTemplate,
        string $contentTemplate,
        string $contentHtml
    ): string
    {
        $isBackendLayout = $this->isBackendLayoutTemplate($layoutTemplate);
        $contentRenderKey = $isBackendLayout ? PreparedContentStore::put($contentHtml) : '';

        $metaData = $template->getData('meta');
        if (!is_array($metaData)) {
            $metaData = [];
        }
        $metaData['contentTemplate'] = $contentTemplate;
        if ($contentRenderKey !== '') {
            unset($metaData['content']);
            $metaData['contentRenderKey'] = $contentRenderKey;
        } else {
            unset($metaData['contentRenderKey']);
            $metaData['content'] = $contentHtml;
        }
        $template->setData('meta', $metaData);

        $childHtml = $template->getData('child_html');
        if (!is_array($childHtml)) {
            $childHtml = [];
        }
        if ($contentRenderKey !== '') {
            unset($childHtml['content']);
            $childHtml['contentRenderKey'] = $contentRenderKey;
        } else {
            unset($childHtml['contentRenderKey']);
            $childHtml['content'] = $contentHtml;
        }
        $template->setData('child_html', $childHtml);

        if ($contentRenderKey !== '') {
            $template->setData('content', null);
            $template->setData('contentRenderKey', $contentRenderKey);
        } else {
            $template->setData('content', $contentHtml);
            $template->setData('contentRenderKey', null);
        }
        $template->setData('contentTemplate', $contentTemplate);

        return $contentRenderKey;
    }

    private function buildLayoutFetchData(string $layoutTemplate, string $contentHtml, string $contentRenderKey): array
    {
        if ($this->isBackendLayoutTemplate($layoutTemplate)) {
            return [
                'contentRenderKey' => $contentRenderKey,
            ];
        }

        return [
            'content' => $contentHtml,
        ];
    }

    private function isBackendLayoutTemplate(string $layoutTemplate): bool
    {
        return $this->detectAreaFromTemplatePath($layoutTemplate) === 'backend';
    }

    private function resolveNextLayoutTemplate(string $currentLayoutTemplate, string $layoutSpec): string
    {
        $layoutSpec = trim($layoutSpec);
        if ($layoutSpec === '') {
            return '';
        }

        if (str_contains($layoutSpec, '::')) {
            return $layoutSpec;
        }

        $layoutSpec = str_replace('\\', '/', $layoutSpec);
        if (str_starts_with($layoutSpec, 'theme/')) {
            return str_ends_with($layoutSpec, '.phtml') ? $layoutSpec : ($layoutSpec . '.phtml');
        }

        $area = $this->detectAreaFromTemplatePath($currentLayoutTemplate);
        $layoutSpec = trim($layoutSpec, '/');

        if (str_ends_with($layoutSpec, '.phtml')) {
            if (str_starts_with($layoutSpec, 'layouts/')) {
                return "theme/{$area}/{$layoutSpec}";
            }

            return "theme/{$area}/layouts/{$layoutSpec}";
        }

        if (!str_contains($layoutSpec, '.')) {
            if ($layoutSpec === 'base') {
                return "theme/{$area}/layouts/base.phtml";
            }

            return "theme/{$area}/layouts/{$layoutSpec}/default.phtml";
        }

        return 'theme/' . $area . '/layouts/' . str_replace('.', '/', $layoutSpec) . '.phtml';
    }

    private function detectAreaFromTemplatePath(string $templatePath): string
    {
        $normalizedPath = str_replace('\\', '/', strtolower($templatePath));
        if (str_contains($normalizedPath, '/theme/backend/layouts/')
            || str_contains($normalizedPath, 'theme/backend/layouts/')) {
            return 'backend';
        }

        return 'frontend';
    }
}
