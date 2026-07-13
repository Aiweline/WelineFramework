<?php
declare(strict_types=1);

namespace Weline\Multipass\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Api\Auth\CustomerIdentity;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Multipass\Model\IdentityProvider;

class IdentityClientService
{
    private const STATE_TTL = 600;
    private const STATE_KEY_PREFIX = 'multipass_identity_client_state_';

    public function __construct(
        private readonly Request $request,
        private readonly SessionFactory $sessionFactory,
        private ?CustomerAccountFacadeInterface $customerAccounts = null,
    ) {
    }

    protected function newProviderModel(): IdentityProvider
    {
        return ObjectManager::getInstance(IdentityProvider::class, [], false);
    }

    public function loadProvider(int $providerId): ?IdentityProvider
    {
        $provider = $this->newProviderModel()->load($providerId);
        return $provider->getId() ? $provider : null;
    }

    public function getDefaultProvider(): ?IdentityProvider
    {
        $provider = $this->newProviderModel()
            ->where(IdentityProvider::schema_fields_STATUS, IdentityProvider::STATUS_ACTIVE)
            ->order(IdentityProvider::schema_fields_SORT_ORDER, 'ASC')
            ->order(IdentityProvider::schema_fields_ID, 'ASC')
            ->find()
            ->fetch();

        return $provider->getId() ? $provider : null;
    }

    public function listProviders(int $limit = 50): array
    {
        $items = $this->newProviderModel()
            ->order(IdentityProvider::schema_fields_SORT_ORDER, 'ASC')
            ->order(IdentityProvider::schema_fields_ID, 'DESC')
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        return is_array($items) ? $items : [];
    }

    public function saveProvider(array $data): IdentityProvider
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        $provider = $providerId > 0 ? $this->loadProvider($providerId) : $this->newProviderModel();
        if (!$provider) {
            throw new \InvalidArgumentException((string) __('授权提供方不存在'));
        }

        $name = trim((string) ($data['name'] ?? ''));
        $issuerBaseUrl = rtrim(trim((string) ($data['issuer_base_url'] ?? '')), '/');
        $restBaseUrl = rtrim(trim((string) ($data['rest_base_url'] ?? '')), '/');
        $clientId = trim((string) ($data['client_id'] ?? ''));
        $clientSecret = trim((string) ($data['client_secret'] ?? ''));
        $redirectUri = trim((string) ($data['redirect_uri'] ?? ''));
        $status = trim((string) ($data['status'] ?? IdentityProvider::STATUS_ACTIVE));

