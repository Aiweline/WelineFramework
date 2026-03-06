<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\System\File\Downloader;
use Weline\AutoLeadAgent\Model\WasmHash;

/**
 * WASM编译服务
 * 
 * 负责检测、安装依赖、编译和管理WASM模块
 * 使用 WASI SDK 便携预编译工具链
 */
class WasmCompileService
{
    /**
     * 模块路径
     */
    private string $modulePath;

    /**
     * WASM根目录
     */
    private string $wasmPath;

    /**
     * WASM源码路径
     */
    private string $wasmSrcPath;

    /**
     * WASM输出路径
     */
    private string $wasmOutputPath;

    /**
     * 依赖安装路径
     */
    private string $depsPath;

    /**
     * 打印输出（可选）
     */
    private ?Printing $printing = null;

    /**
     * WASI SDK 版本
     */
    private const WASI_SDK_VERSION = '24';

    /**
     * WASI SDK 下载URL模板
     */
    private const WASI_SDK_URL_TEMPLATE = 'https://github.com/WebAssembly/wasi-sdk/releases/download/wasi-sdk-%s/wasi-sdk-%s.0-%s.tar.gz';

    /**
     * 下载重试次数
     */
    private const DOWNLOAD_RETRY_COUNT = 3;

    /**
     * 下载超时时间（秒）
     */
    private const DOWNLOAD_TIMEOUT = 300;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->modulePath = BP . 'app/code/Weline/AutoLeadAgent/';
        $this->wasmPath = $this->modulePath . 'wasm/';
        $this->wasmSrcPath = $this->wasmPath . 'src/';
        $this->wasmOutputPath = $this->wasmPath . 'output/';
        $this->depsPath = $this->wasmPath . 'deps/';
    }

    /**
     * 设置打印输出实例
     */
    public function setPrinting(Printing $printing): self
    {
        $this->printing = $printing;
        return $this;
    }

    /**
     * 输出消息
     */
    private function output(string $message, string $type = 'note'): void
    {
        if ($this->printing) {
            match ($type) {
                'success' => $this->printing->success($message),
                'warning' => $this->printing->warning($message),
                'error' => $this->printing->error($message),
                default => $this->printing->note($message),
            };
            // 立即刷新输出缓冲区，确保实时显示
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * 检查编译环境
     */
    public function checkEnvironment(): array
    {
        $result = [
            'wasi_sdk' => false,
            'wasi_sdk_path' => null,
            'source_exists' => false,
        ];

        // 检查本地安装的 WASI SDK
        $wasiSdkPath = $this->depsPath . 'wasi-sdk/';
        if (is_dir($wasiSdkPath)) {
            $clangPath = $this->getWasiClangPath($wasiSdkPath);
            if ($clangPath && file_exists($clangPath)) {
                $result['wasi_sdk'] = true;
                $result['wasi_sdk_path'] = $wasiSdkPath;
            }
        }

        // 检查源码是否存在
        $result['source_exists'] = file_exists($this->wasmSrcPath . 'agent_core.cpp');

        return $result;
    }

    /**
     * 获取 WASI SDK clang 路径
     */
    private function getWasiClangPath(string $wasiSdkPath): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $wasiSdkPath . 'bin/clang.exe';
        }
        return $wasiSdkPath . 'bin/clang';
    }

    /**
     * 自动安装依赖
     */
    public function installDependencies(): array
    {
        $result = [
            'success' => true,
            'installed' => [],
            'errors' => [],
        ];

        // 创建依赖目录
        if (!is_dir($this->depsPath)) {
            mkdir($this->depsPath, 0755, true);
        }

        // 检查环境
        $envCheck = $this->checkEnvironment();
        
        // 安装 WASI SDK
        if (!$envCheck['wasi_sdk']) {
            $this->output(__('正在安装 WASI SDK（便携编译工具）...'));
            $wasiResult = $this->installWasiSdk();
            if ($wasiResult['success']) {
                $result['installed'][] = 'wasi-sdk';
                $this->output(__('✓ WASI SDK 安装成功'), 'success');
            } else {
                $result['errors'][] = 'wasi-sdk: ' . $wasiResult['error'];
                $this->output(__('✗ WASI SDK 安装失败：%{1}', [$wasiResult['error']]), 'error');
                $result['success'] = false;
            }
        } else {
            $this->output(__('✓ WASI SDK 已安装'), 'success');
        }

        return $result;
    }

    /**
     * 安装 WASI SDK
     * 
     * @param bool $forceReinstall 是否强制重新安装（即使已存在）
     */
    private function installWasiSdk(bool $forceReinstall = false): array
    {
        $wasiSdkPath = $this->depsPath . 'wasi-sdk/';
        
        // 检查是否已安装（除非强制重装）
        if (!$forceReinstall) {
            $clangPath = $this->getWasiClangPath($wasiSdkPath);
            if ($clangPath && file_exists($clangPath)) {
                $this->output(__('✓ WASI SDK 已存在，跳过下载'), 'success');
                return ['success' => true];
            }
        }
        
        // 确定平台
        $platform = $this->getWasiSdkPlatform();
        if (!$platform) {
            return [
                'success' => false,
                'error' => __('不支持的操作系统：%{1}', [PHP_OS_FAMILY]),
            ];
        }

        // 构建下载URL
        $version = self::WASI_SDK_VERSION;
        $url = sprintf(self::WASI_SDK_URL_TEMPLATE, $version, $version, $platform);
        
        $this->output(__('下载地址：%{1}', [$url]));
        $this->output(__('正在下载 WASI SDK（约50MB，请耐心等待）...'));

        // 清理旧的下载文件
        $this->cleanOldDownloads();

        // 下载文件
        $tarFile = $this->depsPath . 'wasi-sdk-download.tar.gz';
        $downloadResult = $this->downloadFile($url, $tarFile);
        
        if (!$downloadResult['success']) {
            return $downloadResult;
        }

        // 验证下载文件大小（WASI SDK 应该至少有 40MB）
        $fileSize = filesize($tarFile);
        if ($fileSize < 40 * 1024 * 1024) {
            @unlink($tarFile);
            return [
                'success' => false,
                'error' => __('下载文件不完整（%{1}），请重试', [$this->formatFileSize($fileSize)]),
            ];
        }

        $this->output(__('下载完成（%{1}），正在解压...', [$this->formatFileSize($fileSize)]));

        // 解压
        $extractResult = $this->extractTarGz($tarFile, $this->depsPath);
        if (!$extractResult['success']) {
            @unlink($tarFile);
            return $extractResult;
        }

        // 查找解压后的目录（可能名称不同）
        $extractedDir = null;
        $expectedDir = $this->depsPath . 'wasi-sdk-' . $version . '.0';
        if (is_dir($expectedDir)) {
            $extractedDir = $expectedDir;
        } else {
            // 查找所有以 wasi-sdk 开头的目录
            $dirs = glob($this->depsPath . 'wasi-sdk*', GLOB_ONLYDIR);
            if (!empty($dirs)) {
                // 排除目标目录
                $dirs = array_filter($dirs, function($dir) use ($wasiSdkPath) {
                    return $dir !== $wasiSdkPath && is_dir($dir);
                });
                if (!empty($dirs)) {
                    $extractedDir = reset($dirs);
                }
            }
        }

        if ($extractedDir && is_dir($extractedDir)) {
            // 如果目标目录已存在，先删除
            if (is_dir($wasiSdkPath)) {
                $this->output(__('清理旧的 WASI SDK 安装...'));
                if (!$this->recursiveDelete($wasiSdkPath)) {
                    // 如果删除失败，尝试重命名旧目录
                    $oldPath = $wasiSdkPath . '.old.' . time();
                    if (@rename($wasiSdkPath, $oldPath)) {
                        $this->output(__('已将旧安装重命名为备份目录'));
                    } else {
                        return [
                            'success' => false,
                            'error' => __('无法删除或重命名旧的 WASI SDK 目录，可能是权限问题或文件被占用。请手动删除：%{1}', [$wasiSdkPath]),
                        ];
                    }
                }
            }
            
            // 重命名新目录
            if (!@rename($extractedDir, $wasiSdkPath)) {
                // 如果重命名失败，尝试复制
                $this->output(__('重命名失败，尝试复制文件...'));
                if (!$this->recursiveCopy($extractedDir, $wasiSdkPath)) {
                    return [
                        'success' => false,
                        'error' => __('无法安装 WASI SDK：权限不足或文件被占用。请检查目录权限：%{1}', [$this->depsPath]),
                    ];
                }
                // 复制成功后删除原目录
                $this->recursiveDelete($extractedDir);
            }
        } else {
            // 解压后的目录不存在
            return [
                'success' => false,
                'error' => __('解压后的目录未找到，解压可能失败。请检查：%{1}', [$this->depsPath]),
            ];
        }

        // 清理下载文件
        @unlink($tarFile);

        // 验证安装（添加更详细的错误信息）
        $clangPath = $this->getWasiClangPath($wasiSdkPath);
        if (!$clangPath) {
            return [
                'success' => false,
                'error' => __('WASI SDK 安装后验证失败：无法获取 clang 路径'),
            ];
        }
        
        if (!file_exists($clangPath)) {
            // 检查目录是否存在
            $binDir = dirname($clangPath);
            $details = [];
            if (!is_dir($wasiSdkPath)) {
                $details[] = __('WASI SDK 目录不存在：%{1}', [$wasiSdkPath]);
            } elseif (!is_dir($binDir)) {
                $details[] = __('bin 目录不存在：%{1}', [$binDir]);
            } else {
                // 列出 bin 目录中的文件
                $binFiles = glob($binDir . '/*');
                $details[] = __('期望的文件：%{1}', [$clangPath]);
                $details[] = __('bin 目录中的文件：%{1}', [implode(', ', array_map('basename', $binFiles))]);
            }
            
            return [
                'success' => false,
                'error' => __('WASI SDK 安装后验证失败：clang 文件不存在。%{1}', [implode(' ', $details)]),
            ];
        }

        return ['success' => true];
    }

    /**
     * 清理旧的下载文件（只清理超过7天的文件，保留缓存）
     */
    private function cleanOldDownloads(): void
    {
        $files = glob($this->depsPath . 'wasi-sdk*.tar.gz');
        if ($files) {
            $cacheDays = 7;
            $cacheSeconds = $cacheDays * 24 * 60 * 60;
            $now = time();
            
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $fileMtime = filemtime($file);
                    $fileAge = $now - $fileMtime;
                    
                    // 只删除超过7天的文件
                    if ($fileAge >= $cacheSeconds) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    /**
     * 获取 WASI SDK 平台标识
     */
    private function getWasiSdkPlatform(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'x86_64-windows';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // 检测是否是 ARM Mac
            $output = [];
            @exec('uname -m 2>&1', $output);
            $arch = trim($output[0] ?? 'x86_64');
            return $arch === 'arm64' ? 'arm64-macos' : 'x86_64-macos';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            return 'x86_64-linux';
        }
        return null;
    }

    /**
     * 下载文件（带重试机制和7天缓存）
     */
    private function downloadFile(string $url, string $targetPath): array
    {
        // 检查缓存：如果文件存在且在7天内，跳过下载
        if (file_exists($targetPath)) {
            $fileMtime = filemtime($targetPath);
            $fileAge = time() - $fileMtime;
            $cacheDays = 7;
            $cacheSeconds = $cacheDays * 24 * 60 * 60;
            
            if ($fileAge < $cacheSeconds) {
                $fileSize = filesize($targetPath);
                $remainingDays = round(($cacheSeconds - $fileAge) / (24 * 60 * 60), 1);
                $this->output(__('使用缓存文件（%{1}，剩余 %{2} 天有效）', [
                    $this->formatFileSize($fileSize),
                    $remainingDays
                ]), 'success');
                return ['success' => true];
            } else {
                // 文件超过7天，删除旧文件
                $this->output(__('缓存文件已过期（%{1} 天），重新下载...', [round($fileAge / (24 * 60 * 60), 1)]));
                @unlink($targetPath);
                if (PHP_OS_FAMILY === 'Windows') {
                    sleep(1);
                }
            }
        }

        $lastError = '';
        
        for ($retry = 1; $retry <= self::DOWNLOAD_RETRY_COUNT; $retry++) {
            if ($retry > 1) {
                $this->output(__('第 %{1} 次重试...', [$retry]));
                sleep(2);
                // 清理可能存在的部分下载文件
                @unlink($targetPath);
            }

            $result = $this->doDownload($url, $targetPath);
            
            // 检查下载是否成功：文件存在且大小大于0
            if ($result['success'] && file_exists($targetPath)) {
                $fileSize = filesize($targetPath);
                if ($fileSize > 0) {
                    // 下载成功，立即返回，不再重试
                    return ['success' => true];
                } else {
                    // 文件大小为0，删除并重试
                    $lastError = __('下载的文件大小为0');
                    @unlink($targetPath);
                }
            } else {
                // 下载失败
                $lastError = $result['error'] ?? __('下载失败');
                @unlink($targetPath);
            }
        }

        return [
            'success' => false,
            'error' => __('下载失败（已重试%{1}次）：%{2}', [self::DOWNLOAD_RETRY_COUNT, $lastError]),
        ];
    }

    /**
     * 执行下载（使用 System\File\Downloader，遵循 SOLID 原则）
     */
    private function doDownload(string $url, string $targetPath): array
    {
        $this->output(__('使用 PHP 下载（显示进度）...'));
        
        // 创建下载器实例
        $downloader = new Downloader(self::DOWNLOAD_TIMEOUT);
        
        // 用于保存文件总大小（在回调函数中获取）
        $fileTotalSize = 0;
        
        // 设置进度回调函数
        $downloader->setProgressCallback(function (int $downloaded, int $total, float $percent, float $speedMBPerSec, string $remainingTime) use (&$fileTotalSize) {
            if ($total > 0) {
                $fileTotalSize = $total;
            }
            $this->displayProgress($downloaded, $total, $percent, $speedMBPerSec, $remainingTime);
        });
        
        // 执行下载
        $result = $downloader->download($url, $targetPath);
        
        // 显示完成信息
        if ($result['success']) {
            $totalSize = $fileTotalSize > 0 ? $fileTotalSize : $result['downloaded'];
            $this->displayCompletion($result['downloaded'], $totalSize, $result['totalTime']);
        }
        
        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * 显示下载进度
     */
    private function displayProgress(int $downloaded, int $total, float $percent, float $speedMBPerSec, string $remainingTime): void
    {
        if ($total > 0) {
            $downloadedFormatted = Downloader::formatFileSize($downloaded);
            $totalFormatted = Downloader::formatFileSize($total);
            
            // 格式化速度显示
            $speedText = '';
            if ($speedMBPerSec > 0.1) {
                if ($speedMBPerSec < 1) {
                    $speedText = ' ' . __('速度: %{1} MB/s', sprintf('%.2f', $speedMBPerSec));
                } else {
                    $speedText = ' ' . __('速度: %{1} MB/s', sprintf('%.1f', $speedMBPerSec));
                }
            }
            
            // 格式化剩余时间（处理翻译）
            $remainingText = '';
            if ($remainingTime) {
                // 剩余时间格式：数值|单位，需要分离并翻译
                if (strpos($remainingTime, '|') !== false) {
                    [$value, $unit] = explode('|', $remainingTime, 2);
                    $translatedUnit = __('%{1}', $unit); // 翻译单位
                    $remainingText = ' ' . __('剩余: %{1}%{2}', [$value, $translatedUnit]);
                } else {
                    $remainingText = ' ' . __('剩余: %{1}', $remainingTime);
                }
            }
            
            // 构建友好的进度显示
            $progressText = sprintf(
                '%s [%s%%] %s / %s%s%s',
                __('下载中'),
                $percent,
                $downloadedFormatted,
                $totalFormatted,
                $speedText,
                $remainingText
            );
            
            // 输出进度信息（使用 \r 覆盖同一行）
            echo "\r" . $progressText;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        } else {
            // 没有文件大小，显示已下载量和速度
            $downloadedFormatted = Downloader::formatFileSize($downloaded);
            $speedText = '';
            if ($speedMBPerSec > 0.1) {
                if ($speedMBPerSec < 1) {
                    $speedText = ' ' . __('速度: %{1} MB/s', sprintf('%.2f', $speedMBPerSec));
                } else {
                    $speedText = ' ' . __('速度: %{1} MB/s', sprintf('%.1f', $speedMBPerSec));
                }
            }
            
            $progressText = sprintf('%s: %s%s', __('已下载'), $downloadedFormatted, $speedText);
            echo "\r" . $progressText;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }
    }

    /**
     * 显示下载完成信息
     */
    private function displayCompletion(int $downloaded, int $total, float $totalTime): void
    {
        $totalFormatted = Downloader::formatFileSize($total);
        $avgSpeedMBPerSec = ($totalTime > 0) ? ($downloaded / 1024 / 1024) / $totalTime : 0;
        
        $timeText = '';
        if ($totalTime < 60) {
            $timeText = '，' . __('耗时: %{1}秒', sprintf('%.1f', $totalTime));
        } elseif ($totalTime < 3600) {
            $timeText = '，' . __('耗时: %{1}分钟', sprintf('%.1f', $totalTime / 60));
        } else {
            $timeText = '，' . __('耗时: %{1}小时', sprintf('%.1f', $totalTime / 3600));
        }
        
        $speedText = '';
        if ($avgSpeedMBPerSec > 0.1) {
            if ($avgSpeedMBPerSec < 1) {
                $speedText = '，' . __('平均速度: %{1} MB/s', sprintf('%.2f', $avgSpeedMBPerSec));
            } else {
                $speedText = '，' . __('平均速度: %{1} MB/s', sprintf('%.1f', $avgSpeedMBPerSec));
            }
        }
        
        echo sprintf("\r%s: 100%% (%s)%s%s\n", __('下载完成'), $totalFormatted, $timeText, $speedText);
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * 检查命令是否可用
     */
    private function checkCommandAvailable(string $command): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: 使用 where 命令检查
            $output = [];
            $returnCode = 0;
            exec("where $command 2>&1", $output, $returnCode);
            return $returnCode === 0;
        } else {
            // Unix: 使用 which 命令检查
            $output = [];
            $returnCode = 0;
            exec("which $command 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }
    }

    /**
     * 执行命令并实时显示进度（使用 proc_open 实时读取输出）
     * 
     * @param string $cmd 要执行的命令
     * @param int &$returnCode 返回码（引用传递）
     * @return string|false 输出内容，失败返回 false
     */
    private function executeWithProgress(string $cmd, int &$returnCode): string|false
    {
        // 使用 proc_open 实时读取输出，避免 exec() 阻塞问题
        if (!function_exists('proc_open')) {
            // 如果 proc_open 不可用，回退到 exec
            $output = [];
            $success = Processer::execute($cmd, $output, $returnCode);
            return $success ? implode("\n", $output) : false;
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            $returnCode = -1;
            return false;
        }

        // 关闭 stdin（不需要输入）
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }

        // 设置非阻塞模式以便实时读取
        if (isset($pipes[1])) {
            stream_set_blocking($pipes[1], false);
        }
        if (isset($pipes[2])) {
            stream_set_blocking($pipes[2], false);
        }

        $output = '';
        $stderr = '';
        $startTime = time();
        $timeout = self::DOWNLOAD_TIMEOUT + 60; // 额外60秒缓冲
        $lastOutputTime = $startTime;
        $noOutputTimeout = 30; // 30秒没有输出就认为可能卡住了

        // 实时读取输出
        while (true) {
            $status = proc_get_status($process);
            
            // 检查超时
            $elapsed = time() - $startTime;
            if ($elapsed > $timeout) {
                @proc_terminate($process);
                @proc_close($process);
                $returnCode = -1;
                return false;
            }

            $hasOutput = false;

            // 使用 stream_select 检查是否有数据可读（更高效）
            // 注意：在 Windows 上，stream_select 可能对某些流类型不工作，所以需要回退机制
            $read = [];
            if (isset($pipes[1]) && !feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (isset($pipes[2]) && !feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if (!empty($read)) {
                $write = null;
                $except = null;
                $changed = @stream_select($read, $write, $except, 0, 100000); // 0.1秒超时
                
                if ($changed > 0) {
                    // 读取 stdout
                    if (isset($pipes[1]) && in_array($pipes[1], $read, true) && !feof($pipes[1])) {
                        $chunk = @fread($pipes[1], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $output .= $chunk;
                            $hasOutput = true;
                            // 实时输出进度
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }

                    // 读取 stderr（curl 的进度条通常输出到 stderr）
                    if (isset($pipes[2]) && in_array($pipes[2], $read, true) && !feof($pipes[2])) {
                        $chunk = @fread($pipes[2], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $stderr .= $chunk;
                            $hasOutput = true;
                            // stderr 也输出（curl 的进度条在这里）
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                } elseif ($changed === false && PHP_OS_FAMILY === 'Windows') {
                    // Windows 上 stream_select 可能失败，使用直接读取方式
                    // 读取 stdout
                    if (isset($pipes[1]) && !feof($pipes[1])) {
                        $chunk = @fread($pipes[1], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $output .= $chunk;
                            $hasOutput = true;
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                    // 读取 stderr
                    if (isset($pipes[2]) && !feof($pipes[2])) {
                        $chunk = @fread($pipes[2], 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $stderr .= $chunk;
                            $hasOutput = true;
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                }
            }

            // 更新最后输出时间
            if ($hasOutput) {
                $lastOutputTime = time();
            } else {
                // 如果进程还在运行但长时间没有输出，可能卡住了
                if ($status['running'] && (time() - $lastOutputTime) > $noOutputTimeout) {
                    @proc_terminate($process);
                    @proc_close($process);
                    $returnCode = -1;
                    return false;
                }
            }

            // 检查进程是否已结束
            if ($status['running'] === false) {
                // 进程已结束，读取剩余数据
                if (isset($pipes[1]) && !feof($pipes[1])) {
                    $remaining = @stream_get_contents($pipes[1]);
                    if ($remaining !== false && $remaining !== '') {
                        $output .= $remaining;
                        echo $remaining;
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                }
                if (isset($pipes[2]) && !feof($pipes[2])) {
                    $remaining = @stream_get_contents($pipes[2]);
                    if ($remaining !== false && $remaining !== '') {
                        $stderr .= $remaining;
                        echo $remaining;
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                }
                break;
            }

            // 避免 CPU 占用过高
            usleep(50000); // 0.05秒
        }

        // 关闭管道
        if (isset($pipes[1])) {
            @fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            @fclose($pipes[2]);
        }

        // 获取最终状态
        $finalStatus = proc_get_status($process);
        $returnCode = $finalStatus['exitcode'] ?? -1;
        @proc_close($process);

        // 如果有错误输出且没有正常输出，返回错误
        if (empty($output) && !empty($stderr)) {
            return false;
        }

        return $output ?: $stderr;
    }

    /**
     * 使用 PowerShell 下载（Windows 专用，带进度显示）
     */
    private function downloadWithPowerShell(string $url, string $targetPath): array
    {
        // 使用 PowerShell 的 Invoke-WebRequest，支持进度显示
        $psScript = sprintf(
            '$ProgressPreference = "Continue"; ' .
            '[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; ' .
            'try { ' .
            '  $response = Invoke-WebRequest -Uri "%s" -OutFile "%s" -UseBasicParsing -ErrorAction Stop; ' .
            '  exit 0; ' .
            '} catch { ' .
            '  Write-Host $_.Exception.Message; ' .
            '  exit 1; ' .
            '}',
            addslashes($url),
            addslashes($targetPath)
        );

        $cmd = sprintf('powershell -NoProfile -ExecutionPolicy Bypass -Command "%s"', addslashes($psScript));
        
        $returnCode = 0;
        $result = $this->executeWithProgress($cmd, $returnCode);

        if ($returnCode === 0 && file_exists($targetPath) && filesize($targetPath) > 1024) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => $result !== false ? $result : __('PowerShell 下载失败'),
        ];
    }

    /**
     * 使用 PHP 内置函数下载（带进度显示，使用系统进度条）
     */
    private function downloadWithPhp(string $url, string $targetPath): array
    {
        // 获取文件大小
        $headers = @get_headers($url, true);
        $contentLength = 0;
        if ($headers && isset($headers['Content-Length'])) {
            $contentLength = is_array($headers['Content-Length']) 
                ? (int)end($headers['Content-Length']) 
                : (int)$headers['Content-Length'];
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'follow_location' => true,
                'max_redirects' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if (!$handle) {
            return [
                'success' => false,
                'error' => __('PHP fopen 打开URL失败'),
            ];
        }

        $fileHandle = @fopen($targetPath, 'wb');
        if (!$fileHandle) {
            @fclose($handle);
            return [
                'success' => false,
                'error' => __('无法创建目标文件'),
            ];
        }

        $downloaded = 0;
        $chunkSize = 8192; // 8KB chunks
        $lastProgress = 0;
        $lastProgressTime = microtime(true);
        $startTime = microtime(true);
        $lastSpeedCheckTime = $startTime;
        $lastSpeedCheckBytes = 0;

        while (!feof($handle)) {
            $chunk = @fread($handle, $chunkSize);
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
                ];
            }

            $downloaded += $written;

            // 使用系统的进度条显示（每下载 256KB 或每 0.3 秒更新一次，更频繁）
            $currentTime = microtime(true);
            $shouldUpdate = false;
            
            if ($contentLength > 0) {
                // 有文件大小：每下载 256KB 或每 0.3 秒更新一次
                if (($downloaded - $lastProgress >= 256 * 1024) || 
                    ($currentTime - $lastProgressTime >= 0.3) ||
                    ($downloaded >= $contentLength)) {
                    $shouldUpdate = true;
                }
            } else {
                // 没有文件大小：每下载 512KB 或每 0.5 秒更新一次
                if (($downloaded - $lastProgress >= 512 * 1024) || 
                    ($currentTime - $lastProgressTime >= 0.5)) {
                    $shouldUpdate = true;
                }
            }
            
            if ($shouldUpdate) {
                // 计算下载速度（每秒）
                $elapsed = $currentTime - $lastSpeedCheckTime;
                $speedBytesPerSec = 0;
                $speedMBPerSec = 0;
                if ($elapsed > 0.5) { // 至少0.5秒才计算速度，避免波动太大
                    $bytesDiff = $downloaded - $lastSpeedCheckBytes;
                    $speedBytesPerSec = $bytesDiff / $elapsed;
                    $speedMBPerSec = $speedBytesPerSec / 1024 / 1024;
                    $lastSpeedCheckTime = $currentTime;
                    $lastSpeedCheckBytes = $downloaded;
                }
                
                if ($contentLength > 0) {
                    $percent = round(($downloaded / $contentLength) * 100, 1);
                    $downloadedFormatted = $this->formatFileSize($downloaded);
                    $totalFormatted = $this->formatFileSize($contentLength);
                    
                    // 计算剩余时间
                    $remainingTime = '';
                    if ($speedMBPerSec > 0.1) { // 速度大于0.1MB/s才显示剩余时间
                        $remainingBytes = $contentLength - $downloaded;
                        $remainingSeconds = $remainingBytes / $speedBytesPerSec;
                        if ($remainingSeconds < 60) {
                            $remainingTime = sprintf(' 剩余: %.0f秒', $remainingSeconds);
                        } elseif ($remainingSeconds < 3600) {
                            $remainingTime = sprintf(' 剩余: %.1f分钟', $remainingSeconds / 60);
                        } else {
                            $remainingTime = sprintf(' 剩余: %.1f小时', $remainingSeconds / 3600);
                        }
                    }
                    
                    // 格式化速度显示
                    $speedText = '';
                    if ($speedMBPerSec > 0.1) {
                        if ($speedMBPerSec < 1) {
                            $speedText = sprintf(' 速度: %.2f MB/s', $speedMBPerSec);
                        } else {
                            $speedText = sprintf(' 速度: %.1f MB/s', $speedMBPerSec);
                        }
                    }
                    
                    // 构建友好的进度显示
                    $progressText = sprintf(
                        '下载中 [%s%%] %s / %s%s%s',
                        $percent,
                        $downloadedFormatted,
                        $totalFormatted,
                        $speedText,
                        $remainingTime
                    );
                    
                    // 输出进度信息（使用 \r 覆盖同一行）
                    echo "\r" . $progressText;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                } else {
                    // 没有文件大小，显示已下载量和速度
                    $downloadedFormatted = $this->formatFileSize($downloaded);
                    $speedText = '';
                    if ($speedMBPerSec > 0.1) {
                        if ($speedMBPerSec < 1) {
                            $speedText = sprintf(' 速度: %.2f MB/s', $speedMBPerSec);
                        } else {
                            $speedText = sprintf(' 速度: %.1f MB/s', $speedMBPerSec);
                        }
                    }
                    
                    $progressText = sprintf('已下载: %s%s', $downloadedFormatted, $speedText);
                    echo "\r" . $progressText;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
                $lastProgress = $downloaded;
                $lastProgressTime = $currentTime;
            }
        }

        @fclose($handle);
        @fclose($fileHandle);

        if ($downloaded === 0) {
            @unlink($targetPath);
            return [
                'success' => false,
                'error' => __('下载失败：未下载任何数据'),
            ];
        }

        // 显示下载完成信息
        $totalTime = microtime(true) - $startTime;
        $avgSpeedMBPerSec = ($totalTime > 0) ? ($downloaded / 1024 / 1024) / $totalTime : 0;
        
        if ($contentLength > 0) {
            $totalFormatted = $this->formatFileSize($contentLength);
            $timeText = '';
            if ($totalTime < 60) {
                $timeText = sprintf('，耗时: %.1f秒', $totalTime);
            } elseif ($totalTime < 3600) {
                $timeText = sprintf('，耗时: %.1f分钟', $totalTime / 60);
            } else {
                $timeText = sprintf('，耗时: %.1f小时', $totalTime / 3600);
            }
            
            $speedText = '';
            if ($avgSpeedMBPerSec > 0.1) {
                if ($avgSpeedMBPerSec < 1) {
                    $speedText = sprintf('，平均速度: %.2f MB/s', $avgSpeedMBPerSec);
                } else {
                    $speedText = sprintf('，平均速度: %.1f MB/s', $avgSpeedMBPerSec);
                }
            }
            
            echo sprintf("\r下载完成: 100%% (%s)%s%s\n", $totalFormatted, $timeText, $speedText);
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        } else {
            $downloadedFormatted = $this->formatFileSize($downloaded);
            $timeText = '';
            if ($totalTime < 60) {
                $timeText = sprintf('，耗时: %.1f秒', $totalTime);
            } elseif ($totalTime < 3600) {
                $timeText = sprintf('，耗时: %.1f分钟', $totalTime / 60);
            } else {
                $timeText = sprintf('，耗时: %.1f小时', $totalTime / 3600);
            }
            
            $speedText = '';
            if ($avgSpeedMBPerSec > 0.1) {
                if ($avgSpeedMBPerSec < 1) {
                    $speedText = sprintf('，平均速度: %.2f MB/s', $avgSpeedMBPerSec);
                } else {
                    $speedText = sprintf('，平均速度: %.1f MB/s', $avgSpeedMBPerSec);
                }
            }
            
            echo sprintf("\r下载完成: %s%s%s\n", $downloadedFormatted, $timeText, $speedText);
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }

        return ['success' => true];
    }

    /**
     * 解压 tar.gz 文件
     */
    private function extractTarGz(string $tarFile, string $targetDir): array
    {
        $output = [];
        $returnCode = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 使用 tar (Windows 10+ 内置)
            $cmd = sprintf(
                'tar -xzf %s -C %s 2>&1',
                escapeshellarg($tarFile),
                escapeshellarg($targetDir)
            );
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                // 尝试使用 7z
                $output = [];
                $cmd = sprintf(
                    '7z x %s -o%s -y 2>&1',
                    escapeshellarg($tarFile),
                    escapeshellarg($targetDir)
                );
                exec($cmd, $output, $returnCode);
            }
        } else {
            $cmd = sprintf(
                'tar -xzf %s -C %s 2>&1',
                escapeshellarg($tarFile),
                escapeshellarg($targetDir)
            );
            exec($cmd, $output, $returnCode);
        }

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'error' => __('解压失败：%{1}', [implode("\n", $output)]),
            ];
        }

        return ['success' => true];
    }

    /**
     * 检查是否需要编译
     */
    public function needsCompile(): bool
    {
        $wasmFile = $this->wasmOutputPath . 'agent-core.wasm';

        // 如果WASM文件不存在，需要编译
        if (!file_exists($wasmFile)) {
            return true;
        }

        // 获取WASM文件的修改时间
        $wasmMtime = filemtime($wasmFile);

        // 检查源文件是否有更新
        $sourceFiles = [
            $this->wasmSrcPath . 'agent_core.cpp',
            $this->wasmSrcPath . 'agent_core.h',
            $this->wasmSrcPath . 'binding.cpp',
        ];

        foreach ($sourceFiles as $sourceFile) {
            if (file_exists($sourceFile)) {
                $sourceMtime = filemtime($sourceFile);
                if ($sourceMtime > $wasmMtime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 执行编译
     */
    public function compile(): array
    {
        // 确保输出目录存在
        if (!is_dir($this->wasmOutputPath)) {
            mkdir($this->wasmOutputPath, 0755, true);
        }

        $envCheck = $this->checkEnvironment();

        // 使用 WASI SDK 编译
        if ($envCheck['wasi_sdk']) {
            $this->output(__('使用 WASI SDK 编译...'));
            return $this->compileWithWasiSdk($envCheck['wasi_sdk_path']);
        }

        return [
            'success' => false,
            'error' => __('WASI SDK 未安装，请先运行安装'),
        ];
    }

    /**
     * 使用 WASI SDK 编译
     */
    private function compileWithWasiSdk(string $wasiSdkPath): array
    {
        $clangPath = $this->getWasiClangPath($wasiSdkPath);
        $sysrootPath = $wasiSdkPath . 'share/wasi-sysroot';

        $sourceFile = $this->wasmSrcPath . 'agent_core.cpp';
        $targetWasm = $this->wasmOutputPath . 'agent-core.wasm';

        // 使用 clang++ 编译 C++ 代码，指定 sysroot 和目标三元组
        $clangppPath = PHP_OS_FAMILY === 'Windows' 
            ? $wasiSdkPath . 'bin/clang++.exe'
            : $wasiSdkPath . 'bin/clang++';

        // 编译为 wasm32-wasi 目标，支持 C++ 标准库
        $compileCmd = sprintf(
            '%s --target=wasm32-wasi --sysroot=%s -O2 -fno-exceptions -Wl,--export-all -Wl,--no-entry -o %s %s 2>&1',
            escapeshellarg($clangppPath),
            escapeshellarg($sysrootPath),
            escapeshellarg($targetWasm),
            escapeshellarg($sourceFile)
        );

        $this->output(__('执行编译命令...'));
        
        $output = [];
        $returnCode = 0;
        exec($compileCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            // 尝试备选方式：使用 wasm32-wasip1 目标
            $this->output(__('尝试备选编译方式...'));
            $output = [];
            $compileCmd = sprintf(
                '%s --target=wasm32-wasip1 --sysroot=%s -O2 -fno-exceptions -Wl,--export-all -Wl,--no-entry -o %s %s 2>&1',
                escapeshellarg($clangppPath),
                escapeshellarg($sysrootPath),
                escapeshellarg($targetWasm),
                escapeshellarg($sourceFile)
            );
            exec($compileCmd, $output, $returnCode);
        }

        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            
            // 检查是否是权限问题
            if (stripos($errorOutput, 'Access is denied') !== false || 
                stripos($errorOutput, 'Permission denied') !== false) {
                return [
                    'success' => false,
                    'error' => __('WASI SDK 编译失败：权限不足。可能的原因：1) 文件被其他程序占用 2) 目录权限不足 3) 需要管理员权限。请尝试：1) 关闭可能占用文件的程序 2) 以管理员身份运行 3) 检查目录权限：%{1}', [$this->wasmOutputPath]),
                ];
            }
            
            return [
                'success' => false,
                'error' => __('WASI SDK 编译失败：%{1}', [$errorOutput]),
            ];
        }

        if (!file_exists($targetWasm)) {
            return [
                'success' => false,
                'error' => __('编译输出文件不存在'),
            ];
        }

        $hash = hash_file('sha256', $targetWasm);
        $fileSize = $this->formatFileSize(filesize($targetWasm));

        return [
            'success' => true,
            'output_file' => $targetWasm,
            'hash' => $hash,
            'file_size' => $fileSize,
        ];
    }

    /**
     * 注册WASM哈希到数据库
     */
    public function registerHash(string $hash, string $filePath): void
    {
        try {
            /** @var WasmHash $wasmHashModel */
            $wasmHashModel = ObjectManager::getInstance(WasmHash::class);

            // 检查是否已存在相同哈希
            $wasmHashModel->clear()
                ->where(WasmHash::schema_fields_HASH_VALUE, $hash)
                ->find()
                ->fetch();

            $now = date('Y-m-d H:i:s');
            if ($wasmHashModel->getId()) {
                // 更新现有记录
                $wasmHashModel->setData(WasmHash::schema_fields_WASM_PATH, $filePath)
                    ->setData(WasmHash::schema_fields_UPDATED_AT, $now)
                    ->save();
            } else {
                // 创建新记录
                $latestVersion = $this->getLatestVersion();
                $newVersion = $latestVersion + 1;
                $now = date('Y-m-d H:i:s');

                $wasmHashModel->clear()
                    ->setData(WasmHash::schema_fields_WASM_PATH, $filePath)
                    ->setData(WasmHash::schema_fields_HASH_VALUE, $hash)
                    ->setData(WasmHash::schema_fields_VERSION, (string)$newVersion)
                    ->setData(WasmHash::schema_fields_CREATED_AT, $now)
                    ->setData(WasmHash::schema_fields_UPDATED_AT, $now)
                    ->save();
            }
        } catch (\Throwable $e) {
            throw new Exception(__('注册WASM哈希失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 获取最新版本号
     */
    private function getLatestVersion(): int
    {
        try {
            /** @var WasmHash $wasmHashModel */
            $wasmHashModel = ObjectManager::getInstance(WasmHash::class);

            $wasmHashModel->clear()
                ->order(WasmHash::schema_fields_VERSION, 'DESC')
                ->limit(1)
                ->fetch();

            $items = $wasmHashModel->getItems();
            if (!empty($items)) {
                $latest = reset($items);
                return (int)$latest->getData(WasmHash::schema_fields_VERSION);
            }

            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * 获取WASM文件路径
     */
    public function getWasmFilePath(): string
    {
        return $this->wasmOutputPath . 'agent-core.wasm';
    }

    /**
     * 获取WASM源码路径
     */
    public function getWasmSrcPath(): string
    {
        return $this->wasmSrcPath;
    }

    /**
     * 获取依赖路径
     */
    public function getDepsPath(): string
    {
        return $this->depsPath;
    }

    /**
     * 清理构建目录
     */
    public function cleanBuild(): bool
    {
        $buildPath = $this->wasmSrcPath . 'build/';
        
        if (!is_dir($buildPath)) {
            return true;
        }

        return $this->recursiveDelete($buildPath);
    }

    /**
     * 递归删除目录（改进版，处理权限问题）
     */
    private function recursiveDelete(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        // Windows 上先尝试修改文件属性（移除只读属性）
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('attrib -r "%s" /s /d 2>&1', escapeshellarg($dir));
            @exec($cmd);
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                // 尝试多次删除，处理文件被占用的情况
                $retries = 3;
                $deleted = false;
                while ($retries > 0 && !$deleted) {
                    if (@unlink($path)) {
                        $deleted = true;
                    } else {
                        $retries--;
                        if ($retries > 0) {
                            usleep(500000); // 等待 0.5 秒
                        }
                    }
                }
                if (!$deleted) {
                    // 如果还是删除失败，尝试修改权限后再删除
                    @chmod($path, 0777);
                    @unlink($path);
                }
            }
        }

        // 尝试多次删除目录
        $retries = 3;
        $deleted = false;
        while ($retries > 0 && !$deleted) {
            if (@rmdir($dir)) {
                $deleted = true;
            } else {
                $retries--;
                if ($retries > 0) {
                    usleep(500000); // 等待 0.5 秒
                    @chmod($dir, 0777);
                }
            }
        }

        return $deleted;
    }

    /**
     * 递归复制目录
     */
    private function recursiveCopy(string $source, string $destination): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            if (!@mkdir($destination, 0755, true)) {
                return false;
            }
        }

        $files = array_diff(scandir($source), ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                if (!$this->recursiveCopy($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 强制重新安装 WASI SDK
     */
    public function reinstallWasiSdk(): array
    {
        $wasiSdkPath = $this->depsPath . 'wasi-sdk/';
        
        $this->output(__('正在清理旧的 WASI SDK 安装...'));
        
        // 强制删除旧安装
        if (is_dir($wasiSdkPath)) {
            if (!$this->recursiveDelete($wasiSdkPath)) {
                // 如果删除失败，尝试重命名
                $backupPath = $wasiSdkPath . '.backup.' . time();
                if (!@rename($wasiSdkPath, $backupPath)) {
                    return [
                        'success' => false,
                        'error' => __('无法删除旧的 WASI SDK 安装。请手动删除目录：%{1}', [$wasiSdkPath]),
                    ];
                }
                $this->output(__('已将旧安装移动到备份目录：%{1}', [$backupPath]));
            }
        }

        // 清理下载文件
        $this->cleanOldDownloads();

        // 重新安装（强制模式）
        return $this->installWasiSdk(true);
    }
}
