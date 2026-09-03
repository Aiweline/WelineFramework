<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\SessionFactory;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * PayPal 沙箱/正式环境 OAuth 一键授权与回调凭据落库。
 */
final class PayPalOAuthService
{
    public const MODULE = 'Weline_Payment';
    public const AREA = 'backend';
    private const SESSION_KEY = 'weline_payment_paypal_oauth';
    private const STATE_TTL = 900;
    private const CONFIG_PREFIX = 'payment/method/paypal/';
    private const OAUTH_SCOPES = 'openid email https://uri.paypal.com/services/payments/realtimepayment https://uri.paypal.com/services/payments/payment/authcapture';

    public function __construct(
        private readonly ConfigStore $store,
        private readonly Url $url,
        private readonly PaymentConfigValidationService $configValidation,
        private readonly PayPalPlatformCredentialService $platformCredentials,
        private readonly PayPalSandboxPublicOriginService $publicOrigin,
        private readonly ?PayPalApiClient $apiClient = null,
    ) {
    }

    /**
     * @return array{authorization_url:string,state:string,environment:string}
     */
    public function start(string $environment = 'sandbox', ?string $scope = null, array $context = []): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $pkce = false;
        $codeVerifier = '';
        if ($environment === 'sandbox') {
            $provisioned = $this->platformCredentials->ensureSandboxProvisioned($scope);
            $pkce = !empty($provisioned['pkce']);
        }

        $config = $this->loadEnvironmentConfig($environment, $scope);
        if (trim((string) ($config['client_id'] ?? '')) === '') {
            throw new \RuntimeException((string) __(
                '暂时无法连接 PayPal %{1}，请稍后重试。若问题持续，请联系站点管理员。',
                [$environment === 'live' ? (string) __('正式环境') : (string) __('沙箱')]
            ));
        }
        if ($environment === 'live' && trim((string) ($config['client_secret'] ?? '')) === '') {
            throw new \RuntimeException((string) __(
                '暂时无法连接 PayPal 正式环境，请稍后重试。若问题持续，请联系站点管理员。'
            ));
        }

        $this->ensureDefaultCallbackUrls($scope);

        $state = bin2hex(random_bytes(24));
        $redirectUri = $this->callbackUrl($environment, $scope);
        $bucket = $this->session()->getData(self::SESSION_KEY);
        if (!\is_array($bucket)) {
            $bucket = [];
        }

