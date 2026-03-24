<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Http\Request;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\PreviewAccountManager;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

class PreviewAutoLogin implements ObserverInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly Request $request,
        private readonly PreviewContextService $previewContextService,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            if (!$this->isPreviewMode()) {
                return;
            }

            if ($this->request->isBackend()) {
                return;
            }

            if (!$this->isBackendUserLoggedIn()) {
                return;
            }

            $context = $this->previewContextService->getCurrentContext();
            $themeId = $this->previewContextService->getThemeIdForArea('frontend', $context, false);
            if ($themeId <= 0) {
                return;
            }

            if ($this->shouldAutoLogin($context)) {
                PreviewAccountManager::loginPreviewUserByThemeId($themeId);
                return;
            }

            PreviewAccountManager::logoutPreviewUser($themeId);
        } catch (\Throwable $e) {
            w_log_error('Theme preview auto login failed: ' . $e->getMessage(), [], 'theme');
        }
    }

    private function isPreviewMode(): bool
    {
        if (!$this->previewContextService->shouldUseStoredContext()) {
            return false;
        }

        $context = $this->previewContextService->getCurrentContext();
        return $this->previewContextService->getThemeIdForArea('frontend', $context, false) > 0;
    }

    private function isBackendUserLoggedIn(): bool
    {
        try {
            /** @var AuthenticatedSessionInterface $backendSession */
            $backendSession = SessionFactory::getInstance()->createBackendSession();
            return $backendSession->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function shouldAutoLogin(array $context): bool
    {
        if (($context['editor_area'] ?? 'frontend') !== 'frontend') {
            return false;
        }

        $previewAutoLogin = $this->session->getData('preview_auto_login');
        if ($previewAutoLogin !== null) {
            return (bool)$previewAutoLogin;
        }

        $theme = $this->themeContextService->resolveTheme('frontend');
        if (!$theme || !$theme->getId()) {
            return false;
        }

        $layouts = PreviewManager::getPreviewConfig('layouts', 'frontend');
        if (empty($layouts) || !is_array($layouts)) {
            return false;
        }

        return $this->checkLayoutRequiresLogin($theme, $layouts);
    }

    private function checkLayoutRequiresLogin(WelineTheme $theme, array $layouts): bool
    {
        try {
            foreach (['account', 'homepage', 'default'] as $layoutType) {
                if (!isset($layouts[$layoutType]) || empty($layouts[$layoutType])) {
                    continue;
                }

                $previewLogin = $this->getLayoutPreviewLogin(
                    $theme,
                    'frontend',
                    $layoutType,
                    (string)$layouts[$layoutType]
                );
                if ($previewLogin !== null) {
                    return $previewLogin === 1;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function getLayoutPreviewLogin(
        WelineTheme $theme,
        string $area,
        string $layoutType,
        string $layoutOption
    ): ?int {
        try {
            $themePath = (string)$theme->getPath();
            if ($themePath === '') {
                return null;
            }

            $layoutPath = rtrim($themePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'view'
                . DIRECTORY_SEPARATOR . 'theme'
                . DIRECTORY_SEPARATOR . $area
                . DIRECTORY_SEPARATOR . 'layouts'
                . DIRECTORY_SEPARATOR . $layoutType
                . DIRECTORY_SEPARATOR . $layoutOption
                . '.phtml';
            $layoutPath = str_replace('\\', DIRECTORY_SEPARATOR, $layoutPath);

            if (!is_file($layoutPath)) {
                $parentId = (int)$theme->getParentId();
                if ($parentId <= 0) {
                    return null;
                }

                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($parentId);
                if (!$parentTheme->getId()) {
                    return null;
                }

                return $this->getLayoutPreviewLogin($parentTheme, $area, $layoutType, $layoutOption);
            }

            $meta = ComponentMetaParser::parse($layoutPath);
            return isset($meta['preview_login']) ? (int)$meta['preview_login'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
