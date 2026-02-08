<?php
declare(strict_types=1);

/**
 * Weline Framework - 下载异常
 * 
 * 文件下载通过抛出此异常来实现，而不是调用 exit()。
 * Runtime 层会捕获此异常并转换为文件下载响应。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 下载异常
 * 
 * 继承自 ResponseTerminateException，表示需要下载文件。
 * Response::download() 会抛出此异常，由 Runtime 层统一处理。
 */
class DownloadException extends ResponseTerminateException
{
    /**
     * 文件路径
     */
    private string $filePath;
    
    /**
     * 下载文件名
     */
    private string $fileName;
    
    /**
     * 是否删除文件
     */
    private bool $deleteAfterDownload;
    
    /**
     * 构造函数
     * 
     * @param string $filePath 文件路径
     * @param string $fileName 下载文件名
     * @param bool $deleteAfterDownload 下载后是否删除
     */
    public function __construct(string $filePath, string $fileName = '', bool $deleteAfterDownload = false)
    {
        $this->filePath = $filePath;
        $this->fileName = $fileName ?: \basename($filePath);
        $this->deleteAfterDownload = $deleteAfterDownload;
        
        // 设置下载相关的 headers
        $headers = [
            'Content-Description' => 'File Transfer',
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename=' . $this->fileName,
            'Content-Transfer-Encoding' => 'binary',
            'Expires' => '0',
            'Cache-Control' => 'must-revalidate',
            'Pragma' => 'public',
            'Content-Length' => (string) (\is_file($filePath) ? \filesize($filePath) : 0),
        ];
        
        parent::__construct(200, '', $headers);
    }
    
    /**
     * 获取文件路径
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
    
    /**
     * 获取下载文件名
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }
    
    /**
     * 是否删除文件
     */
    public function shouldDeleteAfterDownload(): bool
    {
        return $this->deleteAfterDownload;
    }
    
    /**
     * 发送文件内容（覆盖父类方法）
     */
    public function emit(bool $terminate = true): void
    {
        if (!\is_file($this->filePath)) {
            throw new \RuntimeException("File not found: {$this->filePath}");
        }
        
        if (!\headers_sent()) {
            foreach ($this->headers as $name => $value) {
                \header("{$name}: {$value}");
            }
        }
        
        \readfile($this->filePath);
        
        if ($this->deleteAfterDownload) {
            @\unlink($this->filePath);
        }
        
        if ($terminate) {
            exit(0);
        }
    }
    
    /**
     * 构建 HTTP 响应字符串（用于 WLS 模式）
     * 
     * 使用分块读取代替 file_get_contents，减少峰值内存占用
     */
    public function toHttpString(): string
    {
        if (!\is_file($this->filePath)) {
            throw new \RuntimeException("File not found: {$this->filePath}");
        }
        
        $fileSize = \filesize($this->filePath);
        
        // 更新 Content-Length 为实际文件大小（文件可能在构造后发生变化）
        $this->headers['Content-Length'] = (string) $fileSize;
        
        $statusText = $this->getStatusText($this->statusCode);
        $response = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";
        
        foreach ($this->headers as $name => $value) {
            $response .= "{$name}: {$value}\r\n";
        }
        
        $response .= "Connection: close\r\n";
        $response .= "\r\n";
        
        // 分块读取文件内容（每次 64KB），减少峰值内存
        $fp = @\fopen($this->filePath, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open file: {$this->filePath}");
        }
        
        $chunkSize = 64 * 1024; // 64KB
        $remaining = $fileSize;
        
        while ($remaining > 0 && !\feof($fp)) {
            $readSize = \min($chunkSize, $remaining);
            $chunk = \fread($fp, $readSize);
            if ($chunk === false) {
                \fclose($fp);
                throw new \RuntimeException("File read error: {$this->filePath}");
            }
            $response .= $chunk;
            $remaining -= \strlen($chunk);
        }
        
        \fclose($fp);
        
        if ($this->deleteAfterDownload) {
            @\unlink($this->filePath);
        }
        
        return $response;
    }
}
