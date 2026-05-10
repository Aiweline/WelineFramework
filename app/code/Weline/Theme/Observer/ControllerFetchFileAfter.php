<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
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
        $fileName = (string)$eventData->getData('fileName');
        $layoutOption = (string)($eventData->getData('layoutOption') ?? '');
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
        } catch (\Throwable $e) {
            $this->reportLayoutWrapFailure(
                $layoutType,
                $layoutOption,
                $contentTemplate,
                $layoutTemplate,
                $fileName,
                $e
            );
            // deploy≠dev 时保留原始 event content；deploy=dev 时由 reportLayoutWrapFailure 重新抛出
        }
    }

    /**
     * 布局包装失败时写日志、响应头；deploy=dev 时抛出以便本地立刻发现。
     */
    private function reportLayoutWrapFailure(
        string $layoutType,
        string $layoutOption,
        string $contentTemplate,
        string $layoutTemplate,
        string $fileName,
        \Throwable $e
    ): void {
        $uri = '';
        try {
            $request = ObjectManager::getInstance(Request::class);
            $uri = (string)($request->getServer('REQUEST_URI') ?? $request->getUri() ?? '');
            if ($this->shouldEmitLayoutWrapResponseHeaders()) {
                $response = $request->getResponse();
                $response->setHeader('X-Weline-Layout-Wrap-Failed', '1');
                $response->setHeader('X-Weline-Layout-Type', $layoutType);
                if ($layoutOption !== '') {
                    $response->setHeader('X-Weline-Layout-Option', $layoutOption);
                }
                $response->setHeader('X-Weline-Layout-Template', $layoutTemplate);
                $response->setHeader('X-Weline-Content-Template', $contentTemplate);
                $response->setHeader('X-Weline-File-Name', $fileName);
            }
        } catch (\Throwable) {
        }

        try {
            Env::getInstance()->getLogger()?->error('[Theme Layout Wrap Failed]', [
                'uri' => $uri,
                'layoutType' => $layoutType,
                'layoutOption' => $layoutOption,
                'contentTemplate' => $contentTemplate,
                'layoutTemplate' => $layoutTemplate,
                'fileName' => $fileName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } catch (\Throwable) {
        }

        if ($this->shouldRethrowLayoutWrapFailure()) {
            throw $e;
        }
    }

    private function shouldRethrowLayoutWrapFailure(): bool
    {
        if (defined('ENV_TEST') && constant('ENV_TEST')) {
            return false;
        }
        try {
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    /** deploy=dev 或 dev.theme_layout_wrap_response_headers=true（PHPUnit 永不输出） */
    private function shouldEmitLayoutWrapResponseHeaders(): bool
    {
        if (defined('ENV_TEST') && constant('ENV_TEST')) {
            return false;
        }
        try {
            if (Env::getInstance()->getConfig('dev.theme_layout_wrap_response_headers', false)) {
                return true;
            }
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 将控制器 assign 到 Template 根上的变量并入 meta，便于布局层 {{meta.xxx}} 与根变量同源。
     */
    private function mergeTemplateRootAssignsIntoMeta(Template $template, array $metaData): array
    {
        $skip = [
            'meta',
            'theme',
            'colors',
            'layout',
            'child_html',
            'contentTemplate',
            'layoutTemplate',
            'fileName',
            'contentRenderKey',
            'controller',
            'request',
            'req',
            'session',
            'eventsManager',
            'viewCache',
            'taglib',
            'compile_dir',
            'template_dir',
            'statics_dir',
            'view_dir',
        ];
        try {
            $all = $template->getData('');
            if (!is_array($all)) {
                return $metaData;
            }
            foreach ($all as $key => $value) {
                if (!is_string($key) || str_starts_with($key, '__')) {
                    continue;
                }
                if (in_array($key, $skip, true)) {
                    continue;
                }
                if ($value instanceof Request || $value instanceof PcController) {
                    continue;
                }
                $metaData[$key] = $value;
            }
        } catch (\Throwable) {
        }

        return $metaData;
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
        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceName = 'theme::ControllerFetchFileAfter::renderLayoutChain';
        $traceStart = $traceEnabled ? microtime(true) : 0.0;
        if ($traceEnabled) {
            RequestLifecycleTrace::pushCurrentParent($traceName);
        }

        $currentLayoutTemplate = $layoutTemplate;
        $currentContentTemplate = $contentTemplate;
        $currentContentHtml = $contentHtml;
        $renderedHtml = $contentHtml;

        try {
            for ($depth = 0; $depth < self::MAX_LAYOUT_WRAP_DEPTH; $depth++) {
                $contentRenderKey = $this->primeTemplateData(
                    $template,
                    $currentLayoutTemplate,
                    $currentContentTemplate,
                    $currentContentHtml
                );

                // Clear previous layout directive before rendering.
                $template->setData('layout', null);
                $layoutFetchSpan = 'theme::ControllerFetchFileAfter::layoutFetch::'
                    . $this->traceTemplateLabel($currentLayoutTemplate)
                    . "::depth_{$depth}";
                $layoutFetchStart = $traceEnabled ? microtime(true) : 0.0;
                if ($traceEnabled) {
                    RequestLifecycleTrace::pushCurrentParent($layoutFetchSpan);
                }
                try {
                    $renderedHtml = (string)$template->fetch(
                        $currentLayoutTemplate,
                        $this->buildLayoutFetchData($currentLayoutTemplate, $currentContentHtml, $contentRenderKey)
                    );
                } finally {
                    if ($traceEnabled) {
                        RequestLifecycleTrace::popCurrentParent();
                        RequestLifecycleTrace::recordSpan(
                            $layoutFetchSpan,
                            (microtime(true) - $layoutFetchStart) * 1000,
                            'theme'
                        );
                    }
                }

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
        } finally {
            if ($traceEnabled) {
                RequestLifecycleTrace::popCurrentParent();
                RequestLifecycleTrace::recordSpan(
                    $traceName,
                    (microtime(true) - $traceStart) * 1000,
                    'theme'
                );
            }
        }
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
        $metaData = $this->mergeTemplateRootAssignsIntoMeta($template, $metaData);
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

    /**
     * 布局链内下一层必须使用 Weline_Theme::theme/{area}/... 模块路径。
     * 裸路径 theme/... 会被 Template::convertFetchFileName 解析到「当前请求模块/view/」，导致 Customer 等控制器下错解析、外层 base 等与主题不一致。
     */
    private function toWelineThemeLayoutModulePath(string $area, string $pathUnderArea): string
    {
        $pathUnderArea = str_replace('\\', '/', trim($pathUnderArea, '/'));

        return 'Weline_Theme::theme/' . $area . '/' . $pathUnderArea;
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
            $relative = str_ends_with($layoutSpec, '.phtml') ? $layoutSpec : ($layoutSpec . '.phtml');

            return 'Weline_Theme::' . $relative;
        }

        $area = $this->detectAreaFromTemplatePath($currentLayoutTemplate);
        $layoutSpec = trim($layoutSpec, '/');

        if (str_ends_with($layoutSpec, '.phtml')) {
            if (str_starts_with($layoutSpec, 'layouts/')) {
                return $this->toWelineThemeLayoutModulePath($area, $layoutSpec);
            }

            return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . $layoutSpec);
        }

        if (!str_contains($layoutSpec, '.')) {
            if ($layoutSpec === 'base') {
                return $this->toWelineThemeLayoutModulePath($area, 'layouts/base.phtml');
            }

            return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . $layoutSpec . '/default.phtml');
        }

        return $this->toWelineThemeLayoutModulePath($area, 'layouts/' . str_replace('.', '/', $layoutSpec) . '.phtml');
    }

    private function detectAreaFromTemplatePath(string $templatePath): string
    {
        $normalizedPath = str_replace('\\', '/', strtolower($templatePath));
        if (str_contains($normalizedPath, '/templates/frontend/theme-preview/content.phtml')
            || str_contains($normalizedPath, '/templates/backend/theme-preview/content.phtml')
        ) {
            $requestArea = $this->resolveEditorAreaFromRequest();
            if ($requestArea !== '') {
                return $requestArea;
            }
        }
        if (str_contains($normalizedPath, '/theme/backend/layouts/')
            || str_contains($normalizedPath, 'theme/backend/layouts/')) {
            return 'backend';
        }

        return 'frontend';
    }

    private function resolveEditorAreaFromRequest(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $area = strtolower(trim((string)$request->getParam('editor_area', '')));
            if ($area === '') {
                $area = strtolower(trim((string)$request->getParam('preview_area', '')));
            }

            return $area === 'backend' ? 'backend' : ($area === 'frontend' ? 'frontend' : '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function traceTemplateLabel(string $templatePath): string
    {
        $normalizedPath = str_replace('\\', '/', $templatePath);
        if (str_contains($normalizedPath, '::')) {
            [$module, $path] = explode('::', $normalizedPath, 2);
            return $module . '::' . basename($path);
        }

        return basename($normalizedPath);
    }
}
