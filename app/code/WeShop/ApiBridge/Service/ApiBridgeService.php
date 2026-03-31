<?php

declare(strict_types=1);

namespace WeShop\ApiBridge\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\ApiBridge\Api\Rest\V1\Weshop\Cart;
use WeShop\ApiBridge\Api\Rest\V1\Weshop\Checkout;
use WeShop\ApiBridge\Api\Rest\V1\Weshop\Auth;

/**
 * API 桥接服务
 *
 * 统一管理 WeShop 各模块的 REST API 桥接类，提供标准化的 API 调用接口
 */
class ApiBridgeService
{
    /**
     * API 端点列表
     */
    private const API_ENDPOINTS = [
        'cart' => [
            'class' => Cart::class,
            'name' => 'Cart',
            'description' => '购物车 API',
            'version' => 'V1',
        ],
        'checkout' => [
            'class' => Checkout::class,
            'name' => 'Checkout',
            'description' => '结账 API',
            'version' => 'V1',
        ],
        'auth' => [
            'class' => Auth::class,
            'name' => 'Auth',
            'description' => '认证 API',
            'version' => 'V1',
        ],
    ];

    /**
     * 获取所有可用的 API 端点
     *
     * @return array<string, array{class: string, name: string, description: string, version: string}>
     */
    public function getApiEndpoints(): array
    {
        return self::API_ENDPOINTS;
    }

    /**
     * 获取指定 API 端点的详细信息
     *
     * @param string $endpoint 端点名称 (cart|checkout|auth)
     * @return array{class: string, name: string, description: string, version: string}|null
     */
    public function getEndpointInfo(string $endpoint): ?array
    {
        return self::API_ENDPOINTS[$endpoint] ?? null;
    }

    /**
     * 获取 Cart API 桥接实例
     *
     * @return Cart
     */
    public function getCartBridge(): Cart
    {
        return ObjectManager::getInstance(Cart::class);
    }

    /**
     * 获取 Checkout API 桥接实例
     *
     * @return Checkout
     */
    public function getCheckoutBridge(): Checkout
    {
        return ObjectManager::getInstance(Checkout::class);
    }

    /**
     * 获取 Auth API 桥接实例
     *
     * @return Auth
     */
    public function getAuthBridge(): Auth
    {
        return ObjectManager::getInstance(Auth::class);
    }

    /**
     * 构建统一 API 响应格式
     *
     * @param bool $success 是否成功
     * @param mixed $data 响应数据
     * @param string $message 消息
     * @param int $code 状态码
     * @param array<string, mixed> $meta 元数据
     * @return array<string, mixed>
     */
    public function buildResponse(
        bool $success,
        mixed $data = null,
        string $message = '',
        int $code = 200,
        array $meta = []
    ): array {
        $response = [
            'success' => $success,
            'code' => $code,
            'message' => $message,
            'timestamp' => time(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return $response;
    }

    /**
     * 构建成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 成功消息
     * @param array<string, mixed> $meta 元数据
     * @return array<string, mixed>
     */
    public function successResponse(mixed $data = null, string $message = '', array $meta = []): array
    {
        return $this->buildResponse(true, $data, $message, 200, $meta);
    }

    /**
     * 构建错误响应
     *
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param mixed $data 附加数据
     * @return array<string, mixed>
     */
    public function errorResponse(string $message, int $code = 400, mixed $data = null): array
    {
        return $this->buildResponse(false, $data, $message, $code);
    }

    /**
     * 构建分页响应
     *
     * @param array<int, mixed> $items 数据项列表
     * @param int $page 当前页码
     * @param int $pageSize 每页数量
     * @param int $total 总数
     * @return array<string, mixed>
     */
    public function paginatedResponse(array $items, int $page, int $pageSize, int $total): array
    {
        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        return $this->successResponse(
            $items,
            '',
            [
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                    'has_prev' => $page > 1,
                ],
            ]
        );
    }

    /**
     * 验证 API 端点是否存在
     *
     * @param string $endpoint 端点名称
     * @return bool
     */
    public function endpointExists(string $endpoint): bool
    {
        return isset(self::API_ENDPOINTS[$endpoint]);
    }

    /**
     * 获取 API 文档信息
     *
     * @return array<string, mixed>
     */
    public function getApiDocumentation(): array
    {
        $endpoints = [];

        foreach (self::API_ENDPOINTS as $key => $endpoint) {
            $endpoints[$key] = [
                'name' => $endpoint['name'],
                'description' => $endpoint['description'],
                'version' => $endpoint['version'],
                'class' => $endpoint['class'],
            ];
        }

        return [
            'name' => 'WeShop API Bridge',
            'version' => '1.0.0',
            'description' => __('WeShop REST API 桥接模块'),
            'endpoints' => $endpoints,
        ];
    }
}
