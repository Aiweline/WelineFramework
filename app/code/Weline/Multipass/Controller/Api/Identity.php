<?php
declare(strict_types=1);

namespace Weline\Multipass\Controller\Api;

use Weline\Customer\Model\Customer;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Multipass\Model\TrustedApp;
use Weline\Multipass\Service\IdentityBridgeService;

class Identity extends FrontendRestController
{
    private ?IdentityBridgeService $identityBridgeService = null;

    public function getAuthorize(): array|string
    {
        try {
            $clientId = $this->getStringParam('client_id');
            if ($clientId === '') {
                return $this->error(__('client_id 不能为空'), '', 400);
            }

            $app = $this->getIdentityBridgeService()->loadActiveAppByClientId($clientId);
            if (!$app) {
                return $this->error(__('应用不存在或已禁用'), '', 404);
            }

            $redirectUri = $this->getStringParam('redirect_uri') ?: $app->getRedirectUri();
            if (!$this->getIdentityBridgeService()->isRedirectUriAllowed($app, $redirectUri)) {
                return $this->error(__('回调地址不匹配'), '', 400);
            }

            $customer = $this->getCurrentCustomer();

            return $this->success(__('授权信息获取成功'), [
                'app' => $this->formatApp($app),
                'redirect_uri' => $redirectUri,
                'requested_scopes' => $this->getScopeParam(),
                'allowed_scopes' => $app->getAllowedScopes(),
                'logged_in' => $customer !== null,
                'user' => $customer ? [
                    'customer_id' => (int) $customer->getId(),
                    'username' => (string) ($customer->getUsername() ?? ''),
                    'email' => $customer->getEmail(),
                    'avatar' => (string) ($customer->getAvatar() ?? ''),
                ] : null,
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    public function postAuthorize(): array|string
    {
        try {
            $customer = $this->getCurrentCustomer();
            if (!$customer) {
                return $this->error(__('请先登录后再授权'), [
                    'login_url' => '/customer/account/login',
                ], 401);
            }

            $authorizationCode = $this->getIdentityBridgeService()->authorizeCustomer(
                $this->getStringParam('client_id'),
                $this->getStringParam('redirect_uri'),
                $customer,
                $this->getScopeParam(),
                $this->getStringParam('state')
            );

            return $this->success(__('授权成功'), [
                'code' => $authorizationCode->getCode(),
                'expires_at' => $authorizationCode->getExpiresAt(),
                'redirect_uri' => $authorizationCode->getRedirectUri(),
                'redirect_url' => $this->buildRedirectUrl(
                    $authorizationCode->getRedirectUri(),
                    $authorizationCode->getCode(),
                    $authorizationCode->getState()
                ),
                'scopes' => $authorizationCode->getScopes(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    public function postToken(): array|string
    {
        try {
            $result = $this->getIdentityBridgeService()->exchangeCode(
                $this->getStringParam('client_id'),
                $this->getStringParam('client_secret'),
                $this->getStringParam('code'),
                $this->getStringParam('redirect_uri')
            );
            if (!$result) {
                return $this->error(__('授权码或客户端凭证无效'), '', 401);
            }

            return $this->success(__('Token 发放成功'), $result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    public function postRefresh(): array|string
    {
        try {
            $result = $this->getIdentityBridgeService()->refresh(
                $this->getStringParam('client_id'),
                $this->getStringParam('client_secret'),
                $this->getStringParam('refresh_token')
            );
            if (!$result) {
                return $this->error(__('刷新 token 无效'), '', 401);
            }

            return $this->success(__('Token 刷新成功'), $result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    public function postRevoke(): array|string
    {
        $token = $this->getTokenFromRequest();
        if ($token === '') {
            return $this->error(__('Token 不能为空'), '', 400);
        }

        if (!$this->getIdentityBridgeService()->revoke($token)) {
            return $this->error(__('Token 不存在或已失效'), '', 404);
        }

        return $this->success(__('Token 已撤销'));
    }

    public function getUserinfo(): array|string
    {
        $token = $this->getTokenFromRequest();
        if ($token === '') {
            return $this->error(__('Token 不能为空'), '', 400);
        }

        $userInfo = $this->getIdentityBridgeService()->getUserInfo($token);
        if (!$userInfo) {
            return $this->error(__('Token 无效或已过期'), '', 401);
        }

        return $this->success(__('用户资料获取成功'), $userInfo);
    }

    public function postBind(): array|string
    {
        try {
            $token = $this->getTokenFromRequest();
            if ($token === '') {
                return $this->error(__('Token 不能为空'), '', 400);
            }

            $result = $this->getIdentityBridgeService()->bindExternalAccount(
                $token,
                $this->getStringParam('external_subject_id'),
                $this->getStringParam('external_display_name'),
                $this->getMapParam('metadata')
            );
            if (!$result) {
                return $this->error(__('Token 无效或已过期'), '', 401);
            }

            return $this->success(__('账号绑定成功'), $result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    private function getIdentityBridgeService(): IdentityBridgeService
    {
        if ($this->identityBridgeService === null) {
            $this->identityBridgeService = ObjectManager::getInstance(IdentityBridgeService::class);
        }

        return $this->identityBridgeService;
    }

    private function getCurrentCustomer(): ?Customer
    {
        $session = SessionFactory::getInstance()->createFrontendSession();
        if (!$session->isLoggedIn()) {
            return null;
        }

        $user = $session->getUser();
        if ($user instanceof Customer && $user->getId()) {
            return $user;
        }

        $userId = (int) ($session->getUserId() ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $customer = ObjectManager::getInstance(Customer::class, [], false)->load($userId);
        return $customer->getId() ? $customer : null;
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

    private function getMapParam(string $key): array
    {
        $value = $this->request->getBodyParam($key);
        if ($value === null) {
            $value = $this->request->getPost($key);
        }
        if ($value === null) {
            $value = $this->request->getParam($key);
        }

        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
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

    private function getTokenFromRequest(): string
    {
        $token = (string) ($this->request->getAuth('bearer') ?? '');
        if ($token !== '') {
            return $token;
        }

        $apiToken = $this->request->getHeader('X-API-Token');
        if (is_string($apiToken) && trim($apiToken) !== '') {
            return trim($apiToken);
        }

        return $this->getStringParam('token');
    }

    private function buildRedirectUrl(string $redirectUri, string $code, string $state = ''): string
    {
        $params = ['code' => $code];
        if ($state !== '') {
            $params['state'] = $state;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return $redirectUri . $separator . http_build_query($params);
    }

    private function formatApp(TrustedApp $app): array
    {
        return [
            'app_id' => $app->getId(),
            'client_id' => $app->getClientId(),
            'name' => $app->getName(),
            'type' => $app->getAppType(),
            'trusted_domain' => $app->getTrustedDomain(),
        ];
    }
}
