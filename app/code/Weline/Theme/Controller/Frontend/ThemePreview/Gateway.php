<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Service\PreviewExitService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemePreviewEntryApplication;

/**
 * 前台主题预览网关：先进入 Theme 模块再写入 Session，避免其它模块 Router 抢占 index/index
 *
 * URL: /theme/frontend/theme-preview/gateway?preview_theme=…
 * 退出预览: /theme/frontend/theme-preview/gateway?exit=1
 */
class Gateway extends FrontendController
{
    public function index(): array|string
    {
        if ($this->shouldExitPreview()) {
            return $this->handlePreviewExit();
        }

        // 裸开 gateway（无 exit）且未登录：清残留预览态并回首页，避免 JSON「需要后台登录」死页
        $loggedIn = false;
        try {
            $loggedIn = SessionFactory::getInstance()->createBackendSession()->isLoggedIn();
        } catch (\Throwable) {
            $loggedIn = false;
        }
        if (!$loggedIn) {
            if (!$this->wantsJsonExitResponse()) {
                try {
                    ObjectManager::getInstance(PreviewExitService::class)->exit($this->resolveExitToken() ?: null);
                } catch (\Throwable) {
                }
                $this->request->getResponse()->redirect($this->resolveExitRedirectUrl());

                return '';
            }

            return $this->error(__('主题预览需要有效的后台登录状态。'));
        }

        $editorArea = (string)$this->request->getParam(
            'editor_area',
            (string)$this->request->getParam('preview_area', 'frontend')
        );
        $area = $editorArea === 'backend' ? 'backend' : 'frontend';
        $explicitPreviewThemeId = \max(0, (int)$this->request->getParam('preview_theme', 0));
        $frontendThemeId = (int)$this->request->getParam('frontend_theme_id', 0);
        $backendThemeId = (int)$this->request->getParam('backend_theme_id', 0);

        if ($explicitPreviewThemeId > 0) {
            if ($area === 'backend') {
                $backendThemeId = $explicitPreviewThemeId;
            } else {
                $frontendThemeId = $explicitPreviewThemeId;
            }
        }

        $themeId = $area === 'backend' ? $backendThemeId : $frontendThemeId;
        $scope = $this->request->getParam('scope');
        $pageType = (string)$this->request->getParam('page_type', 'homepage');
        $versionId = (int)$this->request->getParam('version_id', 0);
        $status = (string)$this->request->getParam('status', 'draft');
        $previewMode = (string)$this->request->getParam('preview_mode', 'default');
        $scopeStr = $scope !== null && $scope !== '' ? trim((string)$scope) : null;

        /** @var ThemePreviewEntryApplication $app */
        $app = ObjectManager::getInstance(ThemePreviewEntryApplication::class);
        $result = $app->preparePreviewRedirect(
            $themeId,
            $area,
            $this->session,
            false,
            $scopeStr,
            $pageType,
            $versionId > 0 ? $versionId : null,
            $status,
            $editorArea,
            $previewMode,
        );

        if (!$result['ok']) {
            return $this->error($result['message']);
        }

        $this->request->getResponse()->redirect($result['redirect']);

        return '';
    }

    private function shouldExitPreview(): bool
    {
        $exitFlag = \trim((string)$this->request->getParam('exit', ''));
        if ($exitFlag === '1' || \strtolower($exitFlag) === 'true') {
            return true;
        }

        $bodyParams = $this->request->getBodyParams();
        if (\is_string($bodyParams)) {
            $data = \json_decode($bodyParams, true);
            if (\is_array($data) && !empty($data['exit'])) {
                return true;
            }
        } elseif (\is_array($bodyParams) && !empty($bodyParams['exit'])) {
            return true;
        }

        return false;
    }

    private function handlePreviewExit(): string
    {
        $token = $this->resolveExitToken();

        try {
            ObjectManager::getInstance(PreviewExitService::class)->exit($token !== '' ? $token : null);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            try {
                ObjectManager::getInstance(PreviewExitService::class)->exit(null);
            } catch (\Throwable) {
            }

            if ($this->wantsJsonExitResponse()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('Failed to exit preview'),
                ]);
            }

            return $this->error($e->getMessage() !== '' ? $e->getMessage() : (string)__('Failed to exit preview'));
        }

        if ($this->wantsJsonExitResponse()) {
            return $this->fetchJson([
                'success' => true,
                'message' => __('Preview exited'),
            ]);
        }

        $this->request->getResponse()->redirect($this->resolveExitRedirectUrl());

        return '';
    }

    private function resolveExitToken(): string
    {
        $token = $this->request->getParam('token', '');
        if (($token === '' || $token === null) && \in_array($this->request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $bodyParams = $this->request->getBodyParams();
            if (\is_string($bodyParams)) {
                $data = \json_decode($bodyParams, true);
                if (\is_array($data)) {
                    $token = $data['token'] ?? '';
                }
            } elseif (\is_array($bodyParams)) {
                $token = $bodyParams['token'] ?? '';
            }
        }

        return \is_scalar($token) ? \trim((string)$token) : '';
    }

    private function wantsJsonExitResponse(): bool
    {
        if ($this->request->isAjax()) {
            return true;
        }

        $accept = \strtolower((string)$this->request->getHeader('Accept'));
        return \str_contains($accept, 'application/json');
    }

    private function resolveExitRedirectUrl(): string
    {
        $redirect = \trim((string)$this->request->getParam('redirect', ''));
        if ($redirect === '') {
            return \rtrim($this->request->getBaseHost(), '/') . '/';
        }

        if (\str_starts_with($redirect, '/')) {
            return \rtrim($this->request->getBaseHost(), '/')
                . $this->stripPreviewTokenFromRedirect($redirect);
        }

        $parts = \parse_url($redirect);
        if (!\is_array($parts)) {
            return '/';
        }

        $baseHost = $this->request->getBaseHost();
        $baseParts = \parse_url($baseHost);
        $host = \strtolower((string)($parts['host'] ?? ''));
        $currentHost = \strtolower((string)($baseParts['host'] ?? ''));
        $port = (int)($parts['port'] ?? 0);
        $currentPort = (int)($baseParts['port'] ?? 0);
        if ($host === ''
            || $currentHost === ''
            || $host !== $currentHost
            || ($port > 0 && $currentPort > 0 && $port !== $currentPort)
        ) {
            return '/';
        }

        $path = (string)($parts['path'] ?? '/');
        $query = (string)($parts['query'] ?? '');

        return \rtrim($baseHost, '/')
            . $this->stripPreviewTokenFromRedirect($path . ($query !== '' ? '?' . $query : ''));
    }

    private function stripPreviewTokenFromRedirect(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '/';
        }

        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }
        unset($query[PreviewTokenService::TOKEN_KEY], $query['exit'], $query['token'], $query['redirect']);
        $path = (string)($parts['path'] ?? '/');
        $queryString = $query !== [] ? '?' . \http_build_query($query) : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $path . $queryString . $fragment;
    }
}
