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
use Weline\AutoLeadAgent\Service\TokenService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Core API控制器
 * 
 * 提供核心代码的动态加载接口
 */
class Core extends FrontendRestController
{
    /**
     * GET /api/v1/auto-lead-agent/core
     * 获取核心代码（动态加载）
     */
    public function get(): string
    {
        try {
            // 验证Token
            $token = $this->request->getHeader('X-Agent-Token') ?? '';
            $domain = $this->request->getServer('HTTP_HOST') ?? '';

            if (empty($token) || empty($domain)) {
                return $this->error(__('Token或域名参数缺失'), [], 401);
            }

            /** @var TokenService $tokenService */
            $tokenService = ObjectManager::getInstance(TokenService::class);
            if (!$tokenService->validateToken($token, $domain)) {
                return $this->error(__('Token验证失败'), [], 401);
            }

            // 读取核心代码文件
            $corePath = BP . '/app/code/Weline/AutoLeadAgent/view/statics/js/agent-core.js';
            
            if (!file_exists($corePath)) {
                return $this->error(__('核心代码文件不存在'), [], 404);
            }

            // 设置响应头
            $this->request->getResponse()
                ->setHeader('Content-Type', 'application/javascript')
                ->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');

            // 读取并返回代码（可以在这里进行混淆处理）
            $coreCode = file_get_contents($corePath);
            
            // 可以在这里添加代码混淆或加密逻辑
            // $coreCode = $this->obfuscateCode($coreCode);

            return $coreCode;

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }
}

