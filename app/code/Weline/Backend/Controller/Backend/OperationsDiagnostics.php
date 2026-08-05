<?php

declare(strict_types=1);

namespace Weline\Backend\Controller\Backend;

use Weline\Backend\Service\OperationsDiagnosticsService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

final class OperationsDiagnostics extends BackendController
{
    public function __construct(private readonly OperationsDiagnosticsService $diagnostics)
    {
    }

    #[Acl(
        'Weline_Backend::commerce:operations:migration-diagnostics',
        '迁移诊断',
        'mdi-database-search-outline',
        '只读查看迁移克隆与检查点状态',
        'Weline_Backend::commerce:operations:group',
    )]
    public function migration(): string
    {
        $this->assign('diagnostics', $this->diagnostics->migration());
        $this->assign('page_title', __('迁移诊断'));
        return $this->fetch();
    }

    #[Acl(
        'Weline_Backend::commerce:operations:release-diagnostics',
        '发布诊断',
        'mdi-rocket-launch-outline',
        '只读查看发布环境与当前标记',
        'Weline_Backend::commerce:operations:group',
    )]
    public function release(): string
    {
        $this->assign('diagnostics', $this->diagnostics->release());
        $this->assign('page_title', __('发布诊断'));
        return $this->fetch();
    }
}
