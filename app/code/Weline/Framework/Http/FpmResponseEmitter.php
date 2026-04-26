<?php
declare(strict_types=1);

/**
 * Weline Framework - FPM 响应发射器
 * 
 * PHP-FPM 模式下的响应发射器实现。
 * 直接使用 PHP 原生函数发送响应。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\System;

/**
 * FPM 响应发射器
 * 
 * 特点：
 * - 使用 header() 发送响应头
 * - 使用 echo 发送响应体
 * - 支持 exit() 终止请求
 */
class FpmResponseEmitter implements ResponseEmitterInterface
{
    /**
     * @inheritDoc
     */
    public function emit(HeaderCollectorInterface $headerCollector, string $body, bool $terminate = true): void
    {
        if (!\headers_sent()) {
            $headerCollector->emit(true);
        }
        
        echo $body;
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitRedirect(string $url, int $code = 302, bool $terminate = true): void
    {
        if (!\headers_sent()) {
            \http_response_code($code);
            \header("Location: {$url}");
        }
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitDownload(string $filePath, string $fileName = '', bool $deleteAfter = false, bool $terminate = true): void
    {
        if (!\is_file($filePath)) {
            $this->emitError(404, 'File not found', $terminate);
            return;
        }
        
        if ($fileName === '') {
            $fileName = \basename($filePath);
        }
        
        $fileSize = \filesize($filePath);
        $mimeType = \mime_content_type($filePath) ?: 'application/octet-stream';
        
        if (!\headers_sent()) {
            \http_response_code(200);
            \header("Content-Type: {$mimeType}");
            \header("Content-Disposition: attachment; filename=\"{$fileName}\"");
            \header("Content-Length: {$fileSize}");
            \header('Cache-Control: private, max-age=0, must-revalidate');
            \header('Pragma: public');
        }
        
        // 清空输出缓冲区
        while (\ob_get_level()) {
            \ob_end_clean();
        }
        
        \readfile($filePath);
        
        if ($deleteAfter) {
            @\unlink($filePath);
        }
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitStaticFile(string $filePath, string $mimeType, array $cacheHeaders = [], bool $terminate = true): void
    {
        if (!\is_file($filePath)) {
            $this->emitError(404, 'File not found', $terminate);
            return;
        }
        
        if (!\headers_sent()) {
            \http_response_code(200);
            \header("Content-Type: {$mimeType}");
            
            foreach ($cacheHeaders as $name => $value) {
                \header("{$name}: {$value}");
            }
        }
        
        \readfile($filePath);
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitNotModified(bool $terminate = true): void
    {
        if (!\headers_sent()) {
            \http_response_code(304);
        }
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function emitError(int $code, string $message = '', bool $terminate = true): void
    {
        if (!\headers_sent()) {
            \http_response_code($code);
            \header('Content-Type: text/html; charset=UTF-8');
        }
        
        $statusText = $this->getStatusText($code);
        $displayMessage = $message ?: $statusText;
        
        echo "<!DOCTYPE html><html><head><title>{$code} {$statusText}</title></head>";
        echo "<body><h1>{$code} {$statusText}</h1>";
        if ($message) {
            echo "<p>" . \htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
        }
        echo "</body></html>";
        
        if ($terminate) {
            System::exit(0);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function toHttpString(HeaderCollectorInterface $headerCollector, string $body): string
    {
        $result = $headerCollector->toHttpHeaderString();
        $contentType = (string)($headerCollector->getHeader('Content-Type') ?? '');
        if (!\str_contains(\strtolower($contentType), 'text/event-stream')
            && !$headerCollector->hasHeader('Content-Length')) {
            $result .= "Content-Length: " . \strlen($body) . "\r\n";
        }
        $result .= "Connection: close\r\n";
        $result .= "\r\n";
        $result .= $body;
        
        return $result;
    }
    
    /**
     * 获取状态码对应的文本
     */
    private function getStatusText(int $code): string
    {
        static $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        
        return $statusTexts[$code] ?? 'Unknown';
    }
}
