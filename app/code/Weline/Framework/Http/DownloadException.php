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
    private const READ_CHUNK_BYTES = 64 * 1024;
    private const LEGACY_BUFFER_MAX_BYTES = 2 * 1024 * 1024;

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
        $this->fileName = self::normalizeFileName($fileName !== '' ? $fileName : \basename($filePath));
        $this->deleteAfterDownload = $deleteAfterDownload;
        
        // 设置下载相关的 headers
        $headers = [
            'Content-Description' => 'File Transfer',
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => self::contentDisposition($this->fileName),
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
        [$stream, $openedStat] = $this->openRegularFile();
        $cleanupFailed = false;
        try {
            $this->headers['Content-Length'] = (string)(int)$openedStat['size'];
            if (!\headers_sent()) {
                foreach ($this->headers as $name => $value) {
                    \header("{$name}: {$value}");
                }
            }
            $remaining = (int)$openedStat['size'];
            while ($remaining > 0) {
                $chunk = @\fread($stream, \min(self::READ_CHUNK_BYTES, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException((string)__('下载文件读取失败。'));
                }
                echo $chunk;
                $remaining -= \strlen($chunk);
            }
        } finally {
            $cleanupFailed = !@\fclose($stream);
            if ($this->deleteAfterDownload) {
                $cleanupFailed = !$this->unlinkOpenedFile($openedStat) || $cleanupFailed;
            }
            if ($cleanupFailed) {
                throw new \RuntimeException((string)__('下载文件清理失败。'));
            }
        }

        if ($terminate) {
            exit(0);
        }
    }
    
    /**
     * 构建兼容响应字符串。
     *
     * 正式 WLS 传输由 WlsStreamedResponse 分块发送。这个旧接口只允许
     * 小文件，避免调用者误把大对象再次拼接成完整内存字符串。
     */
    public function toHttpString(): string
    {
        [$stream, $openedStat] = $this->openRegularFile();
        $fileSize = (int)$openedStat['size'];
        if ($fileSize > self::LEGACY_BUFFER_MAX_BYTES) {
            $cleanupFailed = !@\fclose($stream);
            if ($this->deleteAfterDownload) {
                $cleanupFailed = !$this->unlinkOpenedFile($openedStat) || $cleanupFailed;
            }
            if ($cleanupFailed) {
                throw new \RuntimeException((string)__('下载文件清理失败。'));
            }
            throw new \RuntimeException((string)__('大文件下载必须使用流式响应。'));
        }

        $cleanupFailed = false;
        try {
            $this->headers['Content-Length'] = (string)$fileSize;
            $statusText = $this->getStatusText($this->statusCode);
            $response = "HTTP/1.1 {$this->statusCode} {$statusText}\r\n";
            foreach ($this->headers as $name => $value) {
                $response .= "{$name}: {$value}\r\n";
            }
            $response .= "Connection: close\r\n\r\n";

            $remaining = $fileSize;
            while ($remaining > 0) {
                $chunk = @\fread($stream, \min(self::READ_CHUNK_BYTES, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException((string)__('下载文件读取失败。'));
                }
                $response .= $chunk;
                $remaining -= \strlen($chunk);
            }
        } finally {
            $cleanupFailed = !@\fclose($stream);
            if ($this->deleteAfterDownload) {
                $cleanupFailed = !$this->unlinkOpenedFile($openedStat) || $cleanupFailed;
            }
            if ($cleanupFailed) {
                throw new \RuntimeException((string)__('下载文件清理失败。'));
            }
        }

        return $response;
    }

    /** @return array{0:resource,1:array<string|int,mixed>} */
    private function openRegularFile(): array
    {
        $pathStat = null;
        if ($this->deleteAfterDownload) {
            $pathStat = @\lstat($this->filePath);
            if (!\is_array($pathStat)
                || \is_link($this->filePath)
                || (((int)($pathStat['mode'] ?? 0)) & 0170000) !== 0100000
                || (int)($pathStat['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException((string)__('下载临时文件身份无效。'));
            }
        }
        $stream = @\fopen($this->filePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('下载文件不可用。'));
        }
        $stat = @\fstat($stream);
        if (!\is_array($stat)
            || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($stat['size'] ?? -1) < 0
        ) {
            @\fclose($stream);
            throw new \RuntimeException((string)__('下载文件状态无效。'));
        }
        if ($pathStat !== null
            && ((int)($stat['dev'] ?? -1) !== (int)($pathStat['dev'] ?? -2)
                || (int)($stat['ino'] ?? -1) !== (int)($pathStat['ino'] ?? -2)
                || (int)($stat['nlink'] ?? 0) !== 1)
        ) {
            @\fclose($stream);
            throw new \RuntimeException((string)__('下载临时文件身份无效。'));
        }
        return [$stream, $stat];
    }

    /** @param array<string|int,mixed> $openedStat */
    private function unlinkOpenedFile(array $openedStat): bool
    {
        if (!\file_exists($this->filePath) && !\is_link($this->filePath)) {
            return true;
        }
        $current = @\lstat($this->filePath);
        if (!\is_array($current)
            || \is_link($this->filePath)
            || (((int)($current['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($current['nlink'] ?? 0) !== 1
            || (int)($current['dev'] ?? -1) !== (int)($openedStat['dev'] ?? -2)
            || (int)($current['ino'] ?? -1) !== (int)($openedStat['ino'] ?? -2)
        ) {
            return false;
        }
        return @\unlink($this->filePath);
    }

    private static function normalizeFileName(string $fileName): string
    {
        $fileName = \basename(\str_replace('\\', '/', \trim($fileName)));
        if ($fileName === ''
            || $fileName === '.'
            || $fileName === '..'
            || \preg_match('//u', $fileName) !== 1
        ) {
            return 'download';
        }
        $fileName = (string)\preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName);
        $fileName = \trim($fileName, " .\t");
        if ($fileName === '') {
            return 'download';
        }
        if (\strlen($fileName) > 240) {
            $fileName = \function_exists('mb_strcut')
                ? (string)\mb_strcut($fileName, 0, 240, 'UTF-8')
                : \substr($fileName, 0, 240);
        }
        return $fileName !== '' ? $fileName : 'download';
    }

    private static function contentDisposition(string $fileName): string
    {
        $fallback = (string)\preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);
        $fallback = \trim($fallback, '._-');
        if ($fallback === '') {
            $fallback = 'download';
        }
        $fallback = \substr($fallback, 0, 150);
        return 'attachment; filename="' . \addcslashes($fallback, "\\\"")
            . '"; filename*=UTF-8\'\'' . \rawurlencode($fileName);
    }
}
