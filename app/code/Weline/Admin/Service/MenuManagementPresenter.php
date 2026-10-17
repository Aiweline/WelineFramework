<?php
declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Acl\Model\Acl;

/**
 * Present ACL menu rows for the backend menu-management UI.
 *
 * ACL domain keys (acl_id / source_id / source_name / route) stay unchanged;
 * the admin tree + edit modal historically speak menu_id / source / title / action.
 */
final class MenuManagementPresenter
{
    /**
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    public function presentNode(array $menu): array
    {
        $children = [];
        if (!empty($menu['children']) && is_array($menu['children'])) {
            foreach ($menu['children'] as $child) {
                if (is_array($child)) {
                    $children[] = $this->presentNode($child);
                }
            }
        }

        $sourceName = (string)($menu[Acl::schema_fields_SOURCE_NAME] ?? $menu['title'] ?? $menu['name'] ?? '');
        $aclId = (int)($menu[Acl::schema_fields_ACL_ID] ?? $menu['menu_id'] ?? 0);
        $origin = (string)($menu[Acl::schema_fields_ACL_ORIGIN] ?? '');

        return [
            'menu_id' => $aclId,
            'acl_id' => $aclId,
            'name' => $sourceName,
            'title' => $sourceName,
            'source' => (string)($menu[Acl::schema_fields_SOURCE_ID] ?? $menu['source'] ?? ''),
            'source_id' => (string)($menu[Acl::schema_fields_SOURCE_ID] ?? $menu['source'] ?? ''),
            'parent_source' => (string)($menu[Acl::schema_fields_PARENT_SOURCE] ?? $menu['parent_source'] ?? ''),
            'action' => (string)($menu[Acl::schema_fields_ROUTE] ?? $menu['action'] ?? ''),
            'route' => (string)($menu[Acl::schema_fields_ROUTE] ?? $menu['action'] ?? ''),
            'module' => (string)($menu[Acl::schema_fields_MODULE] ?? $menu['module'] ?? ''),
            'icon' => (string)($menu[Acl::schema_fields_ICON] ?? $menu['icon'] ?? 'menu'),
            'order' => (int)($menu[Acl::schema_fields_ORDER] ?? $menu['order'] ?? 0),
            'is_enable' => (int)($menu[Acl::schema_fields_IS_ENABLE] ?? $menu['is_enable'] ?? 1),
            'is_system' => $this->isSystemOrigin($origin) || !empty($menu['is_system']),
            'acl_origin' => $origin,
            'has_children' => !empty($menu['has_children']) || $children !== [],
            'children' => $children,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function presentTree(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            if (is_array($node)) {
                $out[] = $this->presentNode($node);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $menu
     * @return array<string, mixed>
     */
    public function presentDetail(array $menu): array
    {
        $presented = $this->presentNode($menu);
        unset($presented['children'], $presented['has_children']);
        return $presented;
    }

    private function isSystemOrigin(string $origin): bool
    {
        $origin = strtolower(trim($origin));
        return $origin === 'menu_xml'
            || $origin === 'system'
            || $origin === 'module'
            || str_starts_with($origin, 'system');
    }
}
