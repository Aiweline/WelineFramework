<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Controller\Backend\ImportExport;

use WeShop\ImportExport\Service\ImportExportService;
use Weline\Admin\Controller\BaseController;

class Import extends BaseController
{
    public function __construct(
        private readonly ImportExportService $importExportService
    ) {
    }

    public function post(): string
    {
        try {
            $entity = strtolower((string) $this->request->getParam('entity', 'products'));
            if ($entity !== 'products') {
                throw new \InvalidArgumentException((string) __('Only product CSV import is supported in this slice.'));
            }

            $file = $this->request->getFile('csv_file') ?: $this->request->getFile('file');
            $filePath = is_array($file) ? (string) ($file['tmp_name'] ?? '') : '';
            if ($filePath === '') {
                throw new \InvalidArgumentException((string) __('Please upload a CSV file.'));
            }

            return $this->fetchJson([
                'code' => 200,
                'msg' => (string) __('Import completed.'),
                'data' => $this->importExportService->importProducts($filePath),
            ]);
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Import failed.'));
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
