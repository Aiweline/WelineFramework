<?php
declare(strict_types=1);

namespace Weline\Backend\Controller\Api;

use Weline\Backend\Controller\BackendRestController;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * Backend-area Framework Query REST shell.
 * Owning module: Weline_Backend. URL path remains /api_admin/framework/query.
 */
class Query extends BackendRestController
{
    public function __construct(
        private readonly FrameworkQueryService $queryService
    ) {
        parent::__construct();
    }

    public function postIndex(): string
    {
        try {
            $body = $this->request->getBodyParams(true);
            if (!\is_array($body)) {
                $body = [];
            }
            $provider = (string)($body['provider'] ?? '');
            $operation = (string)($body['operation'] ?? '');
            $params = (array)($body['params'] ?? []);

            $result = $this->queryService->execute($provider, $operation, $params, 'backend');
            return $this->success(__('查询成功'), $result);
        } catch (\Throwable $throwable) {
            return $this->error(__('查询失败：%{1}', $throwable->getMessage()), '', 400);
        }
    }
}
