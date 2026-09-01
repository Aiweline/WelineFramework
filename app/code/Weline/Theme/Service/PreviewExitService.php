<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Helper\PreviewManager;

/**
 * 退出主题预览：清除 Token、HttpOnly Cookie 与会话预览态。
 * 前台预览页可直接调用，不依赖后台 ACL。
 */
final class PreviewExitService
{
    public function __construct(
        private readonly PreviewTokenService $previewTokenService,
        private readonly PreviewContextService $previewContextService,
    ) {
    }

    public function exit(?string $token = null): void
    {
        if ($token === null || $token === '') {
            $token = $this->previewTokenService->getTokenFromRequest() ?? '';
        }
        $token = trim($token);

        if ($token !== '') {
            $this->previewTokenService->deleteToken($token);
        }

        $this->previewTokenService->clearPreviewCookie();
        $this->previewContextService->clearContext();
        PreviewManager::clearPreviewConfig();

        try {
            SessionFactory::getInstance()->createBackendSession()->delete('preview_auto_login');
        } catch (\Throwable) {
        }

        PreviewTokenService::resetRequestState();

        // Drop Process L1 payloads that may have been poisoned while preview cookie bypass was missing.
        try {
            FullPageCacheCoordinator::clearProcessCache();
        } catch (\Throwable) {
        }
    }
}
