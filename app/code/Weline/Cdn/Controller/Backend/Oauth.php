<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Service\CloudflareOAuthService;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

#[AclAttribute(
    'Weline_Cdn::cdn_cloudflare_oauth',
    'Cloudflare OAuth',
    'cloud',
    'Cloudflare OAuth 一键授权',
    'Weline_Cdn::cdn_account_manager'
)]
final class Oauth extends BackendController
{
    #[AclAttribute('Weline_Cdn::cdn_cloudflare_oauth_connect', '连接 Cloudflare', 'link', '发起 Cloudflare OAuth 授权')]
    public function postConnect(): string
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            return $this->redirectRoute('cdn/backend/account');
        }

        $returnRoute = (string)$this->request->getPost('return_route', 'cdn/backend/account');
        if (!in_array($returnRoute, ['cdn/backend/account', 'weline_mail/backend'], true)) {
            $returnRoute = 'cdn/backend/account';
        }

        try {
            $authorizationUrl = $this->service()->authorizationUrl(
                $this->callbackUrl(),
                $returnRoute,
            );
            $this->request->getResponse()->redirect($authorizationUrl);
            return '';
        } catch (\Throwable $e) {
            Message::error(__('Cloudflare OAuth 授权失败：%{1}', $e->getMessage()));
            return $this->redirectRoute($returnRoute);
        }
    }

    #[AclAttribute('Weline_Cdn::cdn_cloudflare_oauth_callback', 'Cloudflare OAuth 回调', 'link', '校验 OAuth state 并保存令牌')]
    public function callback(): string
    {
        $returnRoute = 'cdn/backend/account';
        $state = trim((string)$this->request->getGet('state', ''));

        try {
            $error = trim((string)$this->request->getGet('error', ''));
            if ($error !== '') {
                $context = $this->service()->consumeFailureState($state, $this->callbackUrl());
                $returnRoute = $context['return_route'];
                Message::error(__('Cloudflare OAuth 授权已取消。'));
                return $this->redirectRoute($returnRoute);
            }

            $result = $this->service()->completeAuthorization(
                trim((string)$this->request->getGet('code', '')),
                $state,
                $this->callbackUrl(),
            );
            $returnRoute = $result['return_route'];
            Message::success(__('Cloudflare OAuth 授权成功。'));
        } catch (\Throwable $e) {
            Message::error(__('Cloudflare OAuth 授权失败：%{1}', $e->getMessage()));
        }

        return $this->redirectRoute($returnRoute);
    }

    private function service(): CloudflareOAuthService
    {
        return ObjectManager::getInstance(CloudflareOAuthService::class);
    }

    private function callbackUrl(): string
    {
        return rtrim(
            $this->request->getUrlBuilder()->getBackendUrl('cdn/backend/oauth/callback'),
            '?&',
        );
    }

    private function redirectRoute(string $route): string
    {
        $this->request->getResponse()->redirect(
            $this->request->getUrlBuilder()->getBackendUrl($route)
        );

        return '';
    }
}
