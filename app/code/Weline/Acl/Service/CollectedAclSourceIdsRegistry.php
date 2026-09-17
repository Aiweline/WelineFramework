<?php
declare(strict_types=1);

namespace Weline\Acl\Service;

/**
 * 路由收集阶段 ControllerAttributes 写入的 source_id  registry。
 * 供 after_route_collection 的 ACL diff 使用：孤儿 = 不在（收集到的菜单 ∪ 收集到的 ACL）中。
 */
class CollectedAclSourceIdsRegistry
{
    /** @var array<string, true> */
    private static array $sourceIds = [];

    public static function add(string ...$sourceIds): void
    {
        self::addMany($sourceIds);
    }

    /**
     * @param list<string> $sourceIds
     */
    public static function addMany(array $sourceIds): void
    {
        foreach ($sourceIds as $id) {
            $id = \trim((string)$id);
            if ($id !== '') {
                self::$sourceIds[$id] = true;
            }
        }
    }

    /** @return list<string> */
    public static function getAll(): array
    {
        return array_keys(self::$sourceIds);
    }

    public static function clear(): void
    {
        self::$sourceIds = [];
    }
}
