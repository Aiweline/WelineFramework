<?php

declare(strict_types=1);

/*
 * EAV后台API控制器基类
 * 提供统一的JSON响应格式和通用方法
 */

namespace Weline\Eav\Controller\Backend\Api;

use Weline\Framework\App\Controller\BackendController;

/**
 * EAV API控制器基类
 * 
 * 所有EAV后台API控制器继承此类，提供：
 * - 统一的JSON响应格式
 * - 通用的验证方法
 * - 错误处理
 */
abstract class ApiController extends BackendController
{
    /**
     * 成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 成功消息
     * @return string JSON响应
     */
    protected function apiSuccess(mixed $data = null, string $message = ''): string
    {
        return $this->fetchJson([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 错误响应
     *
     * @param string $message 错误消息
     * @param int $code 错误代码
     * @param mixed $data 附加数据
     * @return string JSON响应
     */
    protected function apiError(string $message, int $code = 400, mixed $data = null): string
    {
        return $this->fetchJson([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => $data,
        ]);
    }

    /**
     * 分页响应
     *
     * @param array $items 数据项
     * @param array $pagination 分页信息
     * @return string JSON响应
     */
    protected function paginated(array $items, array $pagination): string
    {
        return $this->fetchJson([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => $pagination,
            ],
        ]);
    }

    /**
     * 获取请求参数（GET或POST）
     *
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getParam(string $key, mixed $default = null): mixed
    {
        return $this->request->getGet($key) ?? $this->request->getPost($key) ?? $default;
    }

    /**
     * 获取必需的请求参数
     *
     * @param string $key 参数名
     * @return mixed
     * @throws \InvalidArgumentException 参数缺失时抛出异常
     */
    protected function getRequiredParam(string $key): mixed
    {
        $value = $this->getParam($key);
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException(__('缺少必需参数: %1', $key));
        }
        return $value;
    }

    /**
     * 获取整数参数
     *
     * @param string $key 参数名
     * @param int|null $default 默认值
     * @return int|null
     */
    protected function getIntParam(string $key, ?int $default = null): ?int
    {
        $value = $this->getParam($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int)$value;
    }

    /**
     * 验证请求是否为POST
     *
     * @return bool
     */
    protected function isPost(): bool
    {
        return $this->request->isPost();
    }

    /**
     * 验证请求是否为GET
     *
     * @return bool
     */
    protected function isGet(): bool
    {
        return $this->request->isGet();
    }

    /**
     * 包装异常处理执行
     *
     * @param callable $callback 要执行的回调
     * @return string JSON响应
     */
    protected function tryCatch(callable $callback): string
    {
        try {
            return $callback();
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->apiError($e->getMessage(), 500);
        }
    }
}