        $query = [
            'client_id' => (string) $config['client_id'],
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPES,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        if ($environment === 'sandbox' && ($pkce || trim((string) ($config['client_secret'] ?? '')) === '')) {
            $pkcePair = PayPalOAuthPkceHelper::createPair();
            $codeVerifier = $pkcePair['code_verifier'];
            $query['code_challenge'] = $pkcePair['code_challenge'];
            $query['code_challenge_method'] = $pkcePair['method'];
            $pkce = true;
        }

        $bucket[$state] = [
            'method_code' => 'paypal',
            'environment' => $environment,
            'scope' => $scope,
            'redirect_uri' => $redirectUri,
            'created_at' => time(),
            'pkce' => $pkce,
            'code_verifier' => $codeVerifier,
            'website_code' => trim((string) ($context['website_code'] ?? '')),
            'store_code' => trim((string) ($context['store_code'] ?? '')),
            'channel_code' => trim((string) ($context['channel_code'] ?? '')),
            'locale' => trim((string) ($context['locale'] ?? '')),
        ];
        $this->session()->setData(self::SESSION_KEY, $bucket);
        $this->session()->save();

        $authorizeHost = $environment === 'live'
            ? 'https://www.paypal.com'
            : 'https://www.sandbox.paypal.com';

        return [
            'state' => $state,
            'environment' => $environment,
            'authorization_url' => $authorizeHost . '/signin/authorize?' . http_build_query($query),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function complete(array $params): array
    {
        if (!empty($params['error'])) {
            throw new \RuntimeException((string) ($params['error_description'] ?? $params['error']));
        }

        $state = trim((string) ($params['state'] ?? ''));
        $payload = $this->peekState($state);
        if ($payload === null) {
            throw new \RuntimeException((string) __('PayPal 授权状态无效或已过期，请重新发起授权。'));
        }

        if (\is_array($payload['completed_result'] ?? null)) {
            return $payload['completed_result'];
        }

        $code = trim((string) ($params['code'] ?? ''));
        if ($code === '') {
            throw new \RuntimeException((string) __('PayPal 授权回调缺少授权码。'));
        }

        $environment = (string) ($payload['environment'] ?? 'sandbox');
        $scope = \is_string($payload['scope'] ?? null) ? $payload['scope'] : null;
        if ($environment === 'sandbox') {
            $this->platformCredentials->ensureSandboxProvisioned($scope);
        }
        $config = $this->loadEnvironmentConfig($environment, $scope);
        $token = $this->client()->exchangeAuthorizationCode(
            $config,
            $code,
            (string) ($payload['redirect_uri'] ?? $this->callbackUrl($environment, $scope)),
            !empty($payload['pkce']) ? (string) ($payload['code_verifier'] ?? '') : null,
        );

        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException((string) __('PayPal OAuth 未返回 Access Token。'));
        }

        $prefix = $environment . '_';
        $this->writeConfig($prefix . 'oauth_access_token', $accessToken, $scope, true);
        $refreshToken = trim((string) ($token['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $this->writeConfig($prefix . 'oauth_refresh_token', $refreshToken, $scope, true);
        }

        $payerId = $this->client()->fetchMerchantPayerId(array_replace($config, [
            'oauth_access_token' => $accessToken,
        ]));
        if ($payerId !== '') {
            $this->writeConfig($prefix . 'merchant_payer_id', $payerId, $scope, false);
        }

        $this->writeConfig($prefix . 'oauth_connected_at', date('Y-m-d H:i:s'), $scope, false);

        $connection = $this->client()->testConnection(array_replace($config, [
            'oauth_access_token' => $accessToken,
        ]));
        if (empty($connection['success'])) {
            throw new \RuntimeException((string) ($connection['message'] ?? __('PayPal 连接测试失败。')));
        }

        $result = [
            'success' => true,
            'method_code' => 'paypal',
            'environment' => $environment,
            'scope' => $scope,
            'website_code' => trim((string) ($payload['website_code'] ?? '')),
            'store_code' => trim((string) ($payload['store_code'] ?? '')),
            'channel_code' => trim((string) ($payload['channel_code'] ?? '')),
            'locale' => trim((string) ($payload['locale'] ?? '')),
            'merchant_payer_id' => $payerId,
            'message_title' => (string) __('PayPal %{1} 授权完成', [
                $environment === 'live' ? (string) __('正式环境') : (string) __('沙箱'),
            ]),
            'message' => (string) __(
                $environment === 'live'
                    ? '商户凭据已写入当前配置范围，可进行收款验证。'
                    : '商户凭据已写入当前配置范围，可点击「测试沙箱连接」验证。'
            ),
        ];
        $this->markStateCompleted($state, $result);

        return $result;
    }

    public function ownsOAuthState(string $state): bool
    {
        return $this->peekState(trim($state)) !== null;
    }

    public function revoke(string $environment = 'sandbox', ?string $scope = null): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $prefix = $environment . '_';
        foreach ([
            $prefix . 'oauth_access_token',
            $prefix . 'oauth_refresh_token',
            $prefix . 'merchant_payer_id',
            $prefix . 'oauth_connected_at',
        ] as $key) {
            $this->store->deleteScopedConfig(
                self::CONFIG_PREFIX . $key,
                self::MODULE,
                self::AREA,
                $scope,
                SystemConfig::LOCALE_DEFAULT
            );
        }
    }

    /**
     * @return array{success:bool,message:string,details:array<string,mixed>}
     */
    public function testConnection(string $environment = 'sandbox', ?string $scope = null): array
    {
        $environment = $this->normalizeEnvironment($environment);
        if ($environment === 'sandbox') {
            $this->platformCredentials->ensureSandboxProvisioned($scope);
        }
        $config = $this->loadEnvironmentConfig($environment, $scope);
        $result = $this->client()->testConnection($config);

        return [
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
            'details' => \is_array($result['details'] ?? null) ? $result['details'] : [],
        ];
    }

    public function ensureDefaultCallbackUrls(?string $scope = null): void
    {
        $storageScope = $this->requireStorageScope($scope);
        $canonicalReturn = $this->browserReturnUrl($storageScope);
        $currentReturn = $this->readConfig('return_url', $storageScope);
        if ($canonicalReturn !== '' && !$this->isValidBrowserReturnUrl($currentReturn, $storageScope)) {
            $this->writeConfig('return_url', $canonicalReturn, $storageScope, false);
        }
        if ($this->readConfig('cancel_url', $storageScope) === '') {
            $catalog = ObjectManager::getInstance(PaymentShellCallbackUrlCatalog::class);
            $this->writeConfig(
                'cancel_url',
                $catalog->browserCancel($storageScope, 'paypal'),
                $storageScope,
                false,
            );
        }
    }

    private function isValidBrowserReturnUrl(string $url, ?string $expectedStorageScope = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $pathOk = preg_match(
            '#/payment/frontend/callback/([a-z0-9][a-z0-9_.-]*)/?$#D',
            rtrim($path, '/'),
            $pathMatch
        ) === 1
            && isset($pathMatch[1])
            && !str_ends_with((string) $pathMatch[1], '.cancel')
            && !PaymentBrowserCallbackRoutes::isReservedCallbackSegment((string) $pathMatch[1]);
        if (!$pathOk) {
            return false;
        }

        $expectedStorageScope = $expectedStorageScope !== null
            ? strtolower(trim($expectedStorageScope))
            : '';
        if ($expectedStorageScope === '') {
            return true;
        }

        $query = [];
        if (isset($parts['query']) && \is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        $got = strtolower(trim((string) ($query[PaymentBrowserCallbackRoutes::QUERY_TARGET_SCOPE] ?? '')));

        return $got === $expectedStorageScope;
    }

    public function callbackUrl(string $environment = 'sandbox', ?string $storageScope = null): string
    {
        return $this->browserReturnUrl($storageScope);
    }

    /**
     * 支付模块唯一浏览器 Return 路径（Sandbox/Live OAuth 与配置默认 return_url 共用）。
     * 必须带 target_scope=当前写入范围，避免店铺授权时看起来像写到 Global。
     */
    public function browserReturnUrl(?string $storageScope = null): string
    {
        $storageScope = $this->requireStorageScope($storageScope);

        return ObjectManager::getInstance(PaymentShellCallbackUrlCatalog::class)
            ->browserReturnRegister($storageScope, 'paypal');
    }

    private function requireStorageScope(?string $storageScope): string
    {
        $storageScope = strtolower(trim((string) $storageScope));
        if (!PaymentBrowserCallbackRoutes::isStorageScope($storageScope)) {
            throw new \InvalidArgumentException((string) __(
                '支付回调地址必须携带显式配置范围 target_scope（三段 storage_scope）。'
            ));
        }

        return $storageScope;
    }

    public function configUrl(?string $scope = null, array $context = []): string
    {
        return $this->sandboxAuthorizeGuideUrl($context, $scope);
    }

    /**
     * 统一配置中心深度链接：定位 PayPal 沙箱「一键授权」适配入口。
     *
     * @param array{scope?:string,website_code?:string,store_code?:string,channel_code?:string,locale?:string} $context
     */
    public function sandboxAuthorizeGuideUrl(array $context = [], ?string $scope = null): string
    {
        $params = [
            'module' => self::MODULE,
            'area' => self::AREA,
            'search' => 'paypal',
            'q' => 'paypal',
            'guide_key' => 'adapter:paypal.sandbox.authorize',
            'guide_locate' => 'adapter:paypal.sandbox.authorize',
            'guide_title' => (string) __('PayPal 沙箱一键授权'),
            'guide_summary' => (string) __(
                '可在 Global 或 Website（含默认站点）下点击「沙箱一键授权」跳转 PayPal Sandbox 登录。'
            ),
        ];
        $scope = $scope ?? trim((string) ($context['scope'] ?? ''));
        if ($scope !== '') {
            $params['scope'] = $scope;
        }
        foreach (['website_code', 'store_code', 'channel_code', 'locale'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        $built = (string) $this->url->getBackendUrl('weline_systemconfig/backend/config', $params);
        if ($this->isAbsoluteHttpUrl($built) && $this->backendGuideUrlHasPrefix($built)) {
            return $built;
        }

        // CLI / 无请求上下文：getBackendUrl 可能把后台前缀误当成 host，改用公网 Origin + 前缀路径拼装。
        $origin = rtrim($this->publicOrigin->resolvePublicOrigin(), '/');
        $backendPrefix = trim((string) (\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($origin === '' || $backendPrefix === '') {
            return $built;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $origin . '/' . $backendPrefix . '/weline_systemconfig/backend/config'
            . ($query !== '' ? '?' . $query : '');
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        return \in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && $host !== 'http'
            && $host !== 'https'
            && str_contains($host, '.');
    }

    private function backendGuideUrlHasPrefix(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $backendPrefix = trim((string) (\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($backendPrefix === '') {
            return true;
        }

        return str_starts_with($path, '/' . $backendPrefix . '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEnvironmentConfig(string $environment, ?string $scope): array
    {
        $raw = [];
        foreach ([
            'client_id',
            'client_secret',
            'return_url',
            'cancel_url',
            'webhook_id',
            'oauth_access_token',
            'oauth_refresh_token',
            'merchant_payer_id',
            'oauth_connected_at',
        ] as $suffix) {
            $raw[$environment . '_' . $suffix] = $this->readConfig($environment . '_' . $suffix, $scope);
            if ($suffix !== 'client_id' && $suffix !== 'client_secret') {
                $raw[$suffix] = $this->readConfig($suffix, $scope);
            }
        }

        return $this->configValidation->resolveEnvironmentConfig($raw, $environment);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peekState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        $bucket = $this->session()->getData(self::SESSION_KEY);
        if (!\is_array($bucket) || !isset($bucket[$state]) || !\is_array($bucket[$state])) {
            return null;
        }
        $payload = $bucket[$state];
        $createdAt = (int) ($payload['created_at'] ?? 0);
        if ($createdAt <= 0 || $createdAt + self::STATE_TTL < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function markStateCompleted(string $state, array $result): void
    {
        $bucket = $this->session()->getData(self::SESSION_KEY);
        if (!\is_array($bucket) || !isset($bucket[$state]) || !\is_array($bucket[$state])) {
            return;
        }
        $bucket[$state]['completed_result'] = $result;
        $bucket[$state]['code_verifier'] = '';
        $this->session()->setData(self::SESSION_KEY, $bucket);
        $this->session()->save();
    }

    private function readConfig(string $suffix, ?string $scope): string
    {
        // OAuth 凭据与语言无关：始终按 default locale 读写，避免后台 UI 语言
        // （如 zh_Hans_CN）写入后，配置页选 default 读不到、按钮无法切到「重新授权」。
        $value = $this->store->getConfig(
            self::CONFIG_PREFIX . $suffix,
            self::MODULE,
            self::AREA,
            null,
            $scope,
            SystemConfig::LOCALE_DEFAULT
        );

        return trim((string) $value);
    }

    private function writeConfig(string $suffix, string $value, ?string $scope, bool $sensitive): void
    {
        $this->store->setScopedConfig(
            self::CONFIG_PREFIX . $suffix,
            $value,
            self::MODULE,
            self::AREA,
            $scope,
            SystemConfig::LOCALE_DEFAULT,
            $sensitive ? ['is_sensitive' => true, 'reason' => 'paypal_oauth_callback'] : ['reason' => 'paypal_oauth_callback'],
        );
    }

    private function normalizeEnvironment(string $environment): string
    {
        return strtolower(trim($environment)) === 'live' ? 'live' : 'sandbox';
    }

    private function client(): PayPalApiClient
    {
        return $this->apiClient ?? new PayPalApiClient();
    }

    private function session(): Session
    {
        $authSession = SessionFactory::getInstance()->createBackendSession();
        if (!$authSession->isStarted()) {
            $authSession->start(null);
        }

        $session = $authSession->getSession();
        if (!$session instanceof Session) {
            throw new \RuntimeException('PayPal OAuth requires backend Session instance.');
        }

        return $session;
    }
}
