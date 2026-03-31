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
            'productExportUrl' => $this->getUrl('*/backend/import-export/export', ['entity' => 'products']),
            'orderExportUrl' => $this->getUrl('*/backend/import-export/export', ['entity' => 'orders']),
            'productImportUrl' => $this->getUrl('*/backend/import-export/import', ['entity' => 'products']),
        ]);

        return $this->fetchBase();
    }
}
