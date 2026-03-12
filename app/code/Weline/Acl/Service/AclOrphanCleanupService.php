<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;

/**
 * 清理非用户创建的 ACL / 菜单残留。
 *
 * 适用场景：
 * - setup:upgrade 检测到模块已被删除或路径异常时，先按模块名清理残留
 * - 路由收集完成后，按无效 source_id 集合清理孤儿 ACL
 */
class AclOrphanCleanupService
{
    public function __construct(
        private Acl $acl,
        private RoleAccess $roleAccess
    ) {
    }

    /**
     * 按模块名清理非用户创建的 ACL / 菜单残留。
     *
     * @param string[] $moduleNames
     */
    public function cleanupByModules(array $moduleNames): int
    {
        $moduleNames = array_values(array_filter(array_unique($moduleNames)));
        if (empty($moduleNames)) {
            return 0;
        }

        $rows = $this->buildNonUserAclQuery()
            ->fields(Acl::schema_fields_SOURCE_ID . ',' . Acl::schema_fields_MODULE)
            ->select()
            ->fetchArray();

        $rows = array_values(array_filter($rows, static function (array $row) use ($moduleNames): bool {
            $module = (string)($row[Acl::schema_fields_MODULE] ?? '');
            if ($module !== '' && in_array($module, $moduleNames, true)) {
                return true;
            }

            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            foreach ($moduleNames as $moduleName) {
                if ($sourceId !== '' && str_starts_with($sourceId, $moduleName . '::')) {
                    return true;
                }
            }

            return false;
        }));

        return $this->cleanupByRows($rows);
    }

    /**
     * 按 source_id 清理非用户创建的 ACL / 菜单残留。
     *
     * @param string[] $sourceIds
     */
    public function cleanupBySourceIds(array $sourceIds): int
    {
        $sourceIds = array_values(array_filter(array_unique($sourceIds)));
        if (empty($sourceIds)) {
            return 0;
        }

        $rows = $this->buildNonUserAclQuery()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->fields(Acl::schema_fields_SOURCE_ID)
            ->select()
            ->fetchArray();

        return $this->cleanupByRows($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function cleanupByRows(array $rows): int
    {
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string)($row[Acl::schema_fields_SOURCE_ID] ?? ''),
            $rows
        )));

        if (empty($sourceIds)) {
            return 0;
        }

        $this->roleAccess->reset()
            ->where(RoleAccess::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->delete()
            ->fetch();

        $this->acl->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->delete()
            ->fetch();

        return count($sourceIds);
    }

    private function buildNonUserAclQuery(): QueryInterface
    {
        $field = Acl::schema_fields_ACL_ORIGIN;
        return $this->acl->reset()
            ->whereRaw("({$field} IS NULL OR {$field} = '' OR {$field} != '" . addslashes(Acl::acl_origin_user) . "')");
    }
}