        if ($name === '') {
            throw new \InvalidArgumentException((string) __('授权提供方名称不能为空'));
        }
        if ($issuerBaseUrl === '' || filter_var($issuerBaseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('官网授权地址格式不正确'));
        }
        if ($restBaseUrl !== '' && filter_var($restBaseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('官网接口地址格式不正确'));
        }
        if ($clientId === '') {
            throw new \InvalidArgumentException((string) __('Client ID 不能为空'));
        }
        if (!$provider->getId() && $clientSecret === '') {
            throw new \InvalidArgumentException((string) __('Client Secret 不能为空'));
        }
        if ($redirectUri !== '' && filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('本站回调地址格式不正确'));
        }

        $provider->setName($name)
            ->setIssuerBaseUrl($issuerBaseUrl)
            ->setRestBaseUrl($restBaseUrl)
            ->setClientId($clientId)
            ->setRedirectUri($redirectUri)
            ->setScopes((array) ($data['scopes'] ?? []))
            ->setStatus($status)
            ->setSortOrder((int) ($data['sort_order'] ?? 100));

        if ($clientSecret !== '') {
            $provider->setClientSecret($clientSecret);
        }

        $provider->save();

        return $provider;
    }

    public function deleteProvider(int $providerId): bool
    {
        $provider = $this->loadProvider($providerId);
        if (!$provider) {
            return false;
        }

        $provider->delete();
        return true;
    }

    public function buildAuthorizeUrl(IdentityProvider $provider, string $returnUrl = ''): string
    {
        $this->assertProviderReady($provider);

        $state = 'mpstate_' . bin2hex(random_bytes(24));
        $session = $this->sessionFactory->createFrontendSession();
        $session->set(self::STATE_KEY_PREFIX . $state, [
            'provider_id' => $provider->getId(),
            'return_url' => $this->normalizeLocalReturnUrl($returnUrl),
            'created_at' => time(),
        ]);

        $params = [
            'client_id' => $provider->getClientId(),
            'redirect_uri' => $this->resolveRedirectUri($provider),
            'scope' => implode(',', $provider->getScopes()),
            'state' => $state,
        ];

        return $provider->getIssuerBaseUrl() . '/multipass/frontend/identity/authorize?' . http_build_query($params);
    }

    public function completeAuthorization(string $code, string $state): array
    {
        $statePayload = $this->consumeState($state);
        $provider = $this->loadProvider((int) ($statePayload['provider_id'] ?? 0));
        if (!$provider || !$provider->isActive()) {
            throw new \RuntimeException((string) __('授权提供方不存在或已禁用'));
        }
        $this->assertProviderReady($provider);

        $tokenData = $this->exchangeCode($provider, $code);
        $accessToken = (string) ($tokenData['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \RuntimeException((string) __('官网未返回 Access Token'));
        }

        $userInfo = $this->fetchUserInfo($provider, $accessToken);
        $customer = $this->findOrCreateCustomerFromUserInfo($provider, $userInfo);
        $this->bindRemoteAccount($provider, $accessToken, $customer, $userInfo);
        $this->customerAccounts()->login($customer);

        return [
            'provider' => $provider,
            'customer' => $customer,
            'return_url' => (string) ($statePayload['return_url'] ?? '/customer/account'),
            'userinfo' => $userInfo,
        ];
    }

    public function resolveRedirectUri(IdentityProvider $provider): string
    {
        $redirectUri = $provider->getRedirectUri();
        if ($redirectUri !== '') {
            return $redirectUri;
        }

        return rtrim($this->currentBaseUrl(), '/') . '/multipass/frontend/identity/callback';
    }

    private function exchangeCode(IdentityProvider $provider, string $code): array
    {
        $response = $this->postForm($this->endpoint($provider, 'token'), [
            'client_id' => $provider->getClientId(),
            'client_secret' => $provider->getClientSecret(),
            'code' => $code,
            'redirect_uri' => $this->resolveRedirectUri($provider),
        ]);

        $data = $this->extractResponseData($response, __('授权码换 token 失败'));
        if (empty($data['access_token'])) {
            throw new \RuntimeException((string) __('官网 token 响应缺少 access_token'));
        }

        return $data;
    }

    private function fetchUserInfo(IdentityProvider $provider, string $accessToken): array
    {
        $response = $this->getJson($this->endpoint($provider, 'userinfo'), [
            'Authorization: Bearer ' . $accessToken,
        ]);

        $data = $this->extractResponseData($response, __('获取官网用户资料失败'));
        if (empty($data['email'])) {
            throw new \RuntimeException((string) __('官网授权资料缺少邮箱地址'));
        }

        return $data;
    }

    private function bindRemoteAccount(IdentityProvider $provider, string $accessToken, CustomerIdentity $customer, array $userInfo): void
    {
        $response = $this->postForm($this->endpoint($provider, 'bind'), [
            'external_subject_id' => 'customer:' . $customer->getId(),
            'external_display_name' => $customer->getEmail(),
            'metadata' => json_encode([
                'site_base_url' => $this->currentBaseUrl(),
                'official_subject' => (string) ($userInfo['sub'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], [
            'Authorization: Bearer ' . $accessToken,
        ]);

        $this->extractResponseData($response, __('绑定官网授权账号失败'));
    }

    private function findOrCreateCustomerFromUserInfo(IdentityProvider $provider, array $userInfo): CustomerIdentity
    {
        $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException((string) __('官网授权资料缺少有效邮箱地址'));
        }

        $accountService = $this->customerAccounts();
        $customer = $accountService->findByEmail($email);
        if (!$customer) {
            $customer = $accountService->register($email, 'Mp' . bin2hex(random_bytes(12)) . '9', [
                'identity_provider' => $provider->getName(),
                'identity_subject' => (string) ($userInfo['sub'] ?? ''),
            ]);
        }

        $avatar = trim((string) ($userInfo['avatar'] ?? ''));
        if ($avatar !== '') {
            $customer = $accountService->updateAvatar($customer, $avatar);
        }

        return $customer;
    }

    private function customerAccounts(): CustomerAccountFacadeInterface
    {
        return $this->customerAccounts ??= ObjectManager::getInstance(AccountFacadeResolver::class)->customer();
    }

    private function consumeState(string $state): array
    {
        $state = trim($state);
        if ($state === '') {
            throw new \RuntimeException((string) __('授权 state 不能为空'));
        }

        $session = $this->sessionFactory->createFrontendSession();
        $key = self::STATE_KEY_PREFIX . $state;
        $payload = $session->get($key);
        $session->delete($key);

        if (!is_array($payload)) {
            throw new \RuntimeException((string) __('授权 state 已失效，请重新发起登录'));
        }
        if ((int) ($payload['created_at'] ?? 0) + self::STATE_TTL < time()) {
            throw new \RuntimeException((string) __('授权 state 已过期，请重新发起登录'));
        }

        return $payload;
    }

    private function assertProviderReady(IdentityProvider $provider): void
    {
        if (!$provider->isActive()) {
            throw new \RuntimeException((string) __('授权提供方已禁用'));
        }
        if ($provider->getIssuerBaseUrl() === '' || $provider->getClientId() === '' || $provider->getClientSecret() === '') {
            throw new \RuntimeException((string) __('授权提供方配置不完整'));
        }
    }

    private function endpoint(IdentityProvider $provider, string $name): string
    {
        return $provider->getRestBaseUrl() . '/multipass/rest/v1/identity/' . $name;
    }

    private function postForm(string $url, array $fields, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return $this->sendHttpRequest('POST', $url, http_build_query($fields), $headers);
    }

    private function getJson(string $url, array $headers = []): array
    {
        $headers[] = 'Accept: application/json';
        return $this->sendHttpRequest('GET', $url, '', $headers);
    }

    private function sendHttpRequest(string $method, string $url, string $body = '', array $headers = []): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY => '',
            ]);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'status' => $statusCode,
                'body' => is_string($responseBody) ? $responseBody : '',
                'error' => $error,
                'url' => $url,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 12,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);

        return [
            'status' => 0,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $responseBody === false ? (string) __('HTTP 请求失败') : '',
            'url' => $url,
        ];
    }

    private function extractResponseData(array $response, mixed $fallbackMessage): array
    {
        $fallbackMessage = (string) $fallbackMessage;
        if ((string) ($response['error'] ?? '') !== '') {
            throw new \RuntimeException($fallbackMessage . ': ' . (string) $response['error']);
        }

        $body = (string) ($response['body'] ?? '');
        $decoded = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException((string) $fallbackMessage);
        }

        if (($decoded['success'] ?? false) !== true) {
            $message = (string) ($decoded['message'] ?? $decoded['msg'] ?? $fallbackMessage);
            throw new \RuntimeException($message);
        }

        $data = $decoded['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    private function currentBaseUrl(): string
    {
        try {
            $baseUrl = $this->request->getBaseUrl();
            if (is_string($baseUrl) && trim($baseUrl) !== '') {
                return rtrim($baseUrl, '/');
            }
        } catch (\Throwable) {
        }

        return rtrim((string) (Env::getInstance()->getBaseUrl() ?? ''), '/');
    }

    private function normalizeLocalReturnUrl(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || str_starts_with($returnUrl, '//') || str_contains($returnUrl, '\\')) {
            return '/customer/account';
        }
        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnUrl)) {
            $base = parse_url($this->currentBaseUrl());
            $target = parse_url($returnUrl);
            if (($base['host'] ?? '') !== ($target['host'] ?? '')) {
                return '/customer/account';
            }
        }

        return str_starts_with($returnUrl, '/') ? $returnUrl : '/' . $returnUrl;
    }
}
