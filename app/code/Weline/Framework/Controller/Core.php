<?php

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
        $this->request = ObjectManager::getInstance(Request::class);
        $this->getObjectManager();
    }

    public function __setModuleInfo(mixed $module): static
    {
        $this->_module = $module;
        $this->request = ObjectManager::getInstance(Request::class);
        return $this;
    }

    protected function getModule(): mixed
    {
        return $this->_module;
    }

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

    protected function getDebug(): Printing
    {
        if (!isset($this->_debug)) {
            $this->_debug = new Printing();
        }

        return $this->_debug;
    }

    protected function success(string $msg = '请求成功', mixed $data = '', int $code = 200): array|string
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

    protected function warning(string $msg = '请注意', mixed $data = '', int $code = 200): array|string
    {
        return [
            'success' => true,
            'error' => false,
            'warning' => true,
            'status' => 'warning',
            'code' => $code,
            'msg' => __($msg),
            'message' => __($msg),
            'data' => $data,
        ];
    }

    protected function error(string $msg = '请求失败', mixed $data = '', int $code = 404, ?string $title = null): array|string
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

        if (\defined('DEV') && DEV) {
            $response['debug'] = [
                'exception' => \get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        return $response;
    }
}
