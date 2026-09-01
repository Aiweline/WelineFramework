<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;

/**
 * Persist live storefront preview bearer token into HttpOnly cookie + session context.
 *
 * URL: /theme/frontend/theme-preview/bootstrap?weline_preview_token=…
 */
class Bootstrap extends FrontendController
{
    public function index(): string
    {
        return $this->persistPreviewToken();
    }

    public function postIndex(): string
    {
        return $this->persistPreviewToken();
    }

    public function getIndex(): string
    {
        return $this->persistPreviewToken();
    }

    private function persistPreviewToken(): string
    {
        $token = \trim((string)$this->request->getParam(PreviewTokenService::TOKEN_KEY, ''));
        if ($token === '') {
            return $this->respond(false, (string)__('缺少预览 Token'));
        }

        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        $tokenData = $previewTokenService->validateToken($token);
        if ($tokenData === null) {
            return $this->respond(false, (string)__('预览 Token 无效或已过期'));
        }

        try {
            $previewTokenService->setPreviewCookie($token);
        } catch (\Throwable $exception) {
            return $this->respond(false, $exception->getMessage() !== ''
                ? $exception->getMessage()
                : (string)__('预览 Token 无法写入 Cookie'));
        }

        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        $previewContextService->persistContext(
            $previewContextService->buildContext([
                'preview_token' => $token,
            ])
        );

        if (!$this->wantsJsonResponse()) {
            $redirect = \trim((string)$this->request->getParam('redirect', '/'));
            if ($redirect === '' || !\str_starts_with($redirect, '/')) {
                $redirect = '/';
            }

            $this->request->getResponse()->redirect($redirect);

            return '';
        }

        return $this->respond(true, (string)__('预览 Token 已保存'));
    }

    private function wantsJsonResponse(): bool
    {
        $accept = \strtolower((string)$this->request->getHeader('accept'));
        if (\str_contains($accept, 'application/json')) {
            return true;
        }

        return \in_array($this->request->getMethod(), ['POST', 'PUT', 'PATCH'], true);
    }

    private function respond(bool $success, string $message): string
    {
        return $this->fetchJson([
            'success' => $success,
            'message' => $message,
        ]);
    }
}
