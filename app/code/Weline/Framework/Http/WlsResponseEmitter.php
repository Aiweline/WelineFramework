<?php
declare(strict_types=1);

/**
 * Weline Framework - WLS 响应发射器
 * 
 * WLS（Weline Server）模式下的响应发射器实现。
 * 将响应构建为 HTTP 字符串，由 Worker 写入 socket。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * WLS 响应发射器
 * 
 * 特点：
 * - 不直接输出，而是构建 HTTP 响应字符串
 * - 由 Worker 负责写入 socket
 * - 不使用 exit()，通过返回值或异常终止请求
 */
class WlsResponseEmitter implements ResponseEmitterInterface
{
    /**
     * 最后构建的响应字符串
     */
    private string $lastResponse = '';
    
    /**
     * @inheritDoc
     */
    public function emit(HeaderCollectorInterface $headerCollector, string $body, bool $terminate = true): void
    {
        // WLS 模式下，不直接输出，而是存储响应
        $this->lastResponse = $this->toHttpString($headerCollector, $body);
        
        // WLS 模式下不使用 exit，通过异常终止
        if ($terminate) {
            throw new ResponseTerminateException(
                $headerCollector->getStatusCode(),
                $body,
                $headerCollector->getHeaders()
            );
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitRedirect(string $url, int $code = 302, bool $terminate = true): void
    {
        // WLS 模式下通过异常处理重定向
        throw new RedirectException($url, $code);
    }
    
    /**
     * @inheritDoc
     */
    public function emitDownload(string $filePath, string $fileName = '', bool $deleteAfter = false, bool $terminate = true): void
    {
        // WLS 模式下通过异常处理下载
        throw new DownloadException($filePath, $fileName, $deleteAfter);
    }
    
    /**
     * @inheritDoc
     */
    public function emitStaticFile(string $filePath, string $mimeType, array $cacheHeaders = [], bool $terminate = true): void
    {
        // WLS 模式下通过异常处理静态文件
        throw new StaticFileException($filePath, $mimeType, $cacheHeaders);
    }
    
    /**
     * @inheritDoc
     */
    public function emitNotModified(bool $terminate = true): void
    {
        // WLS 模式下通过异常处理 304
        throw new StaticFileException('', '', [], true);
    }
    
    /**
     * @inheritDoc
     */
    public function emitError(int $code, string $message = '', bool $terminate = true): void
    {
        // WLS 模式下通过异常处理错误
        throw new NoRouterException($code, $message);
    }
    
    /**
     * @inheritDoc
     */
    public function toHttpString(HeaderCollectorInterface $headerCollector, string $body): string
    {
        $statusCode = $headerCollector->getStatusCode();
        $statusText = $this->getStatusText($statusCode);
        
        $result = "HTTP/1.1 {$statusCode} {$statusText}\r\n";
        
        // 响应头
        foreach ($headerCollector->getHeaders() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $v) {
                    $result .= "{$name}: {$v}\r\n";
                }
            } else {
                $result .= "{$name}: {$value}\r\n";
            }
        }
        
        // Cookie
        foreach ($headerCollector->getCookies() as $cookie) {
            $result .= "Set-Cookie: " . $this->buildCookieString($cookie) . "\r\n";
        }
        
        // Content-Length
        $result .= "Content-Length: " . \strlen($body) . "\r\n";
        // Keep-Alive 默认开启，避免在 HTTPS 下每个资源都重复握手。
        // 若业务已显式设置 Connection 头，则以业务头为准。
        $headers = $headerCollector->getHeaders();
        $hasConnectionHeader = false;
        foreach ($headers as $name => $_) {
            if (\strtolower((string)$name) === 'connection') {
                $hasConnectionHeader = true;
                break;
            }
        }
        if (!$hasConnectionHeader) {
            $result .= "Connection: keep-alive\r\n";
        }
        $result .= "\r\n";
        $result .= $body;
        
        return $result;
    }
    
    /**
     * 获取最后构建的响应
     * 
     * @return string
     */
    public function getLastResponse(): string
    {
        return $this->lastResponse;
    }
    
    /**
     * 构建 Cookie 字符串
     */
    private function buildCookieString(array $cookie): string
    {
        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
        
        if (isset($cookie['expire']) && $cookie['expire'] !== 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']);
        }
        
        if (!empty($cookie['path'])) {
            $parts[] = 'Path=' . $cookie['path'];
        }
        
        if (!empty($cookie['domain'])) {
            $parts[] = 'Domain=' . $cookie['domain'];
        }
        
        if (!empty($cookie['secure'])) {
            $parts[] = 'Secure';
        }
        
        if (!empty($cookie['httpOnly'])) {
            $parts[] = 'HttpOnly';
        }
        
        if (!empty($cookie['sameSite'])) {
            $parts[] = 'SameSite=' . $cookie['sameSite'];
        }
        
        return \implode('; ', $parts);
    }
    
    /**
     * 获取状态码对应的文本
     */
    private function getStatusText(int $code): string
    {
        static $statusTexts = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            206 => 'Partial Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        
        return $statusTexts[$code] ?? 'Unknown';
    }
}
