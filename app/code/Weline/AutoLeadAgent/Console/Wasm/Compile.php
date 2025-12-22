<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Console\Wasm;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Service\WasmCompileService;

/**
 * WASM编译命令
 * 
 * 用于编译自动寻客模块的WASM核心算法
 * 使用 WASI SDK 便携编译工具（自动下载安装）
 */
class Compile implements CommandInterface
{
    public function __construct(
        private Printing $printing
    ) {
    }

    /**
     * 执行编译命令
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printing->note(__('========================================'));
        $this->printing->note(__('   AutoLeadAgent WASM 编译工具'));
        $this->printing->note(__('========================================'));

        try {
            /** @var WasmCompileService $compileService */
            $compileService = ObjectManager::getInstance(WasmCompileService::class);
            $compileService->setPrinting($this->printing);

            // 解析命令行参数
            $forceCompile = isset($args['force']) || isset($args['f']);
            $noInstall = isset($args['no-install']) || isset($args['n']);
            $installOnly = isset($args['install-deps']) || isset($args['i']);
            $cleanBuild = isset($args['clean']) || isset($args['c']);
            $showEnv = isset($args['env']) || isset($args['e']);
            $debug = isset($args['debug']) || isset($args['d']);

            // 只显示环境信息
            if ($showEnv) {
                $this->showEnvironmentInfo($compileService);
                return;
            }

            // 清理构建目录
            if ($cleanBuild) {
                $this->printing->note(__('清理构建目录...'));
                if ($compileService->cleanBuild()) {
                    $this->printing->success(__('✓ 构建目录已清理'));
                } else {
                    $this->printing->warning(__('清理构建目录时出现问题'));
                }
                if (!$forceCompile && !$installOnly) {
                    return;
                }
            }

            // 检查编译环境
            $this->printing->note(__(''));
            $this->printing->note(__('【步骤 1/4】检查编译环境...'));
            $envCheck = $compileService->checkEnvironment();
            
            $this->displayEnvironmentStatus($envCheck);

            // 如果源码不存在
            if (!$envCheck['source_exists']) {
                $this->printing->error(__('WASM 源码不存在！'));
                $this->printing->note(__('请确保以下文件存在：'));
                $this->printing->note(__('  - %{1}agent_core.cpp', [$compileService->getWasmSrcPath()]));
                $this->printing->note(__('  - %{1}agent_core.h', [$compileService->getWasmSrcPath()]));
                $this->printing->note(__('  - %{1}binding.cpp', [$compileService->getWasmSrcPath()]));
                return;
            }

            // 只安装依赖
            if ($installOnly) {
                $this->printing->note(__(''));
                $this->printing->note(__('【安装依赖】'));
                $installResult = $compileService->installDependencies();
                $this->displayInstallResult($installResult);
                return;
            }

            // 检查是否需要安装 WASI SDK
            if (!$envCheck['wasi_sdk'] && $noInstall) {
                $this->printing->warning(__('WASI SDK 未安装！'));
                $this->printing->note(__('已指定 --no-install 参数，跳过自动安装'));
                $this->printing->note(__(''));
                $this->printing->note(__('请手动下载 WASI SDK：'));
                $this->printing->note(__('  https://github.com/WebAssembly/wasi-sdk/releases'));
                $this->printing->note(__(''));
                $this->printing->note(__('下载后解压到：'));
                $this->printing->note(__('  %{1}wasi-sdk/', [$compileService->getDepsPath()]));
                return;
            }

            // 安装依赖
            if (!$envCheck['wasi_sdk']) {
                $this->printing->note(__(''));
                $this->printing->note(__('【步骤 2/4】自动安装 WASI SDK...'));
                $installResult = $compileService->installDependencies();
                $this->displayInstallResult($installResult);

                if (!$installResult['success']) {
                    $this->printing->error(__('WASI SDK 安装失败，无法继续编译'));
                    $this->printing->note(__(''));
                    $this->printing->note(__('请手动下载 WASI SDK：'));
                    $this->printing->note(__('  https://github.com/WebAssembly/wasi-sdk/releases'));
                    $this->printing->note(__(''));
                    $this->printing->note(__('下载后解压到：'));
                    $this->printing->note(__('  %{1}wasi-sdk/', [$compileService->getDepsPath()]));
                    return;
                }

                // 重新检查环境
                $envCheck = $compileService->checkEnvironment();
                if (!$envCheck['wasi_sdk']) {
                    $this->printing->error(__('WASI SDK 安装后验证失败'));
                    return;
                }
            } else {
                $this->printing->note(__(''));
                $this->printing->note(__('【步骤 2/4】WASI SDK 已就绪'));
            }

            // 检查是否需要编译
            $this->printing->note(__(''));
            $this->printing->note(__('【步骤 3/4】检查是否需要编译...'));
            
            if (!$forceCompile && !$compileService->needsCompile()) {
                $this->printing->success(__('✓ WASM 文件已是最新，无需重新编译'));
                $this->printing->note(__('使用 --force 或 -f 参数强制重新编译'));
                return;
            }

            if ($forceCompile) {
                $this->printing->note(__('强制重新编译模式'));
            } else {
                $this->printing->note(__('检测到源文件更新，需要重新编译'));
            }

            // 执行编译
            $this->printing->note(__(''));
            $this->printing->note(__('【步骤 4/4】执行编译...'));
            $this->printing->note(__(''));
            
            $result = $compileService->compile();

