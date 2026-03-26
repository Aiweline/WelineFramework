<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Admin\Controller\BaseController;
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
        $this->layoutType = 'default.blank';

        $dashboardData = $this->backendAdminPageService->getDashboardData();
        $dashboardData['scenarios'] = $this->appendScenarioUrls($dashboardData['scenarios'] ?? []);

        $this->assign(array_merge(
            [
                'title' => (string) __('DataTable Backend Dashboard'),
                'dashboardUrl' => 'index',
                'comprehensiveUrl' => 'comprehensive/index',
                'tagTestUrl' => 'tag-test/index',
                'docUrl' => 'index/doc',
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
        $selectedDoc = (string) $this->request->getParam('doc', 'quickstart');

        $this->assign(array_merge(
            [
                'title' => (string) __('DataTable Documentation'),
                'dashboardUrl' => '../index',
                'comprehensiveUrl' => '../comprehensive/index',
                'tagTestUrl' => '../tag-test/index',
                'docUrl' => 'doc',
            ],
            $this->backendAdminPageService->getDocumentationPageData($selectedDoc)
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
    private function appendScenarioUrls(array $scenarios): array
    {
        foreach ($scenarios as &$scenario) {
            $route = (string) ($scenario['route'] ?? 'index');
            $segment = preg_replace('/([a-z])([A-Z])/', '$1-$2', $route) ?: $route;
            $scenario['url'] = 'comprehensive/' . strtolower($segment);
        }
        unset($scenario);

        return $scenarios;
    }
}
