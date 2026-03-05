<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * HTTP 响应类
 * 
 * 重构说明：
 * - 所有终止请求的操作都通过抛出 ResponseTerminateException 及其子类实现
 * - 不再直接调用 exit()/die()
 * - 不再判断 WLS_MODE，由 Runtime 层统一处理异常
 * - 响应头通过 HeaderCollector 收集，由 Runtime 层统一发送
 */
class Response implements ResponseInterface
{
    /**
     * HeaderCollector 实例
     */
    private ?HeaderCollectorInterface $headerCollector = null;
    
    /**
     * 响应体
     */
    private string $body = '';
    
    /**
     * 获取 HeaderCollector
     */
    private function getHeaderCollector(): HeaderCollectorInterface
    {
        if ($this->headerCollector === null) {
            $this->headerCollector = HeaderCollector::getInstance();
        }
        return $this->headerCollector;
    }

    function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    public function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }
    
    /**
     * 获取所有响应头
     * 
     * @return array<string, string|array>
     */
    public function getHeaders(): array
    {
        return $this->getHeaderCollector()->getHeaders();
    }
    
    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }
    
    /**
     * 获取状态码
     */
    public function getStatusCode(): int
    {
        return $this->getHeaderCollector()->getStatusCode();
    }

    /**
     * 设置响应头（只收集，不立即发送）
     */
    public function setHeader(string $header_key, string $header_value): static
    {
        $this->getHeaderCollector()->setHeader($header_key, $header_value);
        return $this;
    }

    /**
     * 批量设置响应头
     */
    public function setHeaders(array $headers): static
    {
        $this->getHeaderCollector()->setHeaders($headers);
        return $this;
    }
    
    /**
     * 发送收集的响应头（由 Runtime 层调用）
     */
    public function emitHeaders(): void
    {
        $this->getHeaderCollector()->emit();
    }
    
    /**
     * 设置 Cookie（通过 HeaderCollector）
     */
    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $this->getHeaderCollector()->setCookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite);
        return $this;
    }

    public function setData(mixed $data): static
    {
        /**@var DataObject $dataObject */
        $dataObject = ObjectManager::getInstance(DataObject::class);
        $dataObject->setData($data);
        
        if (\is_int(\strpos($this->getRequest()->getContentType(), 'application/json'))) {
            $this->setHeader('Content-Type', 'application/json');
            $this->body = $dataObject->toJson();
        } elseif (\is_int(\strpos($this->getRequest()->getContentType(), 'text/xml'))) {
            $this->setHeader('Content-Type', 'text/xml');
            $this->body = $dataObject->toXml();
        } else {
            $this->body = $dataObject->toString();
        }
        
        return $this;
    }

    /**
     * 无路由处理
     * 
     * 始终抛出 NoRouterException，由 Runtime 层统一处理。
     * 
     * @throws NoRouterException
     */
    public function noRouter(int|string $code = 404, string $msg = ''): never
    {
        if (empty($msg)) {
            switch ($code) {
                case 403:
                    $msg = 'Forbidden';
                    break;
                case 404:
                    $msg = 'Not Found';
                    break;
                case 500:
                    $msg = 'Internal Server Error';
                    break;
                default:
                    $msg = 'Unknown Error';
            }
        }
        
        $eventData = ['code' => $code, 'msg' => $msg];
        $this->getEvenManager()->dispatch('Weline_Framework_Http::http_response_no_router_before', $eventData);
        $statusCode = \is_int($code) ? $code : (int) $code;
        
        // 始终抛出异常，由 Runtime 层统一处理
        throw new NoRouterException($statusCode, $msg);
    }

    /**
     * 响应 HTTP 状态码
     * 
     * @throws ResponseTerminateException
     */
    public function responseHttpCode(int $code = 200): never
    {
        throw new ResponseTerminateException($code);
    }

    /**
     * 重定向
     * 
     * 始终抛出 RedirectException，由 Runtime 层统一处理。
     * 
     * @throws RedirectException
     */
    public function redirect(string $url, int $code = 302): never
    {
        // 重定向监控：记录重定向信息
        $redirectCount = ($_SERVER['REDIRECT_COUNT'] ?? 0) + 1;
        $_SERVER['REDIRECT_COUNT'] = $redirectCount;
        $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // 如果重定向次数过多，记录警告
        if ($redirectCount > 5) {
            w_log_warning("[Redirect Warning] Too many redirects: {$redirectCount}, current URI: {$currentUri}, redirect to: {$url}");
        }
        
        // 如果重定向次数超过10次，停止重定向循环
        if ($redirectCount > 10) {
            w_log_error("[Redirect Error] Redirect loop detected! Stopping redirect. Current URI: {$currentUri}, Attempted redirect to: {$url}");
            throw new \RuntimeException("Redirect loop detected after {$redirectCount} redirects");
        }
        
        // 触发重定向前事件
        $data = new DataObject(['url' => $url, 'code' => $code]);
        $this->getEvenManager()->dispatch('Framework_Http::response_redirect_before', $data);
        $url = $data->getData('url');
        $code = (int) $data->getData('code');
        
        // 始终抛出异常，由 Runtime 层统一处理
        throw new RedirectException($url, $code);
    }

    /**
     * 渲染 JSON 响应
     */
    public function renderJson(array $data): string
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        return \json_encode($data);
    }

    /**
     * 设置 HTTP 响应状态码
     */
    public function setHttpResponseCode(int $code): static
    {
        $this->getHeaderCollector()->setStatusCode($code);
        return $this;
    }

    /**
     * 设置响应体内容
     */
    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * 发送响应并终止（简短别名，兼容 send）
     *
     * @throws ResponseTerminateException
     */
    public function send(): never
    {
        $this->sendResponse();
    }

    /**
     * 发送响应并终止
     *
     * @throws ResponseTerminateException
     */
    public function sendResponse(): never
    {
        throw new ResponseTerminateException(
            $this->getHeaderCollector()->getStatusCode(),
            $this->body,
            $this->getHeaderCollector()->getHeaders()
        );
    }

    /**
     * 文件下载
     * 
     * @throws DownloadException
     */
    public function download(string $file, string $name = '', bool $is_delete = false): never
    {
        if (!\is_file($file)) {
            Message::error(__('文件不存在！'));
            throw new NoRouterException(404, 'File not found');
        }
        
        // 始终抛出异常，由 Runtime 层统一处理
        throw new DownloadException($file, $name, $is_delete);
    }
    
    /**
     * 构建 ResponseTerminateException
     * 
     * 用于需要立即终止请求的场景
     */
    public function terminate(): ResponseTerminateException
    {
        return new ResponseTerminateException(
            $this->getHeaderCollector()->getStatusCode(),
            $this->body,
            $this->getHeaderCollector()->getHeaders()
        );
    }
    
    /**
     * 获取 HeaderCollector 实例（供外部访问）
     */
    public function getHeaderCollectorInstance(): HeaderCollectorInterface
    {
        return $this->getHeaderCollector();
    }
}
