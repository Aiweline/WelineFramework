<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

/**
 * 文件下载工具类
 * 
 * 遵循 SOLID 原则：
 * - 单一职责：只负责文件下载
 * - 开闭原则：通过回调函数扩展功能
 * - 依赖倒置：依赖回调函数抽象，不依赖具体实现
 */
class Downloader
{
    /**
     * 默认超时时间（秒）
     */
    private const DEFAULT_TIMEOUT = 300;

    /**
     * 默认块大小（字节）
     */
    private const DEFAULT_CHUNK_SIZE = 8192;

    /**
     * 进度更新最小间隔（秒）
     */
    private const PROGRESS_UPDATE_INTERVAL = 0.3;

    /**
     * 速度计算最小间隔（秒）
     */
    private const SPEED_CALC_INTERVAL = 0.5;

    /**
     * 进度更新最小字节数
     */
    private const PROGRESS_UPDATE_MIN_BYTES = 256 * 1024;

    /**
     * 超时时间
     */
    private int $timeout;

    /**
     * 块大小
     */
    private int $chunkSize;

    /**
     * 进度回调函数
     * 
     * @var callable|null function(int $downloaded, int $total, float $percent, float $speedMBPerSec, string $remainingTime): void
     */
    private $progressCallback = null;

    /**
     * 构造函数
     */
    public function __construct(int $timeout = self::DEFAULT_TIMEOUT, int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        $this->timeout = $timeout;
        $this->chunkSize = $chunkSize;
    }

    /**
     * 设置进度回调函数
     * 
     * @param callable $callback function(int $downloaded, int $total, float $percent, float $speedMBPerSec, string $remainingTime): void
     * @return $this
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * 下载文件
     * 
     * @param string $url 下载URL
     * @param string $targetPath 目标文件路径
     * @return array ['success' => bool, 'error' => string|null, 'downloaded' => int, 'totalTime' => float]
     */
    public function download(string $url, string $targetPath): array
    {
        // 获取文件大小
        $contentLength = $this->getContentLength($url);
        
        // 创建流上下文
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'follow_location' => true,
                'max_redirects' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        // 打开远程文件
        $handle = @fopen($url, 'rb', false, $context);
        if (!$handle) {
            return [
                'success' => false,
                'error' => __('无法打开URL'),
                'downloaded' => 0,
                'totalTime' => 0,
            ];
        }

        // 创建目标文件
        $fileHandle = @fopen($targetPath, 'wb');
        if (!$fileHandle) {
            @fclose($handle);
            return [
                'success' => false,
                'error' => __('无法创建目标文件'),
                'downloaded' => 0,
                'totalTime' => 0,
            ];
        }

        // 下载变量
        $downloaded = 0;
        $lastProgress = 0;
        $lastProgressTime = microtime(true);
        $startTime = microtime(true);
        $lastSpeedCheckTime = $startTime;
        $lastSpeedCheckBytes = 0;
        $speedBytesPerSec = 0;
        $speedMBPerSec = 0;

        // 开始下载
        while (!feof($handle)) {
            $chunk = @fread($handle, $this->chunkSize);
            if ($chunk === false) {
                break;
            }
            
            $written = @fwrite($fileHandle, $chunk);
            if ($written === false) {
                @fclose($handle);
                @fclose($fileHandle);
                @unlink($targetPath);
                return [
                    'success' => false,
                    'error' => __('写入文件失败'),
                    'downloaded' => $downloaded,
                    'totalTime' => microtime(true) - $startTime,
                ];
            }

            $downloaded += $written;
            $currentTime = microtime(true);

            // 检查是否需要更新进度
            $shouldUpdate = false;
            if ($contentLength > 0) {
                // 有文件大小：每下载一定字节或每一定时间更新一次
                if (($downloaded - $lastProgress >= self::PROGRESS_UPDATE_MIN_BYTES) || 
                    ($currentTime - $lastProgressTime >= self::PROGRESS_UPDATE_INTERVAL) ||
                    ($downloaded >= $contentLength)) {
                    $shouldUpdate = true;
                }
            } else {
                // 没有文件大小：每下载一定字节或每一定时间更新一次
                if (($downloaded - $lastProgress >= self::PROGRESS_UPDATE_MIN_BYTES * 2) || 
                    ($currentTime - $lastProgressTime >= self::PROGRESS_UPDATE_INTERVAL * 2)) {
                    $shouldUpdate = true;
                }
            }

            // 计算下载速度
            if ($shouldUpdate) {
                $elapsed = $currentTime - $lastSpeedCheckTime;
                if ($elapsed >= self::SPEED_CALC_INTERVAL) {
                    $bytesDiff = $downloaded - $lastSpeedCheckBytes;
                    $speedBytesPerSec = $bytesDiff / $elapsed;
                    $speedMBPerSec = $speedBytesPerSec / 1024 / 1024;
                    $lastSpeedCheckTime = $currentTime;
                    $lastSpeedCheckBytes = $downloaded;
                }

                // 计算剩余时间（返回原始数值和单位，由调用者翻译）
                $remainingTime = '';
                if ($contentLength > 0 && $speedMBPerSec > 0.1) {
                    $remainingBytes = $contentLength - $downloaded;
                    $remainingSeconds = $remainingBytes / $speedBytesPerSec;
                    if ($remainingSeconds < 60) {
                        $remainingTime = sprintf('%.0f|秒', $remainingSeconds);
                    } elseif ($remainingSeconds < 3600) {
                        $remainingTime = sprintf('%.1f|分钟', $remainingSeconds / 60);
                    } else {
                        $remainingTime = sprintf('%.1f|小时', $remainingSeconds / 3600);
                    }
                }

                // 调用进度回调
                if ($this->progressCallback !== null) {
                    $percent = $contentLength > 0 ? round(($downloaded / $contentLength) * 100, 1) : 0;
                    call_user_func($this->progressCallback, $downloaded, $contentLength, $percent, $speedMBPerSec, $remainingTime);
                }

                $lastProgress = $downloaded;
                $lastProgressTime = $currentTime;
            }
        }

        // 关闭文件句柄
        @fclose($handle);
        @fclose($fileHandle);

        $totalTime = microtime(true) - $startTime;

        // 检查下载是否成功
        if ($downloaded === 0) {
            @unlink($targetPath);
            return [
                'success' => false,
                'error' => __('未下载任何数据'),
                'downloaded' => 0,
                'totalTime' => $totalTime,
            ];
        }

        return [
            'success' => true,
            'error' => null,
            'downloaded' => $downloaded,
            'totalTime' => $totalTime,
        ];
    }

    /**
     * 获取文件大小
     * 
     * @param string $url
     * @return int 文件大小（字节），如果无法获取则返回0
     */
    private function getContentLength(string $url): int
    {
        $headers = @get_headers($url, true);
        if (!$headers || !isset($headers['Content-Length'])) {
            return 0;
        }

        $contentLength = is_array($headers['Content-Length']) 
            ? (int)end($headers['Content-Length']) 
            : (int)$headers['Content-Length'];

        return $contentLength;
    }

    /**
     * 格式化文件大小
     * 
     * @param int $bytes
     * @return string
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
