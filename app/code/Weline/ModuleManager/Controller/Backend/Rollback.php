<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Controller\Backend;

use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Database\Api\ModuleRollbackManagerInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_ModuleManager::module_rollback',
    '模块版本回滚',
    'mdi-backup-restore',
    '预检、执行并查看模块代码与数据库联动回滚',
    'Weline_ModuleManager::module-manager'
)]
final class Rollback extends BackendController
{
    public function __construct(
        private readonly ModuleRollbackManagerInterface $rollbackManager,
        private readonly BackendUserContextProviderInterface $userContextProvider,
    ) {
    }

    #[Acl('Weline_ModuleManager::module_rollback_targets', '查看回滚目标', 'mdi-format-list-bulleted', '查看可用目标版本')]
    public function getTargets(): string
    {
        $module = trim((string)$this->request->getParam('module_name', ''));
        return $this->fetchJson(['success' => true, 'items' => $this->rollbackManager->listTargets($module)]);
    }

    #[Acl('Weline_ModuleManager::module_rollback_plan', '创建回滚预检', 'mdi-clipboard-text-search', '生成只读级联回滚计划')]
    public function postPlan(): string
    {
        $plan = $this->rollbackManager->createPlan(
            trim((string)$this->request->getParam('module_name', '')),
            trim((string)$this->request->getParam('target_version', '')),
        );
        return $this->fetchJson(['success' => true, 'plan' => $plan]);
    }

    #[Acl('Weline_ModuleManager::module_rollback_start', '执行模块回滚', 'mdi-play-circle', '启动已确认的持久化回滚任务')]
    public function postStart(): string
    {
        $actor = $this->userContextProvider->current();
        if (!$actor instanceof BackendUserContext || !$actor->getIsEnabled()) {
            throw new \RuntimeException(__('后台操作员不可用'));
        }
        $planId = trim((string)$this->request->getParam('plan_id', ''));
        $operation = $this->rollbackManager->getOperation($planId);
        $plan = (array)($operation['plan'] ?? []);
        if (trim((string)$this->request->getParam('confirm_module', '')) !== (string)($plan['root_module'] ?? '')
            || trim((string)$this->request->getParam('confirm_version', '')) !== (string)($plan['target_version'] ?? '')) {
            throw new \RuntimeException(__('确认的根模块或目标版本与预检计划不一致'));
        }
        $result = $this->rollbackManager->start(
            $planId,
            trim((string)$this->request->getParam('plan_hash', '')),
            $actor->getUsername() . '#' . $actor->getId(),
        );
        return $this->fetchJson($result);
    }

    #[Acl('Weline_ModuleManager::module_rollback_operation', '查看回滚任务', 'mdi-progress-clock', '查看回滚阶段、备份和补偿结果')]
    public function getOperation(): string
    {
        $operationId = trim((string)$this->request->getParam('operation_id', ''));
        return $this->fetchJson(['success' => true, 'operation' => $this->rollbackManager->getOperation($operationId)]);
    }
}
