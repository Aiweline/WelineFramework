<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class Challenge extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerLoginChallengeHandlerInterface $challengeHandler
    ) {
    }

    public function getIndex(): string
    {
        $challengeToken = trim((string) ($this->request->getParam('challenge_token') ?? ''));
        if ($challengeToken === '') {
            MessageManager::error((string)__('缺少登录验证令牌。'));
            return $this->redirect('/customer/account/login');
        }

        $expiresAt = $this->challengeHandler->getChallengeExpiresAt($challengeToken);
        if ($expiresAt === null) {
            MessageManager::error((string)__('登录验证已失效或已过期。'));
            return $this->redirect('/customer/account/login');
        }

        $this->assign('challenge_token', $challengeToken);
        $this->assign('expires_at', $expiresAt);
        $this->assign('title', __('两步验证'));

        return $this->fetch('Weline_Customer::templates/frontend/account/challenge.phtml');
    }

    public function postIndex(): string
    {
        $challengeToken = trim((string) ($this->request->getPost('challenge_token') ?? ''));
        $code = trim((string) ($this->request->getPost('code') ?? ''));

        if ($challengeToken === '' || $code === '') {
            return $this->respondFailure(
                (string)__('请输入验证码。'),
                $challengeToken
            );
        }

        try {
            $result = $this->challengeHandler->completeChallenge($challengeToken, $code);
            return $this->respondSuccess(
                (string)__('两步验证成功。'),
                (string)($result['redirect_url'] ?? 'customer/account')
            );
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->respondFailure($throwable->getMessage(), $challengeToken);
        }
    }

    private function respondSuccess(string $message, string $redirectUrl): string
    {
        $redirect = $this->normalizeRedirectPath($redirectUrl);
        if ($this->expectsJsonResponse()) {
            return $this->fetchJson([
                'success' => true,
                'status' => 'authenticated',
                'message' => $message,
                'redirect' => $redirect,
            ]);
        }

        MessageManager::success($message);
        return $this->redirect($redirect);
    }

    private function respondFailure(string $message, string $challengeToken): string
    {
        if ($this->expectsJsonResponse()) {
            return $this->fetchJson([
                'success' => false,
                'message' => $message,
            ]);
        }

        MessageManager::error($message);
        return $this->redirect('/customer/account/challenge?challenge_token=' . rawurlencode($challengeToken));
    }

    private function expectsJsonResponse(): bool
    {
        if ($this->request->isAjax()) {
            return true;
        }

        $acceptHeader = strtolower((string)($this->request->getHeader('Accept') ?? ''));
        return str_contains($acceptHeader, 'application/json');
    }

    private function normalizeRedirectPath(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return '/customer/account';
        }

        if ((bool)preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl)) {
            $path = trim((string)(parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
            $query = trim((string)(parse_url($redirectUrl, PHP_URL_QUERY) ?? ''));
            $redirectUrl = $path . ($query !== '' ? '?' . $query : '');
        }

        $normalized = ltrim($redirectUrl, '/');
        if ($normalized === '' || $normalized === 'customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }
}
