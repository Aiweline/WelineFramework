<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Frontend;

use Weline\CustomerService\Service\BindCaptchaGuard;
use Weline\CustomerService\Service\EmailBindingService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Cache\SharedResponseCachePolicy;

/**
 * 邮件绑定控制器
 */
class Bind extends FrontendController
{
    public function __construct(
        private readonly EmailBindingService $emailBindingService,
        private readonly BindCaptchaGuard $bindCaptchaGuard,
    ) {
    }

    /**
     * 刷新绑定邮箱人机验证挑战（AJAX）
     * GET /customerservice/frontend/bind/captcha-challenge
     */
    public function getCaptchaChallenge(): string
    {
        // One-shot challenge HTML must never enter shared/page fragment caches.
        SharedResponseCachePolicy::forbid('customerservice_bind_captcha_challenge');

        if (!$this->bindCaptchaGuard->isEnabled()) {
            return $this->fetchJson([
                'success' => true,
                'enabled' => false,
                'html' => '',
            ]);
        }

        $prefer = \strtolower(\trim((string)$this->request->getGet('prefer', '')));
        if ($prefer !== 'local_image') {
            $prefer = '';
        }

        $html = $this->bindCaptchaGuard->renderChallenge(
            $prefer !== '' ? ['prefer' => $prefer] : []
        );

        return $this->fetchJson([
            'success' => $html !== '',
            'enabled' => true,
            'html' => $html,
            'prefer' => $prefer !== '' ? $prefer : null,
            'message' => $html === '' ? __('人机验证加载失败，请稍后重试') : '',
        ]);
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

            if (!$this->emailBindingService->isValidEmail($email)) {
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

            $submission = $this->request->getParams();
            if (!\is_array($submission)) {
                $submission = [];
            }
            if (!$this->bindCaptchaGuard->verify($submission, $this->request)) {
                $degrade = $this->bindCaptchaGuard->allowsLocalDegrade() ? 'local_image' : '';
                $provider = \strtolower(\trim((string)($submission['captcha_provider'] ?? '')));
                $shouldDegrade = $this->bindCaptchaGuard->shouldOfferLocalDegrade($provider);
                $detail = \trim($this->bindCaptchaGuard->lastFailureDetail());
                $message = $shouldDegrade
                    ? __('人机验证服务暂不可用，已切换为本地图码，请填写后重试')
                    : (
                        \str_contains(\strtolower($detail), 'browser_error')
                            ? __('人机验证未完成，请再试一次')
                            : __('人机验证失败或已过期，请重试')
                    );
                $payload = [
                    'success' => false,
                    'message' => $message,
                    'captcha_error' => true,
                    'captcha_degrade' => $shouldDegrade ? $degrade : null,
                ];
                if (\defined('DEV') && \DEV && $detail !== '') {
                    $payload['captcha_detail'] = $detail;
                }

                return $this->fetchJson($payload);
            }

            $result = $this->emailBindingService->sendVerificationEmail($email, $sessionToken);

            if ($result) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => __('验证邮件已发送，请查收')
                ]);
            }

            $detail = trim($this->emailBindingService->getLastErrorMessage());
            $verificationUrl = trim($this->emailBindingService->getLastVerificationUrl());
            $payload = [
                'success' => false,
                'message' => $detail !== ''
                    ? $detail
                    : __('发送验证邮件失败，请稍后重试')
            ];
            if ($verificationUrl !== '') {
                $payload['verification_url'] = $verificationUrl;
            }

            return $this->fetchJson($payload);
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
                $this->assign('home_url', (string)$this->getUrl('/'));
                return $this->fetch();
            }

            $data = $this->emailBindingService->verifyToken($token);

            if (!$data) {
                $this->assign('success', false);
                $this->assign('message', __('验证令牌无效或已过期'));
                $this->assign('home_url', (string)$this->getUrl('/'));
                return $this->fetch();
            }

            // 绑定客户到会话
            $customerId = $this->session->isLoggedIn() ? $this->session->getUserId() : null;
            $result = $this->emailBindingService->bindCustomerToSession(
                $data['email'],
                $data['session_token'],
                $customerId
            );

            $this->assign('home_url', (string)$this->getUrl('/'));
            if ($result) {
                $this->assign('success', true);
                $this->assign('message', __('邮箱绑定成功'));
                $this->assign('email', $data['email']);
                $this->assign('session_token', $data['session_token']);
            } else {
                $this->assign('success', false);
                $this->assign('message', __('邮箱绑定失败，请稍后重试'));
            }

            return $this->fetch();
        } catch (\Exception $e) {
            $this->assign('success', false);
            $this->assign('message', __('验证失败：%{1}', $e->getMessage()));
            $this->assign('home_url', (string)$this->getUrl('/'));
            return $this->fetch();
        }
    }
}

