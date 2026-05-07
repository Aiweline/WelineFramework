<?php

declare(strict_types=1);

namespace WeShop\Inventory\Controller\Backend\Inventory;

use WeShop\Inventory\Service\SourceManagementService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Inventory::inventory_management', 'Inventory Management', 'mdi mdi-warehouse', 'Manage inventory', 'Weline_Backend::shop_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly SourceManagementService $sourceManagementService
    ) {
    }

    #[Acl('WeShop_Inventory::inventory_management_index', 'View inventory', 'mdi mdi-warehouse', 'View inventory management page')]
    public function index(): string
    {
        $this->assign([
            'title' => (string) __('Inventory Management'),
            'inventorySourcesUrl' => $this->getBackendUrl('*/backend/inventory/source'),
            'inventorySourceItemsUrl' => $this->getBackendUrl('*/backend/inventory/source-item'),
        ]);

        return (string) $this->fetchBase('WeShop_Inventory::backend/templates/inventory/dashboard.phtml');
    }
}
