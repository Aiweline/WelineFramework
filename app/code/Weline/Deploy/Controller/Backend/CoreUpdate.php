<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Service\DeployCoreUpdateService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;

#[Acl('Weline_Deploy::release_management', '发布管理', 'mdi mdi-rocket-launch-outline', '发布控制、历史与核心更新', 'Weline_Backend::system_maintenance')]
class CoreUpdate extends BackendController
{
    public function __construct(
        private readonly DeployCoreUpdateService $coreUpdateService,
    ) {
    }

    #[Acl('Weline_Deploy::core_update', '核心更新', 'mdi mdi-update', 'Web 执行框架核心更新')]
    public function getIndex(): string
    {
        $context = $this->coreUpdateService->buildPageContext();
        $this->assign('pageContext', $context);
        $this->assign('runOutput', '');
        $this->assign('runUrl', $this->_url->getBackendUrl('deploy/backend/core-update/run'));

        return (string)$this->fetch();
    }

    #[Acl('Weline_Deploy::core_update_run', '执行核心更新', 'mdi mdi-update', '执行 update:core 命令')]
    public function postRun(): string
    {
        if (!$this->request->isPost()) {
            MessageManager::warning((string)__('请求方式错误。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/core-update'));
            return '';
        }

        $post = (array)$this->request->getPost();
        if ((string)($post['confirm_core_update'] ?? '0') !== '1') {
            MessageManager::warning((string)__('请先确认核心更新操作。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/core-update'));
            return '';
        }

        $branch = trim((string)($post['branch'] ?? ''));
        if ($branch === '') {
            $context = $this->coreUpdateService->buildPageContext();
            $branch = (string)($context['default_branch'] ?? 'dev');
        }

        $result = $this->coreUpdateService->run($branch);
        if (!empty($result['success'])) {
            $message = (string)($result['message'] ?? __('核心更新完成。'));
            if (!empty($result['backup_id'])) {
                $message .= ' ' . (string)__('备份编号：%{1}', [(string)$result['backup_id']]);
            }
            MessageManager::success($message);
        } else {
            MessageManager::error((string)($result['message'] ?? __('核心更新失败。')));
        }

        $this->assign('runOutput', (string)($result['output'] ?? ''));
        $this->assign('runResult', $result);
        $this->assign('pageContext', $this->coreUpdateService->buildPageContext());
        $this->assign('runUrl', $this->_url->getBackendUrl('deploy/backend/core-update/run'));

        return (string)$this->fetch('index');
    }
}
