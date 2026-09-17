<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Resource\MenuRegistryInterface;
use Weline\Acl\Model\Acl;

final class MenuRegistry implements MenuRegistryInterface
{
    public function __construct(
        private readonly Acl $aclModel,
    ) {
    }

    public function listManagedMenus(array $modules = []): array
    {
        $acl = $this->model();
        $acl->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=');
        if ($modules !== []) {
            $acl->where(Acl::schema_fields_MODULE, $modules, 'in');
        }
        return $acl->select()->fetchArray();
    }

    public function countManagedMenus(array $modules = []): int
    {
        try {
            $acl = $this->model();
            $acl->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=');
            if ($modules !== []) {
                $acl->where(Acl::schema_fields_MODULE, $modules, 'in');
            }

            return (int)$acl->total();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function deleteManagedMenus(array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }
        $this->model()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->delete()
            ->fetch();
    }

    public function disableManagedMenus(array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }
        $this->model()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->update([Acl::schema_fields_IS_ENABLE => 0])
            ->fetch();
    }

    public function upsertManagedMenu(string $sourceId, array $data): void
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            throw new \InvalidArgumentException('Menu source id cannot be empty.');
        }

        $row = [
            Acl::schema_fields_SOURCE_ID => $sourceId,
            Acl::schema_fields_SOURCE_NAME => (string)($data['title'] ?? $data['name'] ?? $sourceId),
            Acl::schema_fields_ROUTE => strtolower(trim((string)($data['route'] ?? ''), '/')),
            Acl::schema_fields_ROUTER => '',
            Acl::schema_fields_ICON => (string)($data['icon'] ?? ''),
            Acl::schema_fields_ORDER => (int)($data['order'] ?? 0),
            Acl::schema_fields_PARENT_SOURCE => (string)($data['parent_source'] ?? ''),
            Acl::schema_fields_MODULE => (string)($data['module'] ?? ''),
            Acl::schema_fields_CLASS => '',
            Acl::schema_fields_METHOD => 'GET',
            Acl::schema_fields_REWRITE => '',
            Acl::schema_fields_TYPE => Acl::type_MENUS,
            Acl::schema_fields_ACL_ORIGIN => Acl::acl_origin_menu_xml,
            Acl::schema_fields_ACCESS_MODE => (string)($data['access_mode'] ?? Acl::ACCESS_MODE_READ),
            Acl::schema_fields_SCOPE_GROUP => (string)($data['scope_group'] ?? ''),
            Acl::schema_fields_API_EXPOSABLE => (int)($data['api_exposable'] ?? 0),
            Acl::schema_fields_DOCUMENT => ($data['is_system'] ?? 0) ? __('系统菜单') : __('用户菜单'),
            Acl::schema_fields_IS_ENABLE => (int)($data['is_enable'] ?? 1),
            Acl::schema_fields_IS_BACKEND => (int)($data['is_backend'] ?? 1),
        ];

        $this->model()->setData($row)->save(true, Acl::schema_fields_SOURCE_ID);
    }

    public function managedMenuExists(string $sourceId): bool
    {
        $sourceId = \trim($sourceId);
        if ($sourceId === '') {
            return false;
        }

        return isset($this->filterExistingManagedSources([$sourceId])[$sourceId]);
    }

    public function filterExistingManagedSources(array $sourceIds): array
    {
        $wanted = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = \trim((string)$sourceId);
            if ($sourceId !== '') {
                $wanted[$sourceId] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        try {
            $rows = $this->model()
                ->where(Acl::schema_fields_SOURCE_ID, \array_keys($wanted), 'in')
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $found = [];
        foreach ($rows as $row) {
            $sid = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sid !== '' && isset($wanted[$sid])) {
                $found[$sid] = true;
            }
        }

        return $found;
    }

    public function renameManagedMenuSource(string $oldSourceId, string $newSourceId): void
    {
        $oldSourceId = \trim($oldSourceId);
        $newSourceId = \trim($newSourceId);
        if ($oldSourceId === '' || $newSourceId === '' || $oldSourceId === $newSourceId) {
            return;
        }

        // 先改写仍指向旧 id 的父子引用，避免迁 id 后孤儿 parent_source。
        $childSources = $this->getManagedChildSources($oldSourceId);
        if ($childSources !== []) {
            $this->model()
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
                ->where(Acl::schema_fields_PARENT_SOURCE, $oldSourceId)
                ->update([Acl::schema_fields_PARENT_SOURCE => $newSourceId])
                ->fetch();
        }

        $existingNew = $this->model()
            ->where(Acl::schema_fields_SOURCE_ID, $newSourceId)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->find()
            ->fetch();
        if ((string)$existingNew->getData(Acl::schema_fields_SOURCE_ID) === $newSourceId) {
            $this->deleteManagedMenus([$oldSourceId]);

            return;
        }

        // source_id 是主键/唯一键：不可 UPDATE 主键字段，改为读旧行 → upsert 新 id → 删旧。
        $oldRow = $this->model()
            ->where(Acl::schema_fields_SOURCE_ID, $oldSourceId)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->find()
            ->fetch();
        if ((string)$oldRow->getData(Acl::schema_fields_SOURCE_ID) !== $oldSourceId) {
            return;
        }

        $data = [
            'title' => (string)$oldRow->getData(Acl::schema_fields_SOURCE_NAME),
            'route' => (string)$oldRow->getData(Acl::schema_fields_ROUTE),
            'icon' => (string)$oldRow->getData(Acl::schema_fields_ICON),
            'order' => (int)$oldRow->getData(Acl::schema_fields_ORDER),
            'parent_source' => (string)$oldRow->getData(Acl::schema_fields_PARENT_SOURCE),
            'module' => (string)$oldRow->getData(Acl::schema_fields_MODULE),
            'access_mode' => (string)$oldRow->getData(Acl::schema_fields_ACCESS_MODE),
            'scope_group' => (string)$oldRow->getData(Acl::schema_fields_SCOPE_GROUP),
            'api_exposable' => (int)$oldRow->getData(Acl::schema_fields_API_EXPOSABLE),
            'is_enable' => (int)$oldRow->getData(Acl::schema_fields_IS_ENABLE),
            'is_backend' => (int)$oldRow->getData(Acl::schema_fields_IS_BACKEND),
            'is_system' => 1,
        ];
        $this->upsertManagedMenu($newSourceId, $data);
        $this->deleteManagedMenus([$oldSourceId]);
    }

    public function destinationFingerprint(string $module): string
    {
        $module = \trim($module);
        if ($module === '') {
            return \hash('sha256', '');
        }
        $rows = $this->listManagedMenus([$module]);
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = \implode('|', [
                (string)($row[Acl::schema_fields_SOURCE_ID] ?? ''),
                (string)($row[Acl::schema_fields_PARENT_SOURCE] ?? ''),
                (string)($row[Acl::schema_fields_ROUTE] ?? ''),
                (string)($row[Acl::schema_fields_ORDER] ?? '0'),
                (string)($row[Acl::schema_fields_IS_ENABLE] ?? '1'),
                (string)($row[Acl::schema_fields_ACCESS_MODE] ?? ''),
                (string)($row[Acl::schema_fields_SCOPE_GROUP] ?? ''),
                (string)($row[Acl::schema_fields_API_EXPOSABLE] ?? '0'),
                (string)($row[Acl::schema_fields_SOURCE_NAME] ?? ''),
                (string)($row[Acl::schema_fields_ICON] ?? ''),
            ]);
        }
        \sort($lines, \SORT_STRING);

        return \hash('sha256', \implode("\n", $lines));
    }

    public function getManagedChildSources(string $parentSource): array
    {
        $rows = $this->model()
            ->where(Acl::schema_fields_PARENT_SOURCE, $parentSource)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->select()
            ->fetchArray();
        $sources = [];
        foreach ($rows as $row) {
            $source = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($source !== '') {
                $sources[] = $source;
            }
        }
        return $sources;
    }

    private function model(): Acl
    {
        return (clone $this->aclModel)->reset()->clearData();
    }
}
