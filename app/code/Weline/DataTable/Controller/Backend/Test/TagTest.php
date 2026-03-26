<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Admin\Controller\BaseController;
use Weline\DataTable\Controller\Backend\Test\Concern\HandlesBackendLayouts;
use Weline\DataTable\Service\BackendAdminPageService;
use Weline\Framework\Acl\Acl;

#[Acl(
    'Weline_DataTable::datatable_test_tag',
    '标签验证',
    'mdi mdi-tag-check-outline',
    'DataTable 标签验证页面',
    'Weline_DataTable::datatable_module'
)]
class TagTest extends BaseController
{
    use HandlesBackendLayouts;

    public function __construct(
        private readonly BackendAdminPageService $backendAdminPageService
    ) {
    }

    #[Acl(
        'Weline_DataTable::test_tag_test_index',
        '标签验证页',
        'mdi mdi-check-decagram-outline',
        'DataTable 标签验证结果页'
    )]
    public function index(): string
    {
        $currentLayoutKey = $this->applyBackendLayout();
        $focus = (string) $this->request->getParam('focus', '');

        $this->assign([
            'title' => (string) __('DataTable Tag Verification'),
            'currentLayout' => $currentLayoutKey,
            'layoutOptions' => $this->buildBackendLayoutOptions('index', $currentLayoutKey, true, ['focus' => $focus]),
            'layoutSwitcherTitle' => (string) __('Verification Layout'),
            'layoutSwitcherDescription' => (string) __('Validate the tag verification report inside the same backend layout variants used by the dashboard and demo routes.'),
            'dashboardUrl' => $this->routeWithQuery('../index', ['layout' => $currentLayoutKey]),
            'comprehensiveUrl' => $this->routeWithQuery('../comprehensive/index', ['layout' => $currentLayoutKey]),
            'docUrl' => $this->routeWithQuery('../index/doc', ['layout' => $currentLayoutKey]),
            'verifyUrl' => '../comprehensive/verify-tags',
            'focusSection' => $focus,
            'report' => $this->backendAdminPageService->getTagVerificationReport(),
        ]);

        return (string) $this->fetchBase('Weline_DataTable::backend/templates/test/tag-test.phtml');
    }
}
