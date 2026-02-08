<?php
declare(strict_types=1);

/**
 * Weline Framework - 静态文件响应异常
 * 
 * 静态文件响应通过抛出此异常来实现，而不是调用 exit()。
 * Runtime 层会捕获此异常并转换为文件响应。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 静态文件响应异常
 * 
 * 继承自 ResponseTerminateException，表示需要响应静态文件。
 * Router::StaticFile() 会抛出此异常，由 Runtime 层统一处理。
 */
class StaticFileException extends ResponseTerminateException
{
    /**
     * 文件路径
     */
    private string $filePath;
    
    /**
     * MIME 类型
     */
    private string $mimeType;
    
    /**
     * 是否 304 Not Modified 响应
     */
    private bool $notModified = false;
    
    /**
     * 构造函数
     * 
     * @param string $filePath 文件路径
     * @param string $mimeType MIME 类型
     * @param array $headers 额外的响应头
     * @param bool $notModified 是否 304 响应
     */
    public function __construct(string $filePath, string $mimeType = '', array $headers = [], bool $notModified = false)
    {
        $this->filePath = $filePath;
        $this->mimeType = $mimeType;
        $this->notModified = $notModified;
        
        $statusCode = $notModified ? 304 : 200;
        
        if (!$notModified && $mimeType) {
            $headers['Content-Type'] = $mimeType;
        }
        
        parent::__construct($statusCode, '', $headers);
    }
    
    /**
     * 获取文件路径
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
    
    /**
     * 获取 MIME 类型
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }
    
    /**
     * 是否 304 Not Modified 响应
     */
    public function isNotModified(): bool
    {
        return $this->notModified;
    }
    
    /**
     * 发送静态文件内容（覆盖父类方法）
     */
    public function emit(bool $terminate = true): void
    {
        if (!\headers_sent()) {
            \http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                \header("{$name}: {$value}");
            }
        }
        
        // 304 Not Modified 不需要发送内容
        if (!$this->notModified && \is_file($this->filePath)) {
            \readfile($this->filePath);
        }
        
        if ($terminate) {
            exit(0);
        }
    }
    
    /**
     * 构建 HTTP 响应字符串（用于 WLS 模式）
     */
    public function toHttpString(): string
    {
        $statusText = $this->getStatusText($this->statusCode);
        $response = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";
        
        foreach ($this->headers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }
        
        // 304 Not Modified 响应
        if ($this->notModified) {
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
            return $response;
        }
        
        // 读取文件内容
        if (\is_file($this->filePath)) {
            $content = \file_get_contents($this->filePath);
            $response .= "Content-Length: " . \strlen($content) . "\r\n";
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
            $response .= $content;
        } else {
            $response .= "Connection: close\r\n";
            $response .= "\r\n";
        }
        
        return $response;
    }
}
