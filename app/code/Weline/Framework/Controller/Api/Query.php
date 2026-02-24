<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Service\Query\FrameworkQueryService;

class Query extends FrontendRestController
{
    public function __construct(
        private readonly FrameworkQueryService $queryService
    ) {
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

            $result = $this->queryService->execute($provider, $operation, $params, 'frontend');
            return $this->fetch([
                'code' => 200,
                'msg' => __('查询成功'),
                'data' => $result,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetch([
                'code' => 400,
                'msg' => __('查询失败：%{1}', $throwable->getMessage()),
                'data' => '',
            ]);
        }
    }
}