            if ($result['success']) {
                $this->printing->note(__(''));
                $this->printing->success(__('========================================'));
                $this->printing->success(__('   ✓ WASM 编译成功！'));
                $this->printing->success(__('========================================'));
                $this->printing->note(__(''));
                $this->printing->note(__('输出文件: %{1}', [$result['output_file']]));
                $this->printing->note(__('文件大小: %{1}', [$result['file_size']]));
                $this->printing->note(__('SHA256:   %{1}', [$result['hash']]));

                // 注册哈希
                $this->printing->note(__(''));
                $this->printing->note(__('正在注册 WASM 哈希到数据库...'));
                try {
                    $compileService->registerHash($result['hash'], $result['output_file']);
                    $this->printing->success(__('✓ WASM 哈希已注册'));
                } catch (\Throwable $e) {
                    $this->printing->warning(__('哈希注册失败：%{1}', [$e->getMessage()]));
                    $this->printing->note(__('这不影响 WASM 模块的使用'));
                }

            } else {
                $this->printing->note(__(''));
                $this->printing->error(__('========================================'));
                $this->printing->error(__('   ✗ WASM 编译失败'));
                $this->printing->error(__('========================================'));
                $this->printing->error(__('错误信息: %{1}', [$result['error']]));
            }

        } catch (\Throwable $e) {
            $this->printing->error(__('编译过程中发生错误：%{1}', [$e->getMessage()]));
            if ($debug) {
                $this->printing->error($e->getTraceAsString());
            }
        }
    }

    /**
     * 显示环境信息
     */
    private function showEnvironmentInfo(WasmCompileService $compileService): void
    {
        $this->printing->note(__('【编译环境信息】'));
        $this->printing->note(__(''));
        
        $envCheck = $compileService->checkEnvironment();
        $this->displayEnvironmentStatus($envCheck);
        
        $this->printing->note(__(''));
        $this->printing->note(__('【路径信息】'));
        $this->printing->note(__('WASM 源码目录: %{1}', [$compileService->getWasmSrcPath()]));
        $this->printing->note(__('WASM 输出文件: %{1}', [$compileService->getWasmFilePath()]));
        $this->printing->note(__('依赖安装目录: %{1}', [$compileService->getDepsPath()]));
        
        // 检查 WASM 文件状态
        $wasmFile = $compileService->getWasmFilePath();
        $this->printing->note(__(''));
        $this->printing->note(__('【WASM 文件状态】'));
        if (file_exists($wasmFile)) {
            $this->printing->success(__('✓ WASM 文件已存在'));
            $this->printing->note(__('  文件大小: %{1}', [$this->formatFileSize(filesize($wasmFile))]));
            $this->printing->note(__('  修改时间: %{1}', [date('Y-m-d H:i:s', filemtime($wasmFile))]));
            $this->printing->note(__('  SHA256:   %{1}', [hash_file('sha256', $wasmFile)]));
        } else {
            $this->printing->warning(__('✗ WASM 文件不存在，需要编译'));
        }
    }

    /**
     * 显示环境状态
     */
    private function displayEnvironmentStatus(array $envCheck): void
    {
        $this->printing->note(__(''));
        
        // WASI SDK
        if ($envCheck['wasi_sdk']) {
            $this->printing->success(__('✓ WASI SDK 已安装'));
            if ($envCheck['wasi_sdk_path']) {
                $this->printing->note(__('  路径: %{1}', [$envCheck['wasi_sdk_path']]));
            }
        } else {
            $this->printing->warning(__('✗ WASI SDK 未安装（将自动下载，约50MB）'));
        }
        
        // 源码
        if ($envCheck['source_exists']) {
            $this->printing->success(__('✓ WASM 源码文件存在'));
        } else {
            $this->printing->error(__('✗ WASM 源码文件不存在'));
        }
    }

    /**
     * 显示安装结果
     */
    private function displayInstallResult(array $result): void
    {
        if (!empty($result['installed'])) {
            $this->printing->success(__('已安装的组件：'));
            foreach ($result['installed'] as $component) {
                $this->printing->success(__('  ✓ %{1}', [$component]));
            }
        }
        
        if (!empty($result['errors'])) {
            $this->printing->warning(__('安装过程中的警告/错误：'));
            foreach ($result['errors'] as $error) {
                $this->printing->warning(__('  • %{1}', [$error]));
            }
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
     * 命令提示
     */
    public function tip(): string
    {
        return __('编译自动寻客模块的 WASM 核心算法（自动安装 WASI SDK）');
    }

    /**
     * 命令帮助
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'wasm:compile',
            $this->tip(),
            [
                '-f, --force' => __('强制重新编译，即使 WASM 文件已是最新'),
                '-n, --no-install' => __('跳过自动安装 WASI SDK'),
                '-i, --install-deps' => __('仅安装 WASI SDK，不执行编译'),
                '-c, --clean' => __('清理构建目录'),
                '-e, --env' => __('显示编译环境信息'),
                '-d, --debug' => __('显示调试信息'),
            ],
            [],
            [
                __('编译（自动安装）') => 'php bin/m wasm:compile',
                __('强制重新编译') => 'php bin/m wasm:compile --force',
                __('跳过自动安装') => 'php bin/m wasm:compile --no-install',
                __('仅安装 WASI SDK') => 'php bin/m wasm:compile --install-deps',
                __('清理后重新编译') => 'php bin/m wasm:compile --clean --force',
                __('查看环境信息') => 'php bin/m wasm:compile --env',
            ]
        );
    }

    /**
     * 命令别名
     */
    public function aliases(): array
    {
        return ['ala:wasm:compile', 'ala:wc'];
    }
}
