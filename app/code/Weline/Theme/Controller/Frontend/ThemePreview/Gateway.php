<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemePreviewEntryApplication;

/**
 * 前台主题预览网关：先进入 Theme 模块再写入 Session，避免其它模块 Router 抢占 index/index
 *
 * URL: /theme/frontend/theme-preview/gateway?preview_theme=…
 */
class Gateway extends FrontendController
{
    public function index(): array|string
    {
        $editorArea = (string)$this->request->getParam(
            'editor_area',
            (string)$this->request->getParam('preview_area', 'frontend')
        );
        $area = $editorArea === 'backend' ? 'backend' : 'frontend';
        $explicitPreviewThemeId = \max(0, (int)($_GET['preview_theme'] ?? $this->request->getParam('preview_theme', 0)));
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
        $autoLogin = $this->request->getParam('auto_login', '1');
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
            $autoLogin,
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
}
