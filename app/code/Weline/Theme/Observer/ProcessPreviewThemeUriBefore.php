<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\State;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Controller\Router as ThemeRouter;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;

class ProcessPreviewThemeUriBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            if ($request->isBackend()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $path = $data->getData('path');
        if (!\is_string($path)) {
            return;
        }

        $rule = $data->getData('rule');
        $ruleArr = [];
        if ($rule instanceof DataObject) {
            $ruleArr = $rule->getData();
        } elseif (\is_array($rule)) {
            $ruleArr = $rule;
        }

        if (!empty($ruleArr['module'])) {
            // Already routed: still hydrate preview markers / clear sticky language
            // override so App path-first locale wins; do not rewrite path/rule.
            if ($this->requestHasPreviewMarkers($request)) {
                try {
                    $this->hydratePreviewRequestDefaults($request);
                } catch (\Throwable) {
                }
                try {
                    $this->clearLiveCanvasLanguageOverride();
                } catch (\Throwable) {
                }
            }
            $data->setData('path', $path);
            $data->setData('rule', new DataObject($ruleArr));
            return;
        }

        $previewToken = (string)$request->getParam(PreviewTokenService::TOKEN_KEY, '');
        $editorMode = \strtolower(\trim((string)$request->getParam('editor_mode', '')));
        $isEditorMode = $editorMode === '1' || $editorMode === 'true';
        $hasPreviewContext = (int)$request->getParam('preview_theme', 0) > 0
            || (int)$request->getParam('frontend_theme_id', 0) > 0
            || (int)$request->getParam('backend_theme_id', 0) > 0
            || $previewToken !== ''
            || $isEditorMode;

        ThemeRouter::rewritePreviewThemeQuery($path, $ruleArr);
        ThemeRouter::rewriteDefaultThemePublicPage($path, $ruleArr);

        if ($hasPreviewContext) {
            try {
                $this->hydratePreviewRequestDefaults($request);
            } catch (\Throwable) {
            }

            // Live canvas visitor language is the real path (App synchronizeParsedLocalization).
            // Clear any sticky request override so path / website-default win — never ?locale=.
            try {
                $this->clearLiveCanvasLanguageOverride();
            } catch (\Throwable) {
            }
        }

        $data->setData('path', $path);
        $data->setData('rule', new DataObject($ruleArr));
    }

    private function requestHasPreviewMarkers(Request $request): bool
    {
        $editorMode = \strtolower(\trim((string)$request->getParam('editor_mode', '')));
        $isEditorMode = $editorMode === '1' || $editorMode === 'true';

        return (int)$request->getParam('preview_theme', 0) > 0
            || (int)$request->getParam('frontend_theme_id', 0) > 0
            || (int)$request->getParam('backend_theme_id', 0) > 0
            || (string)$request->getParam(PreviewTokenService::TOKEN_KEY, '') !== ''
            || $isEditorMode;
    }

    private function hydratePreviewRequestDefaults(Request $request): void
    {
        $previewToken = (string)$request->getParam(PreviewTokenService::TOKEN_KEY, '');

        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        $context = $previewContextService->persistCurrentRequestContext();

        if ($previewToken !== '') {
            /** @var PreviewTokenService $previewTokenService */
            $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
            /** @var PreviewRequestInspector $previewRequestInspector */
            $previewRequestInspector = ObjectManager::getInstance(PreviewRequestInspector::class);
            if (!$previewRequestInspector->shouldKeepPreviewStateOnlyForCurrentRequest()
                && $previewTokenService->validateToken($previewToken)) {
                $previewTokenService->setPreviewCookie($previewToken);
            }
        }

        if ((string)$request->getParam('preview_mode', '') === '') {
            $request->setGet('preview_mode', (string)($context['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE));
        }
        if ((string)$request->getParam('status', '') === '') {
            $request->setGet('status', (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS));
        }
        if ((string)$request->getParam('editor_area', '') === '') {
            $request->setGet('editor_area', (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND));
        }
        if ((string)$request->getParam('shell', '') === '') {
            $request->setGet('shell', (string)($context['shell'] ?? PreviewContextService::SHELL_PREVIEW));
        }
        // Visitor language is path-only for live canvas — never backfill ?locale= from session.
    }

    /**
     * Live canvas: clear sticky language override so App path-first locale wins.
     * Request-scoped only — never write WELINE_USER_LANG.
     */
    private function clearLiveCanvasLanguageOverride(): void
    {
        State::setRequestLanguageOverride('');
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
    }
}
