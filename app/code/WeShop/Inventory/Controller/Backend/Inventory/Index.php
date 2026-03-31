<?php

declare(strict_types=1);

namespace WeShop\Inventory\Controller\Backend\Inventory;

use WeShop\Inventory\Service\SourceManagementService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly SourceManagementService $sourceManagementService
    ) {
    }

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
