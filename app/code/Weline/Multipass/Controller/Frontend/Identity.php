<?php
declare(strict_types=1);

namespace Weline\Multipass\Controller\Frontend;

use Weline\Customer\Model\Customer;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Service\IdentityClientService;
use Weline\Multipass\Service\IdentityBridgeService;

class Identity extends FrontendController
{
    protected ?string $layoutType = 'account.auth';

    private ?IdentityBridgeService $identityBridgeService = null;
    private ?IdentityClientService $identityClientService = null;

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

            return (string) $this->redirect($this->buildRedirectUrl(
                $authorizationCode->getRedirectUri(),
                $authorizationCode->getCode(),
                $authorizationCode->getState()
            ));
        } catch (\Throwable $e) {
            return $this->renderAuthorizePage($e->getMessage());
        }
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
            return (string) $this->redirect($this->getIdentityClientService()->buildAuthorizeUrl(
                $provider,
                $this->getStringParam('return_url')
            ));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return (string) $this->redirect('/customer/account/login');
        }
    }

    public function getCallback(): string
    {
        try {
            $result = $this->getIdentityClientService()->completeAuthorization(
                $this->getStringParam('code'),
                $this->getStringParam('state')
            );
            $this->getMessageManager()->addSuccess(__('官网授权登录成功'));

            return (string) $this->redirect((string) ($result['return_url'] ?? '/customer/account'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('官网授权登录失败：%{1}', [$e->getMessage()]));
            return (string) $this->redirect('/customer/account/login');
        }
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

    private function getCurrentCustomer(): ?Customer
    {
        $user = $this->getLoginUser();
        if ($user instanceof Customer && $user->getId()) {
            return $user;
        }

        $userId = (int) ($this->getLoginUserId() ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $customer = ObjectManager::getInstance(Customer::class, [], false)->load($userId);
        return $customer->getId() ? $customer : null;
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

    private function normalizeLocalReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || str_starts_with($returnUrl, '//') || str_contains($returnUrl, '\\')) {
            return '/customer/account/index#identity-bridge';
        }
        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnUrl)) {
            return '/customer/account/index#identity-bridge';
        }

        return str_starts_with($returnUrl, '/') ? $returnUrl : '/' . $returnUrl;
    }
}
