<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Frontend;

use Weline\CustomerService\Service\EmailBindingService;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 邮件绑定控制器
 */
class Bind extends FrontendController
{
    private EmailBindingService $emailBindingService;

    public function __construct(
        EmailBindingService $emailBindingService
    ) {
        $this->emailBindingService = $emailBindingService;
    }

    /**
     * 发送绑定验证邮件（AJAX）
     * POST /customerservice/frontend/bind/send-verification
     */
    public function postSendVerification(): string
    {
        try {
            $email = trim($this->request->getPost('email', ''));
            $sessionToken = trim($this->request->getPost('session_token', ''));

            if (empty($email)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('邮箱地址不能为空')
                ]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('邮箱格式不正确')
                ]);
            }

            if (empty($sessionToken)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('会话令牌不能为空')
                ]);
            }

            $result = $this->emailBindingService->sendVerificationEmail($email, $sessionToken);

            if ($result) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('验证邮件已发送，请查收')
                ]);
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('发送验证邮件失败，请稍后重试')
                ]);
            }
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('发送验证邮件失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 验证绑定令牌
     * GET /customerservice/frontend/bind/verify
     */
    public function verify(): string
    {
        try {
            $token = trim($this->request->getParam('token', ''));

            if (empty($token)) {
                $this->assign('success', false);
                $this->assign('message', __('验证令牌不能为空'));
                return $this->fetch();
            }

            $data = $this->emailBindingService->verifyToken($token);

            if (!$data) {
                $this->assign('success', false);
                $this->assign('message', __('验证令牌无效或已过期'));
                return $this->fetch();
            }

            // 绑定客户到会话
            $customerId = $this->session->isLoggedIn() ? $this->session->getUserId() : null;
            $result = $this->emailBindingService->bindCustomerToSession(
                $data['email'],
                $data['session_token'],
                $customerId
            );

            if ($result) {
                $this->assign('success', true);
                $this->assign('message', __('邮箱绑定成功'));
                $this->assign('email', $data['email']);
            } else {
                $this->assign('success', false);
                $this->assign('message', __('邮箱绑定失败，请稍后重试'));
            }

            return $this->fetch();
        } catch (\Exception $e) {
            $this->assign('success', false);
            $this->assign('message', __('验证失败：%{1}', $e->getMessage()));
            return $this->fetch();
        }
    }
}

