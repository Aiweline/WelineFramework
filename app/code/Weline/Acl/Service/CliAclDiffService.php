<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Framework\Manager\ObjectManager;

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
        private MenuXmlReader $menuReader,
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    public function beginCollection(): void
    {
        CollectedAclSourceIdsRegistry::clear();
    }

    public function cleanupAfterCollection(): int
    {
        $validSourceIds = array_flip(array_merge(
            $this->getCollectedMenuSourceIds(),
            CollectedAclSourceIdsRegistry::getAll()
        ));

        $orphanSourceIds = [];
        foreach ($this->getKnownSystemSourceIds() as $sourceId) {
            if (!isset($validSourceIds[$sourceId])) {
                $orphanSourceIds[] = $sourceId;
            }
        }

        return $this->aclOrphanCleanupService->cleanupBySourceIds($orphanSourceIds);
    }

    /**
     * @return string[]
     */
    private function getCollectedMenuSourceIds(): array
    {
        $moduleMenus = $this->menuReader->read();
        $sources = [];
        foreach ($moduleMenus as $menus) {
            foreach (($menus['data'] ?? []) as $menu) {
                $source = (string)($menu['source'] ?? '');
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }
        return $sources;
    }

    /**
     * @return string[]
     */
    private function getKnownSystemSourceIds(): array
    {
        /** @var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class);
        $field = Acl::schema_fields_ACL_ORIGIN;
        $rows = $acl->reset()
            ->whereRaw("({$field} IS NULL OR {$field} = '' OR {$field} != '" . addslashes(Acl::acl_origin_user) . "')")
            ->fields(Acl::schema_fields_SOURCE_ID)
            ->select()
            ->fetchArray();

        $sourceIds = [];
        foreach ($rows as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sourceId !== '') {
                $sourceIds[] = $sourceId;
            }
        }
        return $sourceIds;
    }
}
