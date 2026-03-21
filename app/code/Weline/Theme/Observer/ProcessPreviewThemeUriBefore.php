<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Controller\Router as ThemeRouter;
use Weline\Theme\Service\PreviewContextService;
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
            return;
        }

        $previewToken = (string)$request->getParam(PreviewTokenService::TOKEN_KEY, '');
        $hasPreviewContext = (int)($_GET['preview_theme'] ?? 0) > 0
            || (int)$request->getParam('frontend_theme_id', 0) > 0
            || (int)$request->getParam('backend_theme_id', 0) > 0
            || $previewToken !== '';

        if ($hasPreviewContext) {
            try {
                /** @var PreviewContextService $previewContextService */
                $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
                $context = $previewContextService->persistCurrentRequestContext();

                if ($previewToken !== '') {
                    /** @var PreviewTokenService $previewTokenService */
                    $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
                    if ($previewTokenService->validateToken($previewToken)) {
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
            } catch (\Throwable) {
            }
        }

        ThemeRouter::rewritePreviewThemeQuery($path, $ruleArr);

        $data->setData('path', $path);
        $data->setData('rule', new DataObject($ruleArr));
    }
}
