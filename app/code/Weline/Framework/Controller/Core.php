<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Debug\Printing;

class Core implements Data\DataInterface
{
    protected ObjectManager $_objectManager;

    protected Request $request;

    protected Printing $_debug;

    private mixed $_module;

    protected function noRouter()
    {
        $this->request->getResponse()->noRouter();
    }


    public function __init()
    {
        // WLS 模式下每个请求使用新的 Request 对象
        // 必须每次都从 ObjectManager 获取最新的 Request，不能使用缓存的实例
        // 因为 WlsRuntime 会在每个请求开始时调用 ObjectManager::setInstance() 设置新的 Request
        $this->request = ObjectManager::getInstance(Request::class);
        $this->getObjectManager();
    }


    /**
     * @DESC          # 设置模块名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/11 15:55
     * 参数区：
     *
     * @param mixed $module
     *
     * @return $this
     */
    public function __setModuleInfo(mixed $module): static
    {
        $this->_module = $module;
        // WLS 模式下：Router 每次分发前都会调用此方法
        // 必须刷新 request 引用，因为 WlsRuntime 已在 ObjectManager 中注册了新的 WlsRequest
        // 而控制器单例的 __init() 只在首次创建时调用一次，$this->request 会指向旧的 WlsRequest
        $this->request = ObjectManager::getInstance(Request::class);
        return $this;
    }

    /**
     * @DESC          # 获取模块名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/11 15:55
     * 参数区：
     * @return string
     */
    protected function getModule(): mixed
    {
        return $this->_module;
    }


    /**
     * @return ObjectManager
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function getObjectManager(): ObjectManager
    {
        if (!isset($this->_objectManager)) {
            $this->_objectManager = ObjectManager::getInstance();
        }

        return $this->_objectManager;
    }

    protected function getObject(string $class): mixed
    {
        return $this->getObjectManager()::getInstance($class);
    }

    /**
     * @return Printing
     */
    protected function getDebug(): Printing
    {
        if (!isset($this->_debug)) {
            $this->_debug = new Printing();
        }

        return $this->_debug;
    }


    /**
     * 返回成功响应
     * 
     * @param string $msg 成功消息
     * @param mixed $data 响应数据
     * @param int $code HTTP 状态码
     * @return array|string 响应数组（子类可能返回序列化字符串）
     */
    protected function success(string $msg = '请求成功！', mixed $data = '', int $code = 200): array|string
    {
        return [
            'success' => true,
            'error' => false,
            'code' => $code,
            'msg' => __($msg),
            'message' => __($msg),
            'data' => $data,
        ];
    }

    /**
     * 返回错误响应（支持多语言和前端友好提示）
     * 
     * @param string $msg 错误消息
     * @param mixed $data 额外数据
     * @param int $code HTTP 状态码
     * @param string|null $title 错误标题（可选，默认根据状态码生成）
     * @return array|string 响应数组（子类可能返回序列化字符串）
     */
    protected function error(string $msg = '请求失败！', mixed $data = '', int $code = 404, ?string $title = null): array|string
    {
        return [
            'success' => false,
            'error' => true,
            'code' => $code,
            'title' => $title ?? \Weline\Framework\Exception\ErrorResponse::getTitle($code),
            'msg' => __($msg),
            'message' => __($msg),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($code),
            'data' => $data,
        ];
    }

    /**
     * 返回异常响应（支持多语言和前端友好提示）
     * 
     * @param \Throwable $exception 异常对象
     * @param string $msg 自定义错误消息（可选）
     * @param mixed $data 额外数据
     * @param int|null $code HTTP 状态码（可选，默认从异常获取）
     * @return array|string 响应数组（子类可能返回序列化字符串）
     */
    protected function exception(\Throwable $exception, string $msg = '', mixed $data = '', ?int $code = null): array|string
    {
        $statusCode = $code ?? \Weline\Framework\Exception\ErrorResponse::getStatusCode($exception);
        $message = $msg ?: $exception->getMessage();
        
        $response = [
            'success' => false,
            'error' => true,
            'code' => $statusCode,
            'title' => \Weline\Framework\Exception\ErrorResponse::getTitle($statusCode),
            'msg' => __($message),
            'message' => __($message),
            'icon' => \Weline\Framework\Exception\ErrorResponse::getIcon($statusCode),
            'data' => $data,
        ];
        
        // DEV 模式添加调试信息
        if (\defined('DEV') && DEV) {
            $response['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }
        
        return $response;
    }
}
