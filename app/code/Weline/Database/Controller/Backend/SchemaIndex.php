<?php

declare(strict_types=1);

namespace Weline\Database\Controller\Backend;

use Weline\Database\Service\Index\SchemaIndexAdminService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;

#[Acl(
    'Weline_Database::database_admin_index',
    '索引管理',
    'list',
    '查看异步 schema 索引开关、待索引与索引状态',
    'Weline_Database::database_admin'
)]
final class SchemaIndex extends BackendPageController
{
    public function __construct(
        private readonly SchemaIndexAdminService $adminService,
    ) {
    }

    #[Acl(
        'Weline_Database::database_admin_index',
        '索引管理',
        'list',
        '查看异步 schema 索引开关、待索引与索引状态',
        'Weline_Database::database_admin'
    )]
    public function index(): string
    {
        $summary = $this->adminService->summary();
        $this->assign([
            'title' => (string)__('索引管理'),
            'summary' => $summary,
            'jobs' => $this->adminService->jobs(),
            'states' => $this->adminService->states(),
        ]);

        return (string)$this->fetch('Weline_Database::backend/templates/schema-index/index.phtml');
    }
}
