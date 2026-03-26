<?php

declare(strict_types=1);

namespace Weline\DataTable\Api\Rest\V1;

use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\App\Controller\FrontendRestController;

class DemoTable extends FrontendRestController
{
    public function __construct(
        private DemoTableService $demoTableService
    ) {
    }

    public function postData(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->getTableData($this->getPayload()));
    }

    public function postFields(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->getTableFields($this->getPayload()));
    }

    public function postSaveConfig(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->saveFieldConfig($this->getPayload()), 'Field config saved.');
    }

    public function postClearConfig(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->clearFieldConfig($this->getPayload()), 'Field config cleared.');
    }

    public function postCreate(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->createRecord($this->getPayload()), 'Record created.');
    }

    public function postUpdate(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->updateRecord($this->getPayload()), 'Record updated.');
    }

    public function postSaveData(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->saveData($this->getPayload()), 'Record saved.');
    }

    public function postDeleteData(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->deleteRecords($this->getPayload()), 'Record deleted.');
    }

    public function postExportData(): string
    {
        try {
            $export = $this->demoTableService->exportData($this->getPayload());
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(200);
            $response->setHeader('Content-Type', (string)($export['content_type'] ?? 'application/octet-stream'));
            $response->setHeader('Content-Disposition', 'attachment; filename="' . ($export['filename'] ?? 'datatable-demo-export.dat') . '"');
            return (string)($export['body'] ?? '');
        } catch (\Throwable $throwable) {
            return $this->jsonError($throwable->getMessage(), 400);
        }
    }

    public function postInitData(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->initDemoData(), 'Demo data initialized.');
    }

    public function postClearData(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->clearDemoData(), 'Demo data cleared.');
    }

    /**
     * @return array<string,mixed>
     */
    private function getPayload(): array
    {
        $body = $this->request->getBodyParams();
        return is_array($body) ? $body : [];
    }

    private function wrapJson(callable $callback, string $message = 'OK'): string
    {
        try {
            $data = $callback();
            return $this->jsonSuccess($message, is_array($data) ? $data : ['result' => $data]);
        } catch (\Throwable $throwable) {
            return $this->jsonError($throwable->getMessage(), 400);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function jsonSuccess(string $message, array $data = [], int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode($code);
        return $response->renderJson([
            'success' => true,
            'error' => false,
            'code' => $code,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function jsonError(string $message, int $code = 400, array $data = []): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode($code);
        return $response->renderJson([
            'success' => false,
            'error' => true,
            'code' => $code,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
