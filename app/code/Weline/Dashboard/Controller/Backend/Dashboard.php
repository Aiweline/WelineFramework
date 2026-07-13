<?php

declare(strict_types=1);

namespace Weline\Dashboard\Controller\Backend;

use Weline\Framework\App\Controller\BackendPageController;
use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;

class Dashboard extends BackendPageController
{
    protected ?string $layoutType = 'dashboard.default';

    public function __construct(
        private readonly DashboardViewService $dashboardViewService
    ) {
    }

    public function index()
    {
        $websiteId = (int)$this->request->getParam('website_id', 0);
        if ($websiteId < DashboardViewService::DEFAULT_WEBSITE_ID) {
            $websiteId = $this->dashboardViewService->getDefaultWebsiteId();
        }
        $userId = $this->dashboardViewService->getCurrentUserId();
        $viewId = (int)$this->request->getParam('view_id', 0);
        $activeView = $this->dashboardViewService->resolveActiveView($websiteId, $viewId, $userId);
        if (!$activeView) {
            $this->assign('dashboard_error', __('当前没有可用站点，无法初始化 Dashboard。'));
            return $this->fetch();
        }

        $this->applyDashboardLayoutIdentity($activeView);
        $this->dashboardViewService->ensureLayoutInitialized($activeView);

        $websites = $this->dashboardViewService->listWebsites();
        $views = $this->dashboardViewService->getVisibleViews($activeView->getWebsiteId(), $userId);
        $activePayload = $this->dashboardViewService->viewToPayload($activeView, $userId);

        $this->assign('title', __('Dashboard'));
        $this->assign('dashboard_websites', $websites);
        $this->assign('dashboard_views', $views);
        $this->assign('dashboard_active_view', $activePayload);
        $this->assign('dashboard_website_id', $activeView->getWebsiteId());
        $this->assign('dashboard_user_id', $userId);
        $this->assign('dashboard_editor_url', $this->dashboardViewService->buildThemeEditorUrl($activeView));

        return $this->fetch();
    }

    private function applyDashboardLayoutIdentity(DashboardView $view): void
    {
        $identity = $view->layoutIdentity();
        $params = [
            'page_type' => DashboardView::PAGE_TYPE,
            'layout_type' => DashboardView::PAGE_TYPE,
            'layout_option' => DashboardView::LAYOUT_OPTION,
            'scope' => $identity['scope'],
            'target_type' => $identity['target_type'],
            'target_id' => $identity['target_id'],
            'theme_layout_target_type' => $identity['target_type'],
            'theme_layout_target_id' => $identity['target_id'],
            'theme_layout_source_target_type' => $identity['target_type'],
            'theme_layout_source_target_id' => $identity['target_id'],
        ];

        foreach ($params as $key => $value) {
            $this->request->setGet($key, $value);
        }
    }
}
