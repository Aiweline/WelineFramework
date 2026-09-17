<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

/**
 * Data-only persistence boundary for compiled backend menu resources.
 *
 * Parsing and topology stay in the owning Backend module; ACL owns every
 * query and mutation against its resource schema.
 */
interface MenuRegistryInterface
{
    /** @param list<string> $modules */
    public function listManagedMenus(array $modules = []): array;

    /**
     * 受管菜单条数（type=menus、非用户手改）。用于升级跳过门禁：产物为空时禁止跳过。
     *
     * @param list<string> $modules
     */
    public function countManagedMenus(array $modules = []): int;

    /** @param list<string> $sourceIds */
    public function deleteManagedMenus(array $sourceIds): void;

    /** @param list<string> $sourceIds */
    public function disableManagedMenus(array $sourceIds): void;

    public function upsertManagedMenu(string $sourceId, array $data): void;

    /** 全局查询受管菜单 source 是否存在（跨模块父级校验用）。 */
    public function managedMenuExists(string $sourceId): bool;

    /**
     * 批量：给定 source_id 列表，返回其中在 ACL 受管菜单里真实存在的集合（读库对比）。
     *
     * @param list<string> $sourceIds
     * @return array<string, true>
     */
    public function filterExistingManagedSources(array $sourceIds): array;

    /**
     * 将受管菜单 source_id 从旧值迁到新值（含 parent_source 引用）。
     * 若新 id 已存在则删除旧行，否则原地改名。
     */
    public function renameManagedMenuSource(string $oldSourceId, string $newSourceId): void;

    /**
     * 模块受管菜单产物指纹（规范化字段排序后 sha256），供源指纹双条件跳过。
     */
    public function destinationFingerprint(string $module): string;

    /** @return list<string> */
    public function getManagedChildSources(string $parentSource): array;
}
