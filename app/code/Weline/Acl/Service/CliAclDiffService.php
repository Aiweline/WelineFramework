<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Framework\App\Env;

/**
 * CLI 生命周期内的 ACL diff 服务。
 *
 * 用法：
 * - 路由收集开始前清空本轮收集 registry
 * - ControllerAttributes 在收集过程中持续写入 registry
 * - 路由收集结束后直接在 CLI 流程内做差集清理
 */
class CliAclDiffService
{
    public function __construct(
        private MenuSourceIds $menuSourceIds,
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    public function beginCollection(): void
    {
        CollectedAclSourceIdsRegistry::clear();
    }

    public function cleanupAfterCollection(): int
    {
        $validSourceIds = array_merge(
            $this->getCollectedMenuSourceIds(),
            CollectedAclSourceIdsRegistry::getAll()
        );
        $activeModules = array_keys(Env::getInstance()->getActiveModules());
        return $this->aclOrphanCleanupService->cleanupByActiveModules($activeModules, $validSourceIds);
    }

    /**
     * @return string[]
     */
    private function getCollectedMenuSourceIds(): array
    {
        return $this->menuSourceIds->all();
    }

}
