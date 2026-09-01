<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

class Challenge extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.challenge';

    private readonly CustomerAuthReturnUrlService $authReturnUrlService;

    public function __construct(
        private readonly Template $template,
        private readonly CustomerLoginChallengeHandlerInterface $challengeHandler,
        ?CustomerAuthReturnUrlService $authReturnUrlService = null
    ) {
        $this->authReturnUrlService = $authReturnUrlService
            ?? ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
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
        $this->assign('meta', [
            'showHeader' => true,
            'showFooter' => true,
        ]);

        // Stage UI is Theme-inline account-challenge widget (background configurable).
        return $this->fetch('Weline_Customer::templates/frontend/account/challenge-shell.phtml');
    }

    public function postIndex(): string
    {
        $post = [];
        if (\method_exists($this->request, 'getPostParams')) {
            $candidate = $this->request->getPostParams();
            if (\is_array($candidate)) {
                $post = $candidate;
            }
        }
        if ($post === []) {
            $candidate = $this->request->getPost();
            if (\is_array($candidate)) {
                $post = $candidate;
            }
        }

        $challengeToken = trim((string)($post['challenge_token'] ?? ''));
        if ($challengeToken === '') {
            $challengeToken = trim((string)($this->request->getParam('challenge_token') ?? ''));
        }

        $rawCode = (string)($post['code'] ?? '');
        if ($rawCode === '') {
            $rawCode = (string)($this->request->getPost('code') ?? '');
        }
        $code = preg_replace('/\D+/', '', $rawCode) ?? '';

        if ($challengeToken === '') {
            return $this->respondFailure(
                (string)__('登录验证令牌缺失，请返回登录后重试。'),
                ''
            );
        }

        if ($code === '') {
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
        $target = $this->authReturnUrlService->consume($this->session, $redirectUrl);
        $redirect = $this->authReturnUrlService->formatAuthSuccessRedirect($target);
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
}
