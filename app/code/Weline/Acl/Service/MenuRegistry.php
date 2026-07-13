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
            Acl::schema_fields_ROUTE => (string)($data['route'] ?? ''),
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
