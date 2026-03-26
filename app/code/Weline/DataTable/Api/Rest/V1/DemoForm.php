<?php

declare(strict_types=1);

namespace Weline\DataTable\Api\Rest\V1;

use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\App\Controller\FrontendRestController;

class DemoForm extends FrontendRestController
{
    public function __construct(
        private DemoTableService $demoTableService
    ) {
    }

    public function postFields(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->getFormFields($this->getPayload()));
    }

    public function postRecord(): string
    {
        return $this->wrapJson(fn () => $this->demoTableService->getRecord($this->getPayload()));
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
