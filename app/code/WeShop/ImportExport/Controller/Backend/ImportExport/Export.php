<?php

declare(strict_types=1);

namespace WeShop\ImportExport\Controller\Backend\ImportExport;

use WeShop\ImportExport\Service\ImportExportService;
use Weline\Admin\Controller\BaseController;

class Export extends BaseController
{
    public function __construct(
        private readonly ImportExportService $importExportService
    ) {
    }

    public function index(): string
    {
        try {
            $entity = strtolower((string) $this->request->getParam('entity', 'products'));
            $path = match ($entity) {
                'orders' => $this->importExportService->exportOrders($this->readFilters(['status', 'customer_id', 'increment_id'])),
                default => $this->importExportService->exportProducts($this->readFilters(['status', 'sku', 'name'])),
            };

            $content = (string) file_get_contents($path);
            $response = $this->request->getResponse();
            $response->setHeader('Content-Type', 'text/csv; charset=utf-8');
            $response->setHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
            $response->setHeader('Content-Length', (string) strlen($content));
            $response->setBody($content);

            if (is_file($path)) {
                @unlink($path);
            }

            return $content;
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Export failed.'));
        }
    }

    public function get(): string
    {
        return $this->index();
    }

    private function readFilters(array $keys): array
    {
        $filters = [];
        foreach ($keys as $key) {
            $value = $this->request->getParam($key, null);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
