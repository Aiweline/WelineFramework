<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Controller\Frontend;

use WeShop\Frontend\Controller\BaseController;
use WeShop\ApiBridge\Service\ApiBridgeService;
use Weline\Framework\Manager\ObjectManager;

/**
 * API 桥接控制器
 *
 * 提供 WeShop REST API 的统一访问入口和 API 文档页面
 */
class ApiBridge extends BaseController
{
    /**
     * 默认页码
     */
    private const DEFAULT_PAGE = 1;

    /**
     * 默认每页数量
     */
    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * 最大每页数量
     */
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly ApiBridgeService $apiBridgeService
    ) {
    }

    /**
     * API 桥接首页 - 显示 API 文档
     *
     * @return string
     */
    public function index(): string
    {
        try {
            $documentation = $this->apiBridgeService->getApiDocumentation();
            $this->assign('api_doc', $documentation);
            $this->assign('page_title', __('WeShop API Bridge'));

            return $this->renderLayout(
                'default',
                'WeShop_ApiBridge::templates/Frontend/ApiBridge/index.phtml',
                __('API Documentation'),
                ['meta' => ['showHeader' => true, 'showFooter' => true]]
            );
        } catch (\Throwable $e) {
            $this->assign('error_message', __('Failed to load API documentation: %1', $e->getMessage()));
            return $this->renderLayout(
                'default',
                'WeShop_ApiBridge::templates/Frontend/ApiBridge/index.phtml',
                __('API Documentation'),
                ['meta' => ['showHeader' => true, 'showFooter' => true]]
            );
        }
    }

    /**
     * 获取 API 端点列表
     *
     * @return array<string, mixed>
     */
    public function endpoints(): array
    {
        try {
            $endpoints = $this->apiBridgeService->getApiEndpoints();

            return $this->apiBridgeService->successResponse(
                $endpoints,
                __('API endpoints retrieved successfully')
            );
        } catch (\Throwable $e) {
            return $this->apiBridgeService->errorResponse(
                __('Failed to retrieve API endpoints: %1', $e->getMessage()),
                500
            );
        }
    }

    /**
     * 获取指定 API 端点的详细信息
     *
     * @return array<string, mixed>
     */
    public function endpointInfo(): array
    {
        try {
            $endpoint = $this->request->getGet('endpoint', '');
            if (empty($endpoint)) {
                return $this->apiBridgeService->errorResponse(
                    __('API endpoint parameter is required'),
                    400
                );
            }

            if (!$this->apiBridgeService->endpointExists($endpoint)) {
                return $this->apiBridgeService->errorResponse(
                    __('API endpoint "%1" not found', $endpoint),
                    404
                );
            }

            $info = $this->apiBridgeService->getEndpointInfo($endpoint);

            return $this->apiBridgeService->successResponse(
                $info,
                __('API endpoint info retrieved successfully')
            );
        } catch (\Throwable $e) {
            return $this->apiBridgeService->errorResponse(
                __('Failed to retrieve endpoint info: %1', $e->getMessage()),
                500
            );
        }
    }

    /**
     * 测试 API 连接
     *
     * @return array<string, mixed>
     */
    public function test(): array
    {
        try {
            $endpoint = $this->request->getPost('endpoint', '');
            $method = $this->request->getPost('method', 'index');
            $params = $this->request->getPost('params', []);

            if (empty($endpoint)) {
                return $this->apiBridgeService->errorResponse(
                    __('API endpoint parameter is required'),
                    400
                );
            }

            if (!$this->apiBridgeService->endpointExists($endpoint)) {
                return $this->apiBridgeService->errorResponse(
                    __('API endpoint "%1" not found', $endpoint),
                    404
                );
            }

            // 获取桥接实例
            $bridge = match ($endpoint) {
                'cart' => $this->apiBridgeService->getCartBridge(),
                'checkout' => $this->apiBridgeService->getCheckoutBridge(),
                'auth' => $this->apiBridgeService->getAuthBridge(),
                default => null,
            };

            if ($bridge === null) {
                return $this->apiBridgeService->errorResponse(
                    __('Unsupported API endpoint'),
                    400
                );
            }

            // 检查方法是否存在
            if (!method_exists($bridge, $method)) {
                return $this->apiBridgeService->errorResponse(
                    __('Method "%1" not found in endpoint "%2"', $method, $endpoint),
                    404
                );
            }

            // 调用方法
            $result = $bridge->$method($params);

            return $this->apiBridgeService->successResponse(
                $result,
                __('API test executed successfully')
            );
        } catch (\Throwable $e) {
            return $this->apiBridgeService->errorResponse(
                __('API test failed: %1', $e->getMessage()),
                500
            );
        }
    }

    /**
     * 健康检查接口
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => time(),
            'version' => '1.0.0',
            'services' => [],
        ];

        try {
            $endpoints = $this->apiBridgeService->getApiEndpoints();
            foreach ($endpoints as $key => $endpoint) {
                $health['services'][$key] = [
                    'status' => 'available',
                    'class' => $endpoint['class'],
                ];
            }

            return $this->apiBridgeService->successResponse(
                $health,
                __('Health check passed')
            );
        } catch (\Throwable $e) {
            $health['status'] = 'unhealthy';
            $health['error'] = $e->getMessage();

            return $this->apiBridgeService->errorResponse(
                __('Health check failed: %1', $e->getMessage()),
                503,
                $health
            );
        }
    }

    /**
     * 获取 API 文档（JSON 格式）
     *
     * @return array<string, mixed>
     */
    public function docs(): array
    {
        try {
            $documentation = $this->apiBridgeService->getApiDocumentation();

            return $this->apiBridgeService->successResponse(
                $documentation,
                __('API documentation retrieved successfully')
            );
        } catch (\Throwable $e) {
            return $this->apiBridgeService->errorResponse(
                __('Failed to retrieve API documentation: %1', $e->getMessage()),
                500
            );
        }
    }

    /**
     * 统一响应方法 - 输出 JSON
     *
     * @param array<string, mixed> $data
     * @return void
     */
    protected function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 获取分页参数
     *
     * @return array{page: int, page_size: int, offset: int}
     */
    protected function getPaginationParams(): array
    {
        $page = max(1, (int) $this->request->getGet('page', self::DEFAULT_PAGE));
        $pageSize = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $this->request->getGet('page_size', self::DEFAULT_PAGE_SIZE))
        );
        $offset = ($page - 1) * $pageSize;

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'offset' => $offset,
        ];
    }
}
