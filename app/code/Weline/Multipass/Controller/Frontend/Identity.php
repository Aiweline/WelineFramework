<?php
declare(strict_types=1);

namespace Weline\Multipass\Controller\Frontend;

use Weline\Customer\Api\Auth\CustomerIdentity;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\TrustedApp;
use Weline\Multipass\Service\AccountFacadeResolver;
use Weline\Multipass\Service\IdentityClientService;
use Weline\Multipass\Service\IdentityBridgeService;

class Identity extends FrontendController
{
    protected ?string $layoutType = 'account.auth';

    private ?IdentityBridgeService $identityBridgeService = null;
    private ?IdentityClientService $identityClientService = null;
    private ?AccountFacadeResolver $accountFacades = null;

    public function getAuthorize(): string
    {
        return $this->renderAuthorizePage();
    }

    public function postAuthorize(): string
    {
        $customer = $this->getCurrentCustomer();
        if (!$customer) {
            return $this->renderAuthorizePage((string) __('请先登录后再继续授权'));
        }

        try {
            $authorizationCode = $this->getIdentityBridgeService()->authorizeCustomer(
                $this->getStringParam('client_id'),
                $this->getStringParam('redirect_uri'),
                $customer,
                $this->getScopeParam(),
                $this->getStringParam('state')
            );

            $redirectUrl = $this->buildRedirectUrl(
                $authorizationCode->getRedirectUri(),
                $authorizationCode->getCode(),
                $authorizationCode->getState()
            );
        } catch (\Throwable $e) {
            return $this->renderAuthorizePage($e->getMessage());
        }

        return (string) $this->redirect($redirectUrl);
    }

    public function postRevoke(): string
    {
        $customer = $this->getCurrentCustomer();
        if (!$customer) {
            return $this->redirectToLogin();
        }

        $bindingId = (int) $this->getStringParam('binding_id');
        $returnUrl = $this->normalizeLocalReturnUrl($this->getStringParam('return_url'));

        if ($this->getIdentityBridgeService()->revokeCustomerBinding($bindingId, (int) $customer->getId())) {
            $this->getMessageManager()->addSuccess(__('授权应用已撤销'));
        } else {
            $this->getMessageManager()->addError(__('授权应用不存在或无权撤销'));
        }

        return (string) $this->redirect($returnUrl ?: '/customer/account/index#identity-bridge');
    }

    public function postDeveloperApplication(): string
    {
        $customer = $this->getCurrentCustomer();
        if (!$customer) {
            return $this->redirectToLogin();
        }

        $returnUrl = $this->normalizeLocalReturnUrl(
            $this->getStringParam('return_url'),
            '/customer/account/index#multipass-developer-apps'
        );

        try {
            [$app, $clientSecret] = $this->getIdentityBridgeService()->createDeveloperApplication(
                $customer,
                $this->getStringParam('name'),
                $this->getStringParam('redirect_uri'),
                $this->getStringParam('trusted_domain'),
                $this->getStringParam('app_type') ?: 'custom',
                $this->getDeveloperApplicationScopes()
            );

            if ($app->getApplicationStatus() === TrustedApp::APPLICATION_APPROVED && $app->getStatus() === TrustedApp::STATUS_ACTIVE) {
                MessageManager::success(__('Multipass 管理申请已提交并自动通过'));
            } else {
                MessageManager::success(__('Multipass 管理申请已提交，请等待审核'));
            }
            MessageManager::warning(__('客户端 ID：%{1}', [$app->getClientId()]));
            MessageManager::warning(__('客户端密钥：%{1}（请妥善保管，仅显示一次）', [$clientSecret]));
            if ($app->getApplicationStatus() === TrustedApp::APPLICATION_APPROVED && $app->getStatus() === TrustedApp::STATUS_ACTIVE) {
                MessageManager::warning(__('该应用已启用，可立即用于 Weline 授权登录'));
            } else {
                MessageManager::warning(__('审核通过后，该应用才能用于 Weline 授权登录'));
            }
        } catch (\Throwable $e) {
            MessageManager::error(__('Multipass 管理申请提交失败：%{1}', [$e->getMessage()]));
        }

        return (string) $this->redirect($returnUrl ?: '/customer/account/index#multipass-developer-apps');
    }

    public function getLogin(): string
    {
        $providerId = (int) $this->getStringParam('provider_id');
        $provider = $providerId > 0
            ? $this->getIdentityClientService()->loadProvider($providerId)
            : $this->getIdentityClientService()->getDefaultProvider();

        if (!$provider) {
            $this->getMessageManager()->addError(__('官网授权登录未配置'));
            return (string) $this->redirect('/customer/account/login');
        }

        try {
            $authorizeUrl = $this->getIdentityClientService()->buildAuthorizeUrl(
                $provider,
                $this->getStringParam('return_url')
            );
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return (string) $this->redirect('/customer/account/login');
        }

        return (string) $this->redirect($authorizeUrl);
    }

