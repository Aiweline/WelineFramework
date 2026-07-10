<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Deploy\Service\DeployReleaseControlService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Deploy::release_management', '发布管理', 'mdi mdi-rocket-launch-outline', '发布控制、历史与核心更新', 'Weline_Backend::system_maintenance')]
class ReleaseControl extends BackendController
{
    public function __construct(
        private readonly DeployReleaseControlService $controlService,
        private readonly DeployOrchestratorService $orchestrator,
    ) {
    }

    #[Acl('Weline_Deploy::release_control', '发布控制', 'mdi mdi-source-branch-sync', '查看 Git 历史并手动发布')]
    public function getIndex(): string
    {
        $context = $this->controlService->buildPageContext();
        $this->assign('pageContext', $context);
        $this->assign('current', $context['current'] ?? null);
        $this->assign('repoConfigured', !empty($context['repo_configured']));
        $this->assign('isProduction', !empty($context['is_production']));
        $this->assign('branchesUrl', $this->_url->getBackendUrl('deploy/backend/release-control/branches'));
        $this->assign('commitsUrl', $this->_url->getBackendUrl('deploy/backend/release-control/commits'));
        $this->assign('tagsUrl', $this->_url->getBackendUrl('deploy/backend/release-control/tags'));
        $this->assign('previewUrl', $this->_url->getBackendUrl('deploy/backend/release-control/preview'));
        $this->assign('runUrl', $this->_url->getBackendUrl('deploy/backend/release-control/run'));
        $this->assign('configUrl', $this->_url->getBackendUrl('deploy/backend/config'));

        return (string)$this->fetch();
    }

    #[Acl('Weline_Deploy::release_control', '发布控制', 'mdi mdi-source-branch-sync', '查看 Git 历史并手动发布')]
    public function getBranches(): string
    {
        try {
            $branches = $this->controlService->listRemoteBranches();
            return $this->fetchJson([
                'success' => true,
                'branches' => $branches,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'branches' => [],
            ]);
        }
    }

    #[Acl('Weline_Deploy::release_control', '发布控制', 'mdi mdi-source-branch-sync', '查看 Git 历史并手动发布')]
    public function getCommits(): string
    {
        $branch = trim((string)$this->request->getGet('branch', ''));
        $limit = max(1, min(100, (int)$this->request->getGet('limit', 50)));
        $offset = max(0, (int)$this->request->getGet('offset', 0));

        try {
            if ($branch === '') {
                throw new \InvalidArgumentException((string)__('请指定分支。'));
            }
            $commits = $this->controlService->listCommits($branch, $limit, $offset);
            return $this->fetchJson([
                'success' => true,
                'commits' => $commits,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'commits' => [],
            ]);
        }
    }

    #[Acl('Weline_Deploy::release_control', '发布控制', 'mdi mdi-source-branch-sync', '查看 Git 历史并手动发布')]
    public function getTags(): string
    {
        $limit = max(1, min(200, (int)$this->request->getGet('limit', 100)));

        try {
            $tags = $this->controlService->listTags($limit);
            return $this->fetchJson([
                'success' => true,
                'tags' => $tags,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
                'tags' => [],
            ]);
        }
    }

    #[Acl('Weline_Deploy::release_control_run', '执行发布', 'mdi mdi-rocket-launch', '从发布控制触发部署')]
    public function postPreview(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['success' => false, 'message' => (string)__('请求方式错误。')]);
        }

        $post = (array)$this->request->getPost();
        $refType = trim((string)($post['ref_type'] ?? ''));
        $ref = trim((string)($post['ref'] ?? ''));

        try {
            if ($refType === '' || $ref === '') {
                throw new \InvalidArgumentException((string)__('发布参数不完整。'));
            }
            $preview = $this->controlService->previewRelease($refType, $ref);
            return $this->fetchJson([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Deploy::release_control_run', '执行发布', 'mdi mdi-rocket-launch', '从发布控制触发部署')]
    public function postRun(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson(['success' => false, 'message' => (string)__('请求方式错误。')]);
        }

        $post = (array)$this->request->getPost();
        $refType = trim((string)($post['ref_type'] ?? ''));
        $ref = trim((string)($post['ref'] ?? ''));
        $branch = trim((string)($post['branch'] ?? ''));
        $confirmRelease = (string)($post['confirm_release'] ?? '0') === '1';
        $confirmOlder = (string)($post['confirm_older_version'] ?? '0') === '1';

        if (!$confirmRelease) {
            return $this->fetchJson(['success' => false, 'message' => (string)__('请先确认发布操作。')]);
        }

        try {
            if ($refType === '' || $ref === '') {
                throw new \InvalidArgumentException((string)__('发布参数不完整。'));
            }

            $preview = $this->controlService->previewRelease($refType, $ref);
            if (!empty($preview['requires_older_confirm']) && !$confirmOlder) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => (string)__('发布到更旧版本需要额外确认。'),
                    'requires_older_confirm' => true,
                ]);
            }

            $params = $this->controlService->buildReleaseParams($refType, $ref, $branch !== '' ? $branch : null);
            $result = $this->orchestrator->release($params);

            if (!empty($result['success'])) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => (string)__('发布成功：%{1}', [(string)($result['deploy_version'] ?? '')]),
                    'result' => $result,
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('发布失败。')),
                'result' => $result,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
