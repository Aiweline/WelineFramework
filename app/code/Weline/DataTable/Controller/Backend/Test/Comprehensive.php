<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\DataTable\Controller\Backend\Test\Concern\HandlesBackendLayouts;
use Weline\DataTable\Service\BackendAdminPageService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_DataTable::datatable_test_comprehensive',
    'DataTable 综合测试',
    'beaker',
    'DataTable 综合测试页面',
    'Weline_DataTable::datatable_module'
)]
class Comprehensive extends BackendController
{
    use HandlesBackendLayouts;

    private const FRONTEND_TEMPLATE_BASE = 'Weline_DataTable::templates/frontend/test/';

    public function __construct(
        private readonly BackendAdminPageService $backendAdminPageService
    ) {
    }

    #[Acl(
        'Weline_DataTable::test_comprehensive_index',
        '综合测试首页',
        'grid',
        'DataTable 综合测试导航页'
    )]
    public function index(): string
    {
        $this->layoutType = 'default.blank';
        $currentLayoutKey = $this->resolveBackendLayoutKey(true, 'default');
        $dashboardData = $this->backendAdminPageService->getDashboardData();
        $dashboardData['scenarios'] = $this->appendScenarioUrls($this->backendAdminPageService->getScenarioCatalog(), $currentLayoutKey);

        $this->assign(array_merge(
            $dashboardData,
            [
                'title' => (string) __('DataTable Comprehensive Test'),
                'currentLayout' => $currentLayoutKey,
                'layoutOptions' => $this->buildBackendLayoutOptions('index', $currentLayoutKey),
                'layoutSwitcherTitle' => (string) __('Demo Route Layout'),
                'layoutSwitcherDescription' => (string) __('This entry page stays on blank layout by design. The options below change how backend demo and compatibility routes open.'),
                'dashboardUrl' => $this->routeWithQuery('../index', ['layout' => $currentLayoutKey]),
                'docUrl' => $this->routeWithQuery('../index/doc', ['layout' => $currentLayoutKey]),
                'tagTestUrl' => $this->routeWithQuery('../tag-test/index', ['layout' => $currentLayoutKey]),
                'frontendDemoUrl' => $this->getUrl('datatable/test'),
                'verifyTagsUrl' => $this->_url->getBackendUrl('datatable/backend/test/comprehensive/verify-tags'),
            ]
        ));

        return (string) $this->fetch('Weline_DataTable::templates/Test/Comprehensive/index.phtml');
    }

    #[Acl('Weline_DataTable::test_comprehensive_basic', '基础表格', 'table', '基础表格测试')]
    public function basic(): string
    {
        return $this->renderDemoPage('basic', 'Basic Table Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_join', '关联查询', 'link', '多模型关联测试')]
    public function join(): string
    {
        return $this->renderDemoPage('join', 'Joined Table Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_multi_model', '多模型查询', 'table', '兼容旧路由的 JOIN 测试')]
    public function multiModel(): string
    {
        return $this->renderDemoPage('join', 'Multi-model Query Demo', 'multiModel');
    }

    #[Acl('Weline_DataTable::test_comprehensive_form', '独立表单', 'edit', '独立表单测试')]
    public function form(): string
    {
        return $this->renderDemoPage('form', 'Standalone Form Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_upload', '上传字段', 'upload', '上传与字段类型测试')]
    public function upload(): string
    {
        return $this->renderDemoPage('upload', 'Upload Field Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_field_types', '字段类型兼容页', 'edit', '兼容旧路由的字段类型测试')]
    public function fieldTypes(): string
    {
        return $this->renderDemoPage('upload', 'Field Types Demo', 'fieldTypes');
    }

    #[Acl('Weline_DataTable::test_comprehensive_transaction', '事务联动', 'database', '事务保存测试')]
    public function transaction(): string
    {
        return $this->renderDemoPage('transaction', 'Transaction Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_dependency', '依赖顺序', 'branch', '依赖顺序保存测试')]
    public function dependency(): string
    {
        return $this->renderDemoPage('dependency', 'Dependency Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_cascade', '级联删除', 'trash', '级联删除测试')]
    public function cascade(): string
    {
        return $this->renderDemoPage('cascade', 'Cascade Delete Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_performance', '自动生成', 'chart', '自动生成和性能测试')]
    public function performance(): string
    {
        return $this->renderDemoPage('performance', 'Performance Demo');
    }

    #[Acl('Weline_DataTable::test_comprehensive_auto_generation', '自动生成兼容页', 'sparkles', '兼容旧路由的自动生成测试')]
    public function autoGeneration(): string
    {
        return $this->renderDemoPage('performance', 'Auto Generation Demo', 'autoGeneration');
    }

    #[Acl('Weline_DataTable::test_comprehensive_filter', '过滤搜索', 'filter', '兼容旧路由的过滤测试')]
    public function filter(): string
    {
        return $this->renderDemoPage('basic', 'Filter Demo', 'filter');
    }

    #[Acl('Weline_DataTable::test_comprehensive_sorting', '排序分页', 'sort', '兼容旧路由的排序分页测试')]
    public function sorting(): string
    {
        return $this->renderDemoPage('basic', 'Sorting and Pagination Demo', 'sorting');
    }

    #[Acl('Weline_DataTable::test_comprehensive_crud', 'CRUD 测试', 'edit', '兼容旧路由的 CRUD 测试')]
    public function crud(): string
    {
        return $this->renderDemoPage('basic', 'CRUD Demo', 'crud');
    }

    #[Acl('Weline_DataTable::test_comprehensive_inheritance', '属性继承', 'stack', '属性继承验证页')]
    public function inheritance(): string
    {
        $currentLayoutKey = $this->applyBackendLayout();

        return (string) $this->template(
            'Weline_DataTable::templates/Test/Comprehensive/inheritance.phtml',
            [
                'title' => (string) __('Attribute Inheritance Verification'),
                'currentLayout' => $currentLayoutKey,
                'layoutOptions' => $this->buildBackendLayoutOptions('inheritance', $currentLayoutKey),
                'layoutSwitcherTitle' => (string) __('Verification Page Layout'),
                'layoutSwitcherDescription' => (string) __('Check this focused compatibility page inside different backend layout shells without changing the verification logic itself.'),
                'dashboardUrl' => $this->routeWithQuery('../index', ['layout' => $currentLayoutKey]),
                'comprehensiveUrl' => $this->routeWithQuery('index', ['layout' => $currentLayoutKey]),
                'tagTestUrl' => $this->routeWithQuery('../tag-test/index', ['layout' => $currentLayoutKey]),
                'verifyTagsUrl' => $this->_url->getBackendUrl('datatable/backend/test/comprehensive/verify-tags'),
            ]
        );
    }

    #[Acl('Weline_DataTable::test_comprehensive_verify_tags', '标签验证接口', 'check-circle', '标签验证 JSON 接口')]
    public function verifyTags(): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);

        return $response->renderJson([
            'success' => true,
            'message' => (string) __('Tag verification completed.'),
            'data' => $this->backendAdminPageService->getTagVerificationReport(),
        ]);
    }

    private function renderDemoPage(string $templateKey, string $pageTitle, ?string $pageKey = null): string
    {
        $currentLayoutKey = $this->applyBackendLayout();
        $pageKey = $pageKey ?: $templateKey;
        $currentRoute = $this->toRouteSegment((string) $this->request->getRouterData('class/method'));

        return (string) $this->template(
            self::FRONTEND_TEMPLATE_BASE . $templateKey . '.phtml',
            [
                'page_title' => (string) __($pageTitle),
                'page_key' => $pageKey,
                'currentLayout' => $currentLayoutKey,
                'layoutOptions' => $this->buildBackendLayoutOptions($currentRoute, $currentLayoutKey),
                'layoutSwitcherTitle' => (string) __('Demo Page Layout'),
                'layoutSwitcherDescription' => (string) __('Use these layout variants to confirm the backend-hosted demo page still behaves correctly outside the blank compatibility entry.'),
                'demo_links' => $this->buildBackendDemoLinks($currentLayoutKey),
            ]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $scenarios
     * @return array<int,array<string,mixed>>
     */
    private function appendScenarioUrls(array $scenarios, string $layoutKey): array
    {
        $result = [];
        foreach ($scenarios as $scenario) {
            $route = (string) ($scenario['route'] ?? 'index');
            $scenario['url'] = $this->routeWithQuery($this->toRouteSegment($route), ['layout' => $layoutKey]);
            $result[] = $scenario;
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function buildBackendDemoLinks(string $layoutKey): array
    {
        return [
            'index' => $this->routeWithQuery('index', ['layout' => $layoutKey]),
            'basic' => $this->routeWithQuery('basic', ['layout' => $layoutKey]),
            'join' => $this->routeWithQuery('join', ['layout' => $layoutKey]),
            'form' => $this->routeWithQuery('form', ['layout' => $layoutKey]),
            'upload' => $this->routeWithQuery('upload', ['layout' => $layoutKey]),
            'transaction' => $this->routeWithQuery('transaction', ['layout' => $layoutKey]),
            'dependency' => $this->routeWithQuery('dependency', ['layout' => $layoutKey]),
            'cascade' => $this->routeWithQuery('cascade', ['layout' => $layoutKey]),
            'performance' => $this->routeWithQuery('performance', ['layout' => $layoutKey]),
        ];
    }

    private function toRouteSegment(string $value): string
    {
        $segment = preg_replace('/([a-z])([A-Z])/', '$1-$2', $value) ?: $value;
        return strtolower($segment);
    }

}
