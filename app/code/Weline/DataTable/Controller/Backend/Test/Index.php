<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Admin\Controller\BaseController;
use Weline\DataTable\Controller\Backend\Test\Concern\HandlesBackendLayouts;
use Weline\DataTable\Service\BackendAdminPageService;
use Weline\Framework\Acl\Acl;

#[Acl(
    'Weline_DataTable::datatable_test_index',
    'DataTable 测试首页',
    'mdi mdi-view-dashboard-outline',
    'DataTable 后台测试与文档入口',
    'Weline_DataTable::datatable_module'
)]
class Index extends BaseController
{
    use HandlesBackendLayouts;

    public function __construct(
        private readonly BackendAdminPageService $backendAdminPageService
    ) {
    }

    #[Acl(
        'Weline_DataTable::test_index_index',
        'DataTable 后台仪表盘',
        'mdi mdi-monitor-dashboard',
        'DataTable 后台测试仪表盘'
    )]
    public function index(): string
    {
        $currentLayoutKey = $this->applyBackendLayout();

        $dashboardData = $this->backendAdminPageService->getDashboardData();
        $dashboardData['scenarios'] = $this->appendScenarioUrls($dashboardData['scenarios'] ?? [], $currentLayoutKey);
        $dashboardData['docs'] = $this->decorateDocumentLinks($dashboardData['docs'] ?? [], 'index/doc', $currentLayoutKey);

        $this->assign(array_merge(
            [
                'title' => (string) __('DataTable Backend Dashboard'),
                'currentLayout' => $currentLayoutKey,
                'layoutOptions' => $this->buildBackendLayoutOptions('index', $currentLayoutKey),
                'layoutSwitcherTitle' => (string) __('Backend Layouts'),
                'layoutSwitcherDescription' => (string) __('Switch the backend shell here to validate the admin dashboard and related DataTable pages under different layout variants.'),
                'dashboardUrl' => $this->routeWithQuery('index', ['layout' => $currentLayoutKey]),
                'comprehensiveUrl' => $this->routeWithQuery('comprehensive/index', ['layout' => $currentLayoutKey]),
                'tagTestUrl' => $this->routeWithQuery('tag-test/index', ['layout' => $currentLayoutKey]),
                'docUrl' => $this->routeWithQuery('index/doc', ['layout' => $currentLayoutKey]),
                'frontendDemoUrl' => $this->getUrl('datatable/test'),
                'frontendApiBasePath' => $this->getFrontendApiBasePath(),
                'demoInitUrl' => 'datatable/rest/v1/demo-table/init-data',
                'demoClearUrl' => 'datatable/rest/v1/demo-table/clear-data',
            ],
            $dashboardData
        ));

        return (string) $this->fetchBase('Weline_DataTable::backend/templates/test/index.phtml');
    }

    #[Acl(
        'Weline_DataTable::test_index_doc',
        'DataTable 文档说明',
        'mdi mdi-book-open-page-variant-outline',
        'DataTable 后台文档页'
    )]
    public function doc(): string
    {
        $currentLayoutKey = $this->applyBackendLayout();
        $selectedDoc = (string) $this->request->getParam('doc', 'quickstart');
        $pageData = $this->backendAdminPageService->getDocumentationPageData($selectedDoc);
        $selectedDocKey = (string) ($pageData['selectedDoc']['key'] ?? 'quickstart');
        $pageData['docs'] = $this->decorateDocumentLinks($pageData['docs'] ?? [], 'doc', $currentLayoutKey);
        $pageData['selectedDoc']['url'] = $this->routeWithQuery('doc', [
            'doc' => $selectedDocKey,
            'layout' => $currentLayoutKey,
        ]);

        $this->assign(array_merge(
            [
                'title' => (string) __('DataTable Documentation'),
                'currentLayout' => $currentLayoutKey,
                'layoutOptions' => $this->buildBackendLayoutOptions('doc', $currentLayoutKey, true, ['doc' => $selectedDocKey]),
                'layoutSwitcherTitle' => (string) __('Documentation Layout'),
                'layoutSwitcherDescription' => (string) __('Use the same document page in different backend shells to verify spacing, navigation, and long-form content behavior.'),
                'dashboardUrl' => $this->routeWithQuery('../index', ['layout' => $currentLayoutKey]),
                'comprehensiveUrl' => $this->routeWithQuery('../comprehensive/index', ['layout' => $currentLayoutKey]),
                'tagTestUrl' => $this->routeWithQuery('../tag-test/index', ['layout' => $currentLayoutKey]),
                'docUrl' => $this->routeWithQuery('doc', ['layout' => $currentLayoutKey]),
            ],
            $pageData
        ));

        return (string) $this->fetchBase('Weline_DataTable::backend/templates/test/doc.phtml');
    }

    private function getFrontendApiBasePath(): string
    {
        $path = (string) parse_url((string) $this->_url->getFrontendApiUrl(''), PHP_URL_PATH);
        return rtrim($path, '/');
    }

    /**
     * @param array<int,array<string,mixed>> $scenarios
     * @return array<int,array<string,mixed>>
     */
    private function appendScenarioUrls(array $scenarios, string $layoutKey): array
    {
        foreach ($scenarios as &$scenario) {
            $route = (string) ($scenario['route'] ?? 'index');
            $segment = preg_replace('/([a-z])([A-Z])/', '$1-$2', $route) ?: $route;
            $scenario['url'] = $this->routeWithQuery('comprehensive/' . strtolower($segment), ['layout' => $layoutKey]);
        }
        unset($scenario);

        return $scenarios;
    }
}
