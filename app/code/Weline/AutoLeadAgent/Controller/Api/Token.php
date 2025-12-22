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
 * Token API控制器
 * 
 * 提供Token生成和验证接口
 */
class Token extends FrontendRestController
{
    /**
     * POST /api/v1/auto-lead-agent/token
     * 生成Token
     */
    public function post(): string
    {
        try {
            $params = $this->request->getBodyParams();
            $domain = $params['domain'] ?? $this->request->getServer('HTTP_HOST') ?? '';
            $ttl = (int)($params['ttl'] ?? 3600);

            if (empty($domain)) {
                return $this->error(__('域名参数不能为空'), [], 400);
            }

            /** @var TokenService $tokenService */
            $tokenService = ObjectManager::getInstance(TokenService::class);
            $token = $tokenService->generateToken($domain, $ttl);

            return $this->success(__('Token生成成功'), [
                'token' => $token,
                'domain' => $domain,
                'ttl' => $ttl,
            ]);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * GET /api/v1/auto-lead-agent/token/validate
     * 验证Token
     */
    public function validate(): string
    {
        try {
            $token = $this->request->getParam('token') ?? $this->request->getHeader('X-Agent-Token') ?? '';
            $domain = $this->request->getParam('domain') ?? $this->request->getServer('HTTP_HOST') ?? '';

            if (empty($token)) {
                return $this->error(__('Token参数不能为空'), [], 400);
            }

            if (empty($domain)) {
                return $this->error(__('域名参数不能为空'), [], 400);
            }

            /** @var TokenService $tokenService */
            $tokenService = ObjectManager::getInstance(TokenService::class);
            $isValid = $tokenService->validateToken($token, $domain);

            if (!$isValid) {
                return $this->error(__('Token无效或已过期'), [], 401);
            }

            $tokenInfo = $tokenService->getTokenInfo($token);

            return $this->success(__('Token验证成功'), [
                'valid' => true,
                'token_info' => $tokenInfo,
            ]);

        } catch (\Exception $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }
}

