<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\App\Env;

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
     * 依据当前激活模块与本轮已收集 source_id，清理不存在于激活模块中的非用户 ACL / 菜单，并清理当前激活模块内未参与本轮收集的失效资源。
     *
     * @param string[] $activeModules
     * @param string[] $activeSourceIds
     */
    public function cleanupByActiveModules(array $activeModules = [], array $activeSourceIds = []): int
    {
        if (empty($activeModules)) {
            $activeModules = array_keys(Env::getInstance()->getActiveModules());
        }

        $activeModules = array_values(array_filter(array_unique(array_map('strval', $activeModules))));
        $activeSourceIds = array_values(array_filter(array_unique(array_map('strval', $activeSourceIds))));
        $activeModuleSet = array_flip($activeModules);
        $activeSourceIdSet = array_flip($activeSourceIds);

        $rows = $this->buildNonUserAclQuery()
            ->fields(Acl::schema_fields_SOURCE_ID . ',' . Acl::schema_fields_MODULE)
            ->select()
            ->fetchArray();

        $orphanRows = [];
        foreach ($rows as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sourceId === '') {
                continue;
            }

            $module = (string)($row[Acl::schema_fields_MODULE] ?? '');
            $belongsActiveModule = $module !== '' && isset($activeModuleSet[$module]);
            if (!$belongsActiveModule && $sourceId !== '') {
                $parts = explode('::', $sourceId, 2);
                if (isset($parts[0]) && isset($activeModuleSet[$parts[0]])) {
                    $belongsActiveModule = true;
                }
            }

            if (!$belongsActiveModule) {
                $orphanRows[] = ['source_id' => $sourceId];
                continue;
            }

            if (!isset($activeSourceIdSet[$sourceId])) {
                $orphanRows[] = ['source_id' => $sourceId];
            }
        }

        return $this->cleanupByRows($orphanRows);
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

    private function buildNonUserAclQuery(): Acl
    {
        $field = Acl::schema_fields_ACL_ORIGIN;
        return $this->acl->reset()
            ->where($field, '', '=', 'OR')
            ->where($field, Acl::acl_origin_user, '!=');
    }
}
