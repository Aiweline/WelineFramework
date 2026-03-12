<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Service\AclOrphanCleanupService;
use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 路由收集后执行 ACL 孤儿 diff：删除不在「收集到的菜单 ∪ 收集到的 ACL」中的记录。
 * 仅处理非用户创建的（acl_origin 为 NULL、空或 != 'user'），用户创建的不删除。
 */
class AfterRouteCollectionAclDiff implements ObserverInterface
{
    public function __construct(
        private MenuXmlReader $menuReader,
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $validMenuSourceIds = $this->getCollectedMenuSourceIds();
        $validAclSourceIds = CollectedAclSourceIdsRegistry::getAll();
        $validSourceIds = array_flip(array_merge($validMenuSourceIds, $validAclSourceIds));

        $orphanSourceIds = [];
        foreach ($this->getKnownSystemSourceIds() as $sourceId) {
            if (!isset($validSourceIds[$sourceId])) {
                $orphanSourceIds[] = $sourceId;
            }
        }

        $this->aclOrphanCleanupService->cleanupBySourceIds($orphanSourceIds);
    }

    /**
     * 从 menu.xml 收集到的菜单 source_id 列表
     *
     * @return string[]
     */
    private function getCollectedMenuSourceIds(): array
    {
        $moduleMenus = $this->menuReader->read();
        $sources = [];
        foreach ($moduleMenus as $menus) {
            $data = $menus['data'] ?? [];
            foreach ($data as $menu) {
                $source = $menu['source'] ?? '';
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }
        return $sources;
    }

    /**
     * 返回当前库里所有非用户创建 ACL / 菜单的 source_id。
     *
     * 通过清理服务按 source_id 执行最终删除；这里仅负责构建“候选全集”。
     *
     * @return string[]
     */
    private function getKnownSystemSourceIds(): array
    {
        // 复用服务的非用户 ACL 过滤能力，通过全表 source_id 集合获得候选全集
        // 这里不直接访问数据库，避免重复维护 acl_origin 的兼容判断逻辑。
        $sourceIds = [];
        // 利用 cleanupBySourceIds 的过滤语义要求，候选集必须来自现存 ACL 记录。
        // 当前 observer 仍需查询数据库，因此保留最小范围的 ObjectManager 访问并在服务层执行删除。
        /** @var \Weline\Acl\Model\Acl $acl */
        $acl = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Acl\Model\Acl::class);
        $field = \Weline\Acl\Model\Acl::schema_fields_ACL_ORIGIN;
        $rows = $acl->reset()
            ->whereRaw("({$field} IS NULL OR {$field} = '' OR {$field} != '" . addslashes(\Weline\Acl\Model\Acl::acl_origin_user) . "')")
            ->fields(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID)
            ->select()
            ->fetchArray();
        foreach ($rows as $row) {
            $sourceId = (string)($row[\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sourceId !== '') {
                $sourceIds[] = $sourceId;
            }
        }
        return $sourceIds;
    }
}
