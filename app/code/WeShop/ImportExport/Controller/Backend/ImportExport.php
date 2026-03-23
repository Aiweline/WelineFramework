<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Controller\Backend;

use Weline\Admin\Controller\BaseController;

class ImportExport extends BaseController
{
    public function index(): string
    {
        $this->assign([
            'title' => (string) __('Import / Export'),
            'productExportUrl' => $this->getBackendUrl('*/backend/import-export/export', ['entity' => 'products']),
            'orderExportUrl' => $this->getBackendUrl('*/backend/import-export/export', ['entity' => 'orders']),
            'productImportUrl' => $this->getBackendUrl('*/backend/import-export/import', ['entity' => 'products']),
        ]);

        return $this->fetchBase();
    }
}
