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
     */
    private function installWasiSdk(): array
    {
        $wasiSdkPath = $this->depsPath . 'wasi-sdk/';
        
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

        // 重命名解压后的目录
        $extractedDir = $this->depsPath . 'wasi-sdk-' . $version . '.0';
        if (is_dir($extractedDir)) {
            if (is_dir($wasiSdkPath)) {
                $this->recursiveDelete($wasiSdkPath);
            }
            rename($extractedDir, $wasiSdkPath);
        }

        // 清理下载文件
        @unlink($tarFile);

        // 验证安装
        $clangPath = $this->getWasiClangPath($wasiSdkPath);
        if (!$clangPath || !file_exists($clangPath)) {
            return [
                'success' => false,
                'error' => __('WASI SDK 安装后验证失败'),
            ];
        }

        return ['success' => true];
    }

    /**
     * 清理旧的下载文件
     */
    private function cleanOldDownloads(): void
    {
        $files = glob($this->depsPath . 'wasi-sdk*.tar.gz');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
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
     * 下载文件（带重试机制）
     */
    private function downloadFile(string $url, string $targetPath): array
    {
        // 先删除已存在的文件
        if (file_exists($targetPath)) {
            @unlink($targetPath);
            if (PHP_OS_FAMILY === 'Windows') {
                sleep(1);
            }
        }

        $lastError = '';
        
        for ($retry = 1; $retry <= self::DOWNLOAD_RETRY_COUNT; $retry++) {
            if ($retry > 1) {
                $this->output(__('第 %{1} 次重试...', [$retry]));
                sleep(2);
            }

            $result = $this->doDownload($url, $targetPath);
            
            if ($result['success'] && file_exists($targetPath) && filesize($targetPath) > 0) {
                return ['success' => true];
            }
            
            $lastError = $result['error'] ?? __('下载失败');
            @unlink($targetPath);
        }

        return [
            'success' => false,
            'error' => __('下载失败（已重试%{1}次）：%{2}', [self::DOWNLOAD_RETRY_COUNT, $lastError]),
        ];
    }

    /**
     * 执行下载
     */
    private function doDownload(string $url, string $targetPath): array
    {
        $output = [];
        $returnCode = 0;
        $timeout = self::DOWNLOAD_TIMEOUT;

        if (PHP_OS_FAMILY === 'Windows') {
            // 方案1：使用 curl（带断点续传和进度）
            $this->output(__('尝试使用 curl 下载...'));
            $cmd = sprintf(
                'curl -L -C - --retry 2 --connect-timeout 30 --max-time %d -o %s %s 2>&1',
                $timeout,
                escapeshellarg($targetPath),
                escapeshellarg($url)
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($targetPath) && filesize($targetPath) > 1024) {
                return ['success' => true];
            }

            // 方案2：使用 PowerShell（更可靠但无进度显示）
            $this->output(__('尝试使用 PowerShell 下载...'));
            @unlink($targetPath);
            $output = [];
            $psCmd = sprintf(
                'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri \'%s\' -OutFile \'%s\' -UseBasicParsing -TimeoutSec %d" 2>&1',
                $url,
                $targetPath,
                $timeout
            );
            exec($psCmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($targetPath) && filesize($targetPath) > 1024) {
                return ['success' => true];
            }

            // 方案3：使用 PHP 内置函数
            $this->output(__('尝试使用 PHP 下载...'));
            @unlink($targetPath);
            $phpResult = $this->downloadWithPhp($url, $targetPath);
            if ($phpResult['success']) {
                return $phpResult;
            }

            return [
                'success' => false,
                'error' => implode("\n", $output) ?: $phpResult['error'] ?? __('所有下载方式均失败'),
            ];

        } else {
            // Unix 系统：使用 curl
            $cmd = sprintf(
                'curl -L -C - --retry 2 --connect-timeout 30 --max-time %d -o %s %s 2>&1',
                $timeout,
                escapeshellarg($targetPath),
                escapeshellarg($url)
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($targetPath) && filesize($targetPath) > 1024) {
                return ['success' => true];
            }

            // 备选：使用 wget
            @unlink($targetPath);
            $output = [];
            $cmd = sprintf(
                'wget --timeout=%d -O %s %s 2>&1',
                $timeout,
                escapeshellarg($targetPath),
                escapeshellarg($url)
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($targetPath) && filesize($targetPath) > 1024) {
                return ['success' => true];
            }

            return [
                'success' => false,
                'error' => implode("\n", $output) ?: __('curl 和 wget 均失败'),
            ];
        }
    }

    /**
     * 使用 PHP 内置函数下载
     */
    private function downloadWithPhp(string $url, string $targetPath): array
    {
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

        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            return [
                'success' => false,
                'error' => __('PHP file_get_contents 下载失败'),
            ];
        }

        $written = @file_put_contents($targetPath, $content);
        
        if ($written === false || $written === 0) {
            return [
                'success' => false,
                'error' => __('写入文件失败'),
            ];
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
            return [
                'success' => false,
                'error' => __('WASI SDK 编译失败：%{1}', [implode("\n", $output)]),
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
                ->where(WasmHash::fields_HASH_VALUE, $hash)
                ->find()
                ->fetch();

            if ($wasmHashModel->getId()) {
                // 更新现有记录
                $wasmHashModel->setData(WasmHash::fields_WASM_PATH, $filePath)
                    ->save();
            } else {
                // 创建新记录
                $latestVersion = $this->getLatestVersion();
                $newVersion = $latestVersion + 1;

                $wasmHashModel->clear()
                    ->setData(WasmHash::fields_WASM_PATH, $filePath)
                    ->setData(WasmHash::fields_HASH_VALUE, $hash)
                    ->setData(WasmHash::fields_VERSION, (string)$newVersion)
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
                ->order(WasmHash::fields_VERSION, 'DESC')
                ->limit(1)
                ->fetch();

            $items = $wasmHashModel->getItems();
            if (!empty($items)) {
                $latest = reset($items);
                return (int)$latest->getData(WasmHash::fields_VERSION);
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
     * 递归删除目录
     */
    private function recursiveDelete(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
