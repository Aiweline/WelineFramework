<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Deploy::deploy_release', '发布历史', 'mdi mdi-rocket-launch', '查看发布历史与版本信息', 'Weline_Backend::system_maintenance')]
class Release extends BackendController
{
    public function __construct(
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployReleaseRuntimeService $runtimeService,
    ) {
    }

    #[Acl('Weline_Deploy::deploy_release_index', '查看发布历史', 'mdi mdi-rocket-launch', '查看发布历史列表')]
    public function index(): string
    {
        $records = $this->historyService->getRecent(50);
        $current = $this->runtimeService->getCurrent();

        $this->assign('records', $records);
        $this->assign('current', $current);

        return (string)$this->fetch();
    }
}
