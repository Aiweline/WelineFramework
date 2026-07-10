<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Model\DeployRelease;
use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Deploy\Service\DeployReleaseControlService;
use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;

#[Acl('Weline_Deploy::deploy_release', '发布历史', 'mdi mdi-history', '查看发布历史与版本回滚', 'Weline_Deploy::release_management')]
class Release extends BackendController
{
    public function __construct(
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployOrchestratorService $orchestrator,
        private readonly DeployReleaseControlService $controlService,
    ) {
    }

    #[Acl('Weline_Deploy::deploy_release_index', '查看发布历史', 'mdi mdi-history', '查看发布历史列表')]
    public function index(): string
    {
        return $this->getIndex();
    }

    #[Acl('Weline_Deploy::deploy_release_index', '查看发布历史', 'mdi mdi-history', '查看发布历史列表')]
    public function getIndex(): string
    {
        $records = $this->historyService->getRecent(50);
        $current = $this->runtimeService->getCurrent();

        $this->assign('records', $records);
        $this->assign('current', $current);
        $this->assign('rollbackUrl', $this->_url->getBackendUrl('deploy/backend/release/rollback'));
        $this->assign('isProduction', $this->controlService->isProductionSite());

        return (string)$this->fetch();
    }

    #[Acl('Weline_Deploy::release_history_rollback', '回滚发布版本', 'mdi mdi-restore', '从历史记录回滚版本')]
    public function postRollback(): string
    {
        if (!$this->request->isPost()) {
            MessageManager::warning((string)__('请求方式错误。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        $post = (array)$this->request->getPost();
        $releaseId = trim((string)($post['release_id'] ?? ''));
        $confirmRollback = (string)($post['confirm_rollback'] ?? '0') === '1';
        $confirmOlder = (string)($post['confirm_older_version'] ?? '0') === '1';

        if (!$confirmRollback) {
            MessageManager::warning((string)__('请先勾选确认框后再执行回滚。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        if ($releaseId === '') {
            MessageManager::error((string)__('缺少发布记录 ID。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        $record = $this->historyService->findById($releaseId);
        if (!$record instanceof DeployRelease) {
            MessageManager::error((string)__('发布记录不存在。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        if ((string)$record->getData(DeployRelease::schema_fields_STATUS) !== 'success') {
            MessageManager::error((string)__('只能回滚成功的发布记录。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        if ((int)$record->getData(DeployRelease::schema_fields_IS_CURRENT) === 1) {
            MessageManager::warning((string)__('当前版本无需回滚。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        $rollbackRef = $this->buildRollbackRef($record);
        if ($rollbackRef === '') {
            MessageManager::error((string)__('无法解析回滚参考。'));
            $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
            return '';
        }

        $gitCommit = trim((string)$record->getData(DeployRelease::schema_fields_GIT_COMMIT));
        $currentSha = $this->controlService->resolveCurrentCommitSha();
        if ($gitCommit !== '' && $currentSha !== '' && $gitCommit !== $currentSha) {
            $preview = $this->controlService->previewRelease('commit', $gitCommit);
            if (!empty($preview['requires_older_confirm']) && !$confirmOlder) {
                MessageManager::warning((string)__('回滚到更旧版本需要额外确认。'));
                $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
                return '';
            }
        }

        $params = $this->controlService->buildReleaseParams('commit', $gitCommit !== '' ? $gitCommit : $rollbackRef);
        $result = $this->orchestrator->rollback([
            'rollback_ref' => $rollbackRef,
            'config' => $params['config'],
            'no_backup' => $params['no_backup'],
            'backup_trigger' => 'rollback',
        ]);

        if (!empty($result['success'])) {
            MessageManager::success((string)__('回滚成功：%{1}', [(string)($result['deploy_version'] ?? '')]));
        } else {
            MessageManager::error((string)($result['message'] ?? __('回滚失败。')));
        }

        $this->redirect($this->_url->getBackendUrl('deploy/backend/release'));
        return '';
    }

    private function buildRollbackRef(DeployRelease $record): string
    {
        $refType = (string)$record->getData(DeployRelease::schema_fields_GIT_REF_TYPE);
        $gitRef = trim((string)$record->getData(DeployRelease::schema_fields_GIT_REF));
        $gitTag = trim((string)$record->getData(DeployRelease::schema_fields_GIT_TAG));
        $gitCommit = trim((string)$record->getData(DeployRelease::schema_fields_GIT_COMMIT));

        if ($refType === 'tag') {
            if ($gitTag !== '') {
                return str_starts_with($gitTag, 'refs/tags/') ? $gitTag : 'refs/tags/' . $gitTag;
            }
            if ($gitRef !== '') {
                return str_starts_with($gitRef, 'refs/tags/') ? $gitRef : 'refs/tags/' . $gitRef;
            }
        }

        if ($refType === 'branch' && $gitRef !== '') {
            return str_starts_with($gitRef, 'refs/heads/') ? $gitRef : 'refs/heads/' . $gitRef;
        }

        if ($gitCommit !== '') {
            return $gitCommit;
        }

        return $gitRef;
    }
}