    public function getCallback(): string
    {
        try {
            $result = $this->getIdentityClientService()->completeAuthorization(
                $this->getStringParam('code'),
                $this->getStringParam('state')
            );
            $this->getMessageManager()->addSuccess(__('官网授权登录成功'));

            $returnUrl = (string) ($result['return_url'] ?? '/customer/account');
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('官网授权登录失败：%{1}', [$e->getMessage()]));
            return (string) $this->redirect('/customer/account/login');
        }

        return (string) $this->redirect($returnUrl);
    }

    private function renderAuthorizePage(string $errorMessage = ''): string
    {
        $payload = [
            'authorization_error' => $errorMessage,
            'title' => __('授权应用访问你的 Weline 账号'),
            'login_url' => '/customer/account/login?redirect_url=' . rawurlencode($this->currentRequestUrl()),
            'meta' => [
                'showHeader' => false,
                'showFooter' => false,
            ],
        ];

        try {
            $payload = array_merge($payload, $this->getIdentityBridgeService()->resolveAuthorizationRequest(
                $this->getStringParam('client_id'),
                $this->getStringParam('redirect_uri'),
                $this->getScopeParam()
            ));
            $payload['state'] = $this->getStringParam('state');
            $payload['user'] = $this->getCurrentCustomer();
        } catch (\Throwable $e) {
            if ($payload['authorization_error'] === '') {
                $payload['authorization_error'] = $e->getMessage();
            }
        }

        $this->assign($payload);

        return (string) $this->fetch('Weline_Multipass::templates/frontend/identity/authorize.phtml');
    }

    private function getIdentityBridgeService(): IdentityBridgeService
    {
        if ($this->identityBridgeService === null) {
            $this->identityBridgeService = ObjectManager::getInstance(IdentityBridgeService::class);
        }

        return $this->identityBridgeService;
    }

    private function getIdentityClientService(): IdentityClientService
    {
        if ($this->identityClientService === null) {
            $this->identityClientService = ObjectManager::getInstance(IdentityClientService::class);
        }

        return $this->identityClientService;
    }

    private function getCurrentCustomer(): ?CustomerIdentity
    {
        return $this->accountFacades()->customer()->current();
    }

    private function accountFacades(): AccountFacadeResolver
    {
        return $this->accountFacades ??= ObjectManager::getInstance(AccountFacadeResolver::class);
    }

    private function redirectToLogin(): string
    {
        return (string) $this->redirect('/customer/account/login?redirect_url=' . rawurlencode($this->currentRequestUrl()));
    }

    private function currentRequestUrl(): string
    {
        try {
            $url = $this->request->getUrlBuilder()->getCurrentUrl();
            if (is_string($url) && trim($url) !== '') {
                return $url;
            }
        } catch (\Throwable) {
        }

        return (string) ($this->request->getServer('WELINE_ORIGIN_REQUEST_URI') ?: $this->request->getServer('REQUEST_URI') ?: '/');
    }

    private function getStringParam(string $key): string
    {
        $value = $this->request->getBodyParam($key);
        if ($value === null) {
            $value = $this->request->getPost($key);
        }
        if ($value === null) {
            $value = $this->request->getParam($key);
        }

        return trim((string) ($value ?? ''));
    }

    private function getArrayParam(string $key): array
    {
        $value = $this->request->getBodyParam($key);
        if ($value === null) {
            $value = $this->request->getPost($key);
        }
        if ($value === null) {
            $value = $this->request->getParam($key);
        }
        if ($value === null && $key === 'scopes') {
            $value = $this->request->getParam('scope');
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $value)));
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $decoded)));
            }

            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    private function getScopeParam(): array
    {
        $scopes = $this->getArrayParam('scopes');
        if ($scopes === []) {
            $scopes = $this->getArrayParam('scope');
        }

        return array_values(array_unique($scopes));
    }

    private function buildRedirectUrl(string $redirectUri, string $code, string $state = ''): string
    {
        $params = ['code' => $code];
        if ($state !== '') {
            $params['state'] = $state;
        }

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
    }

    private function getDeveloperApplicationScopes(): array
    {
        $scopes = $this->getArrayParam('allowed_scopes');
        if ($scopes === []) {
            $scopes = $this->getScopeParam();
        }

        $allowed = ['profile.basic', 'profile.email', 'account.bind', 'appstore.account', 'community.account'];
        $scopes = array_values(array_intersect(array_values(array_unique($scopes)), $allowed));

        return $scopes !== [] ? $scopes : ['profile.basic', 'profile.email', 'account.bind'];
    }

    private function normalizeLocalReturnUrl(string $returnUrl, string $fallback = '/customer/account/index#identity-bridge'): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || str_starts_with($returnUrl, '//') || str_contains($returnUrl, '\\')) {
            return $fallback;
        }
        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnUrl)) {
            return $fallback;
        }

        return str_starts_with($returnUrl, '/') ? $returnUrl : '/' . $returnUrl;
    }
}
