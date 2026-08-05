<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

/**
 * Canonical ACL source-id migrations shared by route and menu collection.
 *
 * Keeping the map outside either collector is important: menu-only collection
 * can delete retired menu resources without running route collection first.
 */
final class SourceIdRenameMap
{
    /** @var array<string,string> old => new Query resource migrations */
    public const QUERY = [
        'Weline_MediaManager::ai_draw' => 'Weline_MediaManager::query:ai_draw',
        'Weline_MediaManager::ai_draw_save' => 'Weline_MediaManager::query:ai_draw_save',
    ];

    /**
     * Retired menu/controller grants that must move to their canonical owner.
     * Containers are deliberately absent: a container grant is not a business
     * capability grant and must never be widened during migration.
     *
     * @var array<string,string> old => new menu/controller resource migrations
     */
    public const ROLE_ACCESS = [
        'Weline_Checkout::order_list' => 'Weline_Order::order_list',
        'Weline_Checkout::order_view' => 'Weline_Order::order_view',
        'Weline_Checkout::order_update_status' => 'Weline_Order::order_update_status',
        'Weline_Order::status_index' => 'Weline_Order::status_manage',
    ];
}
