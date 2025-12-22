<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\AutoLeadAgent\Service\WasmService;
use Weline\Framework\Manager\ObjectManager;

/**
 * WASM API控制器
 * 
 * 提供WASM文件哈希和下载接口
 */
class Wasm extends FrontendRestController
{
    /**
     * GET /api/v1/auto-lead-agent/wasm/hash
     * 获取WASM哈希
     */
    public function hash(): string
    {
        try {
            /** @var WasmService $wasmService */
            $wasmService = ObjectManager::getInstance(WasmService::class);
            $hash = $wasmService->getLatestHash();

            return $this->success(__('获取WASM哈希成功'), [
                'hash' => $hash,
            ]);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api/v1/auto-lead-agent/wasm/download
     * 下载WASM文件
     */
    public function download(): string
    {
        try {
            $wasmPath = BP . '/app/code/Weline/AutoLeadAgent/view/statics/wasm/agent-core.wasm';

            if (!file_exists($wasmPath)) {
                return $this->error(__('WASM文件不存在'), [], 404);
            }

            // 设置响应头
            $this->request->getResponse()
                ->setHeader('Content-Type', 'application/wasm')
                ->setHeader('Content-Disposition', 'attachment; filename="agent-core.wasm"')
                ->setHeader('Content-Length', (string)filesize($wasmPath));

            // 读取并输出文件
            readfile($wasmPath);
            exit;

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }
}

