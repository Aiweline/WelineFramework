<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Backend\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\App\Session\BackendApiSession;
use Weline\Framework\Service\Query\FrameworkQueryService;

class Query extends BackendRestController
{
    public function __construct(
        BackendApiSession $backendApiSession,
        private readonly FrameworkQueryService $queryService
    ) {
        parent::__construct($backendApiSession);
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

