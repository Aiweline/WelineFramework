<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Api\OauthCapableProviderInterface;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN 一键授权调度器：按 adapter 解析 OauthCapable Provider，授权逻辑全部在 Provider 上。
 */
#[AclAttribute(
    'Weline_Cdn::cdn_oauth',
    'CDN OAuth',
    'cloud',
    'CDN Provider 一键授权',
    'Weline_Cdn::cdn_account_manager'
)]
final class Oauth extends BackendController
{
    #[AclAttribute('Weline_Cdn::cdn_oauth_connect', '连接 CDN Provider', 'link', '发起 Provider OAuth 授权')]
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

        $adapterCode = $this->resolveAdapterCode(
            (string)$this->request->getPost('adapter', ''),
        );

        try {
            $provider = $this->requireProvider($adapterCode);
            $callbackUrl = $provider->getOauthCallbackUrl();
            $authorizationUrl = $provider->startOauthAuthorization($callbackUrl, $returnRoute);
            // Direct RedirectException bypasses Backend open-redirect host filter
            // (same pattern as Payment Connect / Captcha Google OAuth authorize).
            throw new RedirectException($authorizationUrl, 302);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            // Persistent query alert on account page — avoid toast-only “闪一下就没了”.
            return $this->redirectRoute($returnRoute, $this->oauthFailureQuery(
                $adapterCode,
                'connect_failed',
                $e->getMessage(),
            ));
        }
    }

    #[AclAttribute('Weline_Cdn::cdn_oauth_callback', 'CDN OAuth 回调', 'link', '校验 OAuth state 并保存令牌')]
    public function callback(): string
    {
        $returnRoute = 'cdn/backend/account';
        $adapterCode = $this->resolveAdapterCode(
            (string)$this->request->getGet('adapter', ''),
        );
        $state = trim((string)$this->request->getGet('state', ''));
        $error = trim((string)$this->request->getGet('error', ''));
        $errorDescription = trim((string)$this->request->getGet('error_description', ''));

        try {
            $provider = $this->requireProvider($adapterCode);
            $callbackUrl = $provider->getOauthCallbackUrl();

            if ($error !== '') {
                try {
                    $context = $provider->consumeOauthFailureState($state, $callbackUrl);
                    $returnRoute = $context['return_route'];
                } catch (\Throwable) {
                    // Still surface provider error when state was lost after the bounce.
                }

                return $this->redirectRoute($returnRoute, $this->oauthFailureQuery(
                    $adapterCode,
                    $error,
                    $errorDescription,
                ));
            }

            $result = $provider->completeOauthAuthorization(
                trim((string)$this->request->getGet('code', '')),
                $state,
                $callbackUrl,
            );
            $returnRoute = $result['return_route'];
            $adapterName = method_exists($provider, 'getAdapterName')
                ? (string)$provider->getAdapterName()
                : $adapterCode;
            Message::success(__('%{1} OAuth 授权成功。', $adapterName));
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->redirectRoute($returnRoute, $this->oauthFailureQuery(
                $adapterCode,
                'exchange_failed',
                $e->getMessage(),
            ));
        }

        return $this->redirectRoute($returnRoute);
    }

    private function requireProvider(string $adapterCode): OauthCapableProviderInterface
    {
        $provider = $this->resolver()->getOauthCapableAdapter($adapterCode);
        if ($provider === null) {
            throw new \RuntimeException(
                (string)__('适配器 %{1} 不支持一键授权', $adapterCode)
            );
        }

        return $provider;
    }

    /**
     * BC: 未传 adapter 时默认 cloudflare（已登记的 Redirect URL 无 query）。
     */
    private function resolveAdapterCode(string $raw): string
    {
        $code = trim($raw);
        if ($code === '') {
            return 'cloudflare';
        }

        return preg_replace('/[^a-z0-9._-]+/i', '', $code) ?: 'cloudflare';
    }

    private function resolver(): AdapterResolver
    {
        return ObjectManager::getInstance(AdapterResolver::class);
    }

    /**
     * @return array{oauth_error: string, oauth_adapter: string, oauth_cf_error: string, oauth_cf_desc?: string}
     */
    private function oauthFailureQuery(string $adapterCode, string $error, string $description): array
    {
        $error = strtolower(preg_replace('/[^a-z0-9._-]+/i', '_', trim($error)) ?? '');
        $error = substr($error !== '' ? $error : 'unknown', 0, 64);
        $description = preg_replace('/[\r\n\t]+/', ' ', trim($description)) ?? '';
        $description = substr($description, 0, 240);

        $query = [
            'oauth_error' => '1',
            'oauth_adapter' => $adapterCode,
            // Keep oauth_cf_error key for existing account-page contracts / bookmarks.
            'oauth_cf_error' => $error,
        ];
        if ($description !== '') {
            $query['oauth_cf_desc'] = $description;
        }

        return $query;
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirectRoute(string $route, array $query = []): string
    {
        $this->request->getResponse()->redirect(
            $this->request->getUrlBuilder()->getBackendUrl($route, $query)
        );

        return '';
    }
}
