<?php

declare(strict_types=1);

namespace Weline\DataTable\Controller\Backend\Test;

use Weline\Admin\Controller\BaseController;
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
        $focus = (string) $this->request->getParam('focus', '');

        $this->assign([
            'title' => (string) __('DataTable Tag Verification'),
            'dashboardUrl' => '../index',
            'comprehensiveUrl' => '../comprehensive/index',
            'docUrl' => '../index/doc',
            'verifyUrl' => '../comprehensive/verify-tags',
            'focusSection' => $focus,
            'report' => $this->backendAdminPageService->getTagVerificationReport(),
        ]);

        return (string) $this->fetchBase('Weline_DataTable::backend/templates/test/tag-test.phtml');
    }
}
