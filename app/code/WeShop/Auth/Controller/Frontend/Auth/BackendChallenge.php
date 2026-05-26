<?php

declare(strict_types=1);

namespace WeShop\Auth\Controller\Frontend\Auth;

use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Auth\Service\BackendWebAuthService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class BackendChallenge extends FrontendController
{
    private const ROUTE = 'weshop/frontend/auth/backend-challenge';

    public function __construct(
        private readonly BackendWebAuthService $backendWebAuthService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $challengeToken = trim((string) ($this->request->getParam('challenge_token') ?? ''));
        if ($challengeToken === '') {
            $this->getMessageManager()->addError(__('后台登录挑战 token 缺失。'));
            $this->redirect($this->url->getBackendUrl('admin/login'));
            return '';
        }

        $challenge = $this->backendWebAuthService->getChallenge($challengeToken);
        if (!$challenge) {
            $this->getMessageManager()->addError(__('后台登录挑战无效或已过期。'));
            $this->redirect($this->url->getBackendUrl('admin/login'));
            return '';
        }

        $this->assign('challenge_token', $challengeToken);
        $this->assign('expires_at', (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT));
        $this->assign('post_url', $this->url->getFrontendUrl(self::ROUTE));

        return $this->fetch('WeShop_Auth::templates/Frontend/Auth/backend-challenge.phtml');
    }

    public function postIndex(): void
    {
        $challengeToken = trim((string) ($this->request->getPost('challenge_token') ?? ''));
        $code = trim((string) ($this->request->getPost('code') ?? ''));

        if ($challengeToken === '' || $code === '') {
            $this->getMessageManager()->addError(__('请输入验证码。'));
            $this->redirect($this->url->getFrontendUrl(self::ROUTE, [
                'challenge_token' => $challengeToken,
            ]));
            return;
        }

        try {
            $result = $this->backendWebAuthService->completeChallenge($challengeToken, $code);
            $this->getMessageManager()->addSuccess(__('双因素验证已通过。'));
            $this->redirect((string) ($result['redirect_url'] ?? $this->url->getBackendUrl('admin')));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
            $this->redirect($this->url->getFrontendUrl(self::ROUTE, [
                'challenge_token' => $challengeToken,
            ]));
        }
    }
}
