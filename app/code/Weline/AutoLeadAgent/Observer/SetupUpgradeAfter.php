<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Manager\ObjectManager;
use Weline\AutoLeadAgent\Service\WasmCompileService;

/**
 * 系统升级后观察者
 * 
 * 监听 Weline_Framework_Setup::upgrade_after 事件
 * 自动检查并编译 WASM 模块
 */
class SetupUpgradeAfter implements ObserverInterface
{
    public function __construct(
        private Printing $printing
    ) {
    }

    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $this->printing->note(__(''));
        $this->printing->note(__('=== AutoLeadAgent WASM 编译检查 ==='));

        try {
            /** @var WasmCompileService $compileService */
            $compileService = ObjectManager::getInstance(WasmCompileService::class);

            // 检查编译环境
            $envCheck = $compileService->checkEnvironment();

            // 如果源码不存在，跳过
            if (!$envCheck['source_exists']) {
                $this->printing->note(__('WASM 源码不存在，跳过编译'));
                return;
            }

            // 检查是否需要编译
            $needsCompile = $compileService->needsCompile();
            
            // 即使文件已是最新，也要检查数据库表中是否有哈希记录
            if (!$needsCompile) {
                $wasmFile = $compileService->getWasmFilePath();
                if (file_exists($wasmFile)) {
                    // 计算当前WASM文件的哈希
                    $currentHash = hash_file('sha256', $wasmFile);
                    
                    // 检查数据库表中是否有这个哈希的记录
                    /** @var \Weline\AutoLeadAgent\Model\WasmHash $wasmHashModel */
                    $wasmHashModel = ObjectManager::getInstance(\Weline\AutoLeadAgent\Model\WasmHash::class);
                    $wasmHashModel->clear()
                        ->where(\Weline\AutoLeadAgent\Model\WasmHash::fields_HASH_VALUE, $currentHash)
                        ->find()
                        ->fetch();
                    
                    // 如果数据库表中没有记录，需要重新编译并注册
                    if (!$wasmHashModel->getId()) {
                        $this->printing->note(__('WASM 文件存在但数据库表中没有哈希记录，需要重新编译并注册'));
                        $needsCompile = true;
                    } else {
                        $this->printing->note(__('WASM 文件已是最新，无需重新编译'));
                        
                        // 即使不需要编译，也检查静态资源目录是否有文件，如果没有则复制
                        $outputWasm = BP . '/app/code/Weline/AutoLeadAgent/wasm/output/agent-core.wasm';
                        $staticWasmDir = BP . '/app/code/Weline/AutoLeadAgent/view/statics/wasm/';
                        $staticWasmFile = $staticWasmDir . 'agent-core.wasm';
                        
                        if (file_exists($outputWasm) && !file_exists($staticWasmFile)) {
                            if (!is_dir($staticWasmDir)) {
                                mkdir($staticWasmDir, 0755, true);
                            }
                            if (copy($outputWasm, $staticWasmFile)) {
                                $this->printing->success(__('✓ WASM 文件已复制到静态资源目录'));
                            } else {
                                $this->printing->warning(__('⚠️ WASM 文件复制失败'));
                            }
                        } elseif (file_exists($outputWasm) && file_exists($staticWasmFile)) {
                            // 如果两个文件都存在，检查是否需要更新（比较修改时间）
                            $outputMtime = filemtime($outputWasm);
                            $staticMtime = file_exists($staticWasmFile) ? filemtime($staticWasmFile) : 0;
                            if ($outputMtime > $staticMtime) {
                                if (copy($outputWasm, $staticWasmFile)) {
                                    $this->printing->success(__('✓ WASM 文件已更新到静态资源目录'));
                                } else {
                                    $this->printing->warning(__('⚠️ WASM 文件更新失败'));
                                }
                            }
                        }
                        return;
                    }
                }
            }
            
            // 如果不需要编译（且数据库有记录），直接返回
            if (!$needsCompile) {
                return;
            }

            // 检查编译环境（WASI SDK）
            if (!isset($envCheck['wasi_sdk']) || !$envCheck['wasi_sdk']) {
                $this->printing->note(__('WASI SDK 未安装，开始自动安装依赖...'));
                // 立即刷新输出，确保用户能看到进度
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                
                // 自动安装 WASI SDK
                $installResult = $compileService->installDependencies();
                
                if ($installResult['success']) {
                    $this->printing->success(__('✓ WASI SDK 自动安装成功'));
                    
                    // 重新检查环境
                    $envCheck = $compileService->checkEnvironment();
                    
                    if (!isset($envCheck['wasi_sdk']) || !$envCheck['wasi_sdk']) {
                        $this->printing->warning(__('WASI SDK 安装后验证失败，无法编译 WASM'));
                        $this->printing->note(__('模块将使用纯 JavaScript 推理引擎作为备用方案'));
                        $this->printing->note(__('可手动运行: php bin/m auto-lead-agent:wasm:compile --install-deps'));
                        return;
                    }
                } else {
                    $errorMsg = !empty($installResult['errors']) 
                        ? implode('; ', $installResult['errors']) 
                        : __('安装失败，未知错误');
                    $this->printing->warning(__('WASI SDK 自动安装失败：%{1}', [$errorMsg]));
                    $this->printing->note(__('模块将使用纯 JavaScript 推理引擎作为备用方案'));
                    $this->printing->note(__('可手动运行: php bin/m auto-lead-agent:wasm:compile --install-deps'));
                    return;
                }
            }

            // 执行编译
            $this->printing->note(__('检测到 WASM 源码有更新，开始自动编译...'));
            // 立即刷新输出，确保用户能看到进度
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            $result = $compileService->compile();

            if ($result['success']) {
                $this->printing->success(__('✓ WASM 自动编译成功！'));
                $this->printing->note(__('  输出文件: %{1}', [$result['output_file']]));
                $this->printing->note(__('  文件大小: %{1}', [$result['file_size']]));
                $this->printing->note(__('  SHA256: %{1}', [substr($result['hash'], 0, 16) . '...']));

                // 复制WASM文件到静态资源目录
                $staticWasmDir = BP . '/app/code/Weline/AutoLeadAgent/view/statics/wasm/';
                $staticWasmFile = $staticWasmDir . 'agent-core.wasm';
                
                if (!is_dir($staticWasmDir)) {
                    mkdir($staticWasmDir, 0755, true);
                }
                
                if (copy($result['output_file'], $staticWasmFile)) {
                    $this->printing->success(__('✓ WASM 文件已复制到静态资源目录: %{1}', [$staticWasmFile]));
                } else {
                    $this->printing->warning(__('⚠️ WASM 文件复制失败，请手动复制: %{1} -> %{2}', [$result['output_file'], $staticWasmFile]));
                }

                // 注册哈希
                $compileService->registerHash($result['hash'], $result['output_file']);
                $this->printing->success(__('✓ WASM 哈希已自动注册'));

            } else {
                $this->printing->warning(__('WASM 自动编译失败：%{1}', [$result['error']]));
                $this->printing->note(__('模块将使用纯 JavaScript 推理引擎作为备用方案'));
                $this->printing->note(__('可手动运行: php bin/m auto-lead-agent:wasm:compile'));
            }

        } catch (\Throwable $e) {
            $this->printing->warning(__('WASM 编译检查时发生错误：%{1}', [$e->getMessage()]));
            $this->printing->note(__('模块将使用纯 JavaScript 推理引擎作为备用方案'));
        }

        $this->printing->note(__('================================='));
    }
}

