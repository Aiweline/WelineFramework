<?php
declare(strict_types=1);

namespace Weline\Multipass\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\AccountBinding;
use Weline\Multipass\Model\AuthorizationCode;
use Weline\Multipass\Model\IdentityToken;
use Weline\Multipass\Model\TrustedApp;
use Weline\SystemConfig\Model\SystemConfig;

class IdentityBridgeService
{
    public const CONFIG_MODULE = 'Weline_Multipass';
    public const CONFIG_DEVELOPER_APPLICATION_AUTO_APPROVE = 'identity_bridge/developer_applications/auto_approve';

    private const AUTHORIZATION_CODE_TTL = 600;
    private const ACCESS_TOKEN_TTL = 3600;
    private const REFRESH_TOKEN_TTL = 2592000;

    private const DEFAULT_SCOPES = [
        'profile.basic',
        'profile.email',
        'account.bind',
    ];

    private const SCOPE_META = [
        'profile.basic' => ['label' => '基础资料', 'description' => '读取用户 ID、昵称和头像', 'risk' => 'low'],
        'profile.email' => ['label' => '邮箱地址', 'description' => '读取账号邮箱用于登录识别', 'risk' => 'medium'],
        'account.bind' => ['label' => '账号绑定', 'description' => '创建或更新该应用的外部账号绑定', 'risk' => 'medium'],
        'appstore.account' => ['label' => '应用商城账号', 'description' => '创建或关联应用商城账号', 'risk' => 'medium'],
        'community.account' => ['label' => '社区账号', 'description' => '创建或关联论坛/社区账号', 'risk' => 'medium'],
    ];

    protected function newTrustedAppModel(): TrustedApp
    {
        return ObjectManager::getInstance(TrustedApp::class, [], false);
    }

    protected function newBindingModel(): AccountBinding
    {
        return ObjectManager::getInstance(AccountBinding::class, [], false);
    }

    protected function newCodeModel(): AuthorizationCode
    {
        return ObjectManager::getInstance(AuthorizationCode::class, [], false);
    }

    protected function newTokenModel(): IdentityToken
    {
        return ObjectManager::getInstance(IdentityToken::class, [], false);
    }

    public function createTrustedApp(
        string $name,
        string $redirectUri,
        string $trustedDomain = '',
        string $appType = 'app',
        array $allowedScopes = [],
        string $status = TrustedApp::STATUS_ACTIVE,
        int $applicantCustomerId = 0,
        string $applicationStatus = TrustedApp::APPLICATION_APPROVED
    ): array {
        $name = trim($name);
        $redirectUri = trim($redirectUri);
        if ($name === '') {
            throw new \InvalidArgumentException((string) __('应用名称不能为空'));
        }
        if ($redirectUri === '' || filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('回调地址格式不正确'));
        }

        $trustedDomain = $this->normalizeTrustedDomain($trustedDomain !== '' ? $trustedDomain : $redirectUri);
        if ($trustedDomain === '') {
            throw new \InvalidArgumentException((string) __('可信域名不能为空'));
        }

        $app = $this->newTrustedAppModel();
        $credentials = $app->generateClientCredentials();
        $app->setName($name)
            ->setRedirectUri($redirectUri)
            ->setTrustedDomain($trustedDomain)
            ->setAppType($appType)
            ->setAllowedScopes($allowedScopes ?: self::DEFAULT_SCOPES)
            ->setApplicantCustomerId($applicantCustomerId)
            ->setApplicationStatus($applicationStatus)
            ->setStatus($status)
            ->setClientId($credentials['client_id'])
            ->setClientSecret($credentials['client_secret'])
            ->setData('raw_client_secret', $credentials['client_secret'])
            ->save();

        return [$app, $credentials['client_secret']];
    }

    public function updateTrustedApp(
        int $appId,
        string $name,
        string $redirectUri,
        string $trustedDomain,
        string $appType,
        array $allowedScopes,
        string $status,
        string $applicationStatus = TrustedApp::APPLICATION_APPROVED
    ): TrustedApp {
        $app = $this->loadApp($appId);
        if (!$app) {
            throw new \InvalidArgumentException((string) __('应用不存在'));
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException((string) __('应用名称不能为空'));
        }
        if (trim($redirectUri) === '' || filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('回调地址格式不正确'));
        }

        $trustedDomain = $this->normalizeTrustedDomain($trustedDomain !== '' ? $trustedDomain : $redirectUri);
        if ($trustedDomain === '') {
            throw new \InvalidArgumentException((string) __('可信域名不能为空'));
        }
        if ($applicationStatus === TrustedApp::APPLICATION_REJECTED) {
            $status = TrustedApp::STATUS_DISABLED;
        } elseif ($status === TrustedApp::STATUS_ACTIVE && $applicationStatus === TrustedApp::APPLICATION_PENDING) {
            $applicationStatus = TrustedApp::APPLICATION_APPROVED;
        }

        $app->setName($name)
            ->setRedirectUri($redirectUri)
            ->setTrustedDomain($trustedDomain)
            ->setAppType($appType)
            ->setAllowedScopes($allowedScopes ?: self::DEFAULT_SCOPES)
            ->setApplicationStatus($applicationStatus)
            ->setStatus($status)
            ->save();

        return $app;
    }

    public function createDeveloperApplication(
        Customer $customer,
        string $name,
        string $redirectUri,
        string $trustedDomain = '',
        string $appType = 'custom',
        array $allowedScopes = []
    ): array {
        $customerId = (int) $customer->getId();
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('用户未登录'));
        }

        $redirectUri = trim($redirectUri);
        if ($redirectUri === '' || filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string) __('回调地址格式不正确'));
        }
        if (strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException((string) __('回调地址必须使用 HTTPS'));
        }

        $this->assertNoDuplicateDeveloperApplication($customerId, $redirectUri);

        $autoApprove = $this->isDeveloperApplicationAutoApproveEnabled();

        return $this->createTrustedApp(
            $name,
            $redirectUri,
            $trustedDomain,
            $appType,
            $allowedScopes ?: self::DEFAULT_SCOPES,
            $autoApprove ? TrustedApp::STATUS_ACTIVE : TrustedApp::STATUS_DISABLED,
            $customerId,
            $autoApprove ? TrustedApp::APPLICATION_APPROVED : TrustedApp::APPLICATION_PENDING
        );
    }

    public function isDeveloperApplicationAutoApproveEnabled(): bool
    {
        try {
            /** @var SystemConfig $systemConfig */
            $systemConfig = ObjectManager::getInstance(SystemConfig::class);
            $value = $systemConfig->getConfig(
                self::CONFIG_DEVELOPER_APPLICATION_AUTO_APPROVE,
                self::CONFIG_MODULE,
                SystemConfig::area_BACKEND,
                '0'
            );
        } catch (\Throwable) {
            return false;
        }

        return $this->normalizeConfigFlag($value);
    }

    public function rotateClientSecret(int $appId): array
    {
        $app = $this->loadApp($appId);
        if (!$app) {
            throw new \InvalidArgumentException((string) __('应用不存在'));
        }

        $credentials = $app->generateClientCredentials();
        $app->setClientSecret($credentials['client_secret'])->save();

        return [$app, $credentials['client_secret']];
    }

    public function loadApp(int $appId): ?TrustedApp
    {
        $app = $this->newTrustedAppModel()->load($appId);
        return $app->getId() ? $app : null;
    }

    public function loadActiveAppByClientId(string $clientId): ?TrustedApp
    {
        $app = $this->newTrustedAppModel()
            ->where(TrustedApp::schema_fields_CLIENT_ID, trim($clientId))
            ->find()
            ->fetch();

        return $app->getId() && $app->isActive() ? $app : null;
    }

    public function validateClient(string $clientId, string $clientSecret): ?TrustedApp
    {
        $app = $this->loadActiveAppByClientId($clientId);
        if (!$app || !$app->verifyClientSecret($clientSecret)) {
            return null;
        }

        return $app;
    }

    public function isRedirectUriAllowed(TrustedApp $app, string $redirectUri): bool
    {
        return $this->redirectUriMatches($app, trim($redirectUri));
    }

    public function resolveAuthorizationRequest(string $clientId, string $redirectUri = '', array $requestedScopes = []): array
    {
        $app = $this->loadActiveAppByClientId($clientId);
        if (!$app) {
            throw new \InvalidArgumentException((string) __('应用不存在或已禁用'));
        }

        $redirectUri = trim($redirectUri) !== '' ? trim($redirectUri) : $app->getRedirectUri();
        if (!$this->redirectUriMatches($app, $redirectUri)) {
            throw new \InvalidArgumentException((string) __('回调地址不匹配'));
        }

        $scopes = $this->normalizeRequestedScopes($app, $requestedScopes);

        return [
            'app' => $app,
            'redirect_uri' => $redirectUri,
            'requested_scopes' => $scopes,
            'requested_scope_details' => $this->describeScopes($scopes),
            'allowed_scopes' => $app->getAllowedScopes(),
            'allowed_scope_details' => $this->describeScopes($app->getAllowedScopes()),
        ];
    }

    public function authorizeCustomer(
        string $clientId,
        string $redirectUri,
        Customer $customer,
        array $requestedScopes = [],
        string $state = ''
    ): AuthorizationCode {
        $app = $this->loadActiveAppByClientId($clientId);
        if (!$app) {
            throw new \InvalidArgumentException((string) __('应用不存在或已禁用'));
        }
        $redirectUri = trim($redirectUri) !== '' ? trim($redirectUri) : $app->getRedirectUri();
        if (!$this->redirectUriMatches($app, $redirectUri)) {
            throw new \InvalidArgumentException((string) __('回调地址不匹配'));
        }
        if (!$customer->getId()) {
            throw new \InvalidArgumentException((string) __('用户未登录'));
        }

        $scopes = $this->normalizeRequestedScopes($app, $requestedScopes);
        $binding = $this->findOrCreateBinding($app, $customer);
        $binding->setLastAuthorizedAt(time())
            ->setStatus(AccountBinding::STATUS_ACTIVE)
            ->save();

        $code = $this->newCodeModel();
        $code->setAppId($app->getId())
            ->setBindingId($binding->getId())
            ->setLocalCustomerId((int) $customer->getId())
            ->setCode('mpc_' . bin2hex(random_bytes(32)))
            ->setRedirectUri($redirectUri)
            ->setScopes($scopes)
            ->setState($state)
            ->setExpiresAt(time() + self::AUTHORIZATION_CODE_TTL)
            ->setConsumedAt(0)
            ->save();

        return $code;
    }

    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        $app = $this->validateClient($clientId, $clientSecret);
        if (!$app) {
            return null;
        }

        $authorizationCode = $this->newCodeModel()
            ->where(AuthorizationCode::schema_fields_CODE, trim($code))
            ->find()
            ->fetch();
        if (!$authorizationCode->getId()
            || $authorizationCode->getAppId() !== $app->getId()
            || $authorizationCode->isConsumed()
            || $authorizationCode->isExpired()
        ) {
            return null;
        }

        $redirectUri = trim($redirectUri);
        if ($redirectUri !== '' && $authorizationCode->getRedirectUri() !== $redirectUri) {
            return null;
        }

        $binding = $this->loadBinding($authorizationCode->getBindingId());
        if (!$binding || !$binding->isActive() || $binding->getAppId() !== $app->getId()) {
            return null;
        }

        $authorizationCode->setConsumedAt(time())->save();
        return $this->issueTokenPair($app, $binding, $authorizationCode->getScopes());
    }

    public function refresh(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        $app = $this->validateClient($clientId, $clientSecret);
        if (!$app) {
            return null;
        }

        $token = $this->newTokenModel()
            ->where(IdentityToken::schema_fields_TOKEN, trim($refreshToken))
            ->where(IdentityToken::schema_fields_TYPE, IdentityToken::TYPE_REFRESH_TOKEN)
            ->find()
            ->fetch();
        if (!$token->getId() || $token->isExpired() || $token->isRevoked() || $token->getAppId() !== $app->getId()) {
            return null;
        }

        $binding = $this->loadBinding($token->getBindingId());
        if (!$binding || !$binding->isActive() || $binding->getAppId() !== $app->getId()) {
            return null;
        }

        $token->setRevokedAt(time())->save();
        return $this->issueTokenPair($app, $binding, $token->getScopes());
    }

    public function revoke(string $token): bool
    {
        $tokenRecord = $this->newTokenModel()
            ->where(IdentityToken::schema_fields_TOKEN, trim($token))
            ->find()
            ->fetch();
        if (!$tokenRecord->getId()) {
            return false;
        }

        $tokenRecord->setRevokedAt(time())->save();
        return true;
    }

    public function resolveAccessToken(string $token): ?array
    {
        $tokenRecord = $this->newTokenModel()
            ->where(IdentityToken::schema_fields_TOKEN, trim($token))
            ->where(IdentityToken::schema_fields_TYPE, IdentityToken::TYPE_ACCESS_TOKEN)
            ->find()
            ->fetch();
        if (!$tokenRecord->getId() || $tokenRecord->isExpired() || $tokenRecord->isRevoked()) {
            return null;
        }

        $app = $this->loadApp($tokenRecord->getAppId());
        $binding = $this->loadBinding($tokenRecord->getBindingId());
        if (!$app || !$app->isActive() || !$binding || !$binding->isActive()) {
            return null;
        }

        return [
            'app' => $app,
            'binding' => $binding,
            'token' => $tokenRecord,
            'scopes' => $tokenRecord->getScopes(),
        ];
    }

    public function getUserInfo(string $accessToken): ?array
    {
        $context = $this->resolveAccessToken($accessToken);
        if (!$context) {
            return null;
        }

        /** @var AccountBinding $binding */
        $binding = $context['binding'];
        /** @var IdentityToken $token */
        $token = $context['token'];
        /** @var TrustedApp $app */
        $app = $context['app'];
        $scopes = (array) $context['scopes'];

        $customer = ObjectManager::getInstance(Customer::class, [], false)->load($binding->getLocalCustomerId());
        if (!$customer->getId()) {
            return null;
        }

        $payload = [
            'sub' => 'customer:' . $customer->getId(),
            'customer_id' => (int) $customer->getId(),
            'username' => (string) ($customer->getUsername() ?? ''),
            'avatar' => (string) ($customer->getAvatar() ?? ''),
            'app' => [
                'app_id' => $app->getId(),
                'client_id' => $app->getClientId(),
                'name' => $app->getName(),
            ],
            'binding' => [
                'binding_id' => $binding->getId(),
                'external_subject_id' => $binding->getExternalSubjectId(),
            ],
            'token' => [
                'expires_at' => $token->getExpiresAt(),
                'scopes' => $scopes,
            ],
        ];

        if (in_array('profile.email', $scopes, true)) {
            $payload['email'] = $customer->getEmail();
        }

        return $payload;
    }

    public function bindExternalAccount(string $accessToken, string $externalSubjectId, string $displayName = '', array $metadata = []): ?array
    {
        $context = $this->resolveAccessToken($accessToken);
        if (!$context) {
            return null;
        }
        $scopes = (array) $context['scopes'];
        if (empty(array_intersect($scopes, ['account.bind', 'appstore.account', 'community.account']))) {
            throw new \RuntimeException((string) __('当前授权不允许绑定外部账号'));
        }

        $externalSubjectId = trim($externalSubjectId);
        if ($externalSubjectId === '') {
            throw new \InvalidArgumentException((string) __('外部账号标识不能为空'));
        }

        /** @var AccountBinding $binding */
        $binding = $context['binding'];
        $binding->setExternalSubjectId($externalSubjectId)
            ->setExternalDisplayName($displayName)
            ->setMetadata($metadata)
            ->setStatus(AccountBinding::STATUS_ACTIVE)
            ->save();

        return [
            'binding_id' => $binding->getId(),
            'local_customer_id' => $binding->getLocalCustomerId(),
            'external_subject_id' => $binding->getExternalSubjectId(),
            'status' => $binding->getStatus(),
        ];
    }

    public function listBindingsForCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $items = $this->newBindingModel()
            ->where(AccountBinding::schema_fields_LOCAL_CUSTOMER_ID, $customerId)
            ->where(AccountBinding::schema_fields_STATUS, AccountBinding::STATUS_ACTIVE)
            ->order(AccountBinding::schema_fields_ID, 'DESC')
            ->pagination(1, 100)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        $bindings = [];
        foreach ((array) $items as $binding) {
            if (!$binding instanceof AccountBinding || !$binding->getId()) {
                continue;
            }

            $app = $this->loadApp($binding->getAppId());
            if (!$app || !$app->isActive()) {
                continue;
            }

            $tokens = $this->listActiveTokensForBinding($binding->getId());
            $bindings[] = [
                'binding' => $binding,
                'app' => $app,
                'scopes' => $this->resolveBindingScopes($binding, $tokens, $app),
                'scope_details' => $this->describeScopes($this->resolveBindingScopes($binding, $tokens, $app)),
                'tokens' => $tokens,
            ];
        }

        return $bindings;
    }

    public function revokeCustomerBinding(int $bindingId, int $customerId): bool
    {
        if ($bindingId <= 0 || $customerId <= 0) {
            return false;
        }

        $binding = $this->loadBinding($bindingId);
        if (!$binding || $binding->getLocalCustomerId() !== $customerId || !$binding->isActive()) {
            return false;
        }

        $binding->setStatus(AccountBinding::STATUS_REVOKED)->save();
        $this->revokeBindingTokens($binding->getId(), [
            IdentityToken::TYPE_ACCESS_TOKEN,
            IdentityToken::TYPE_REFRESH_TOKEN,
        ]);

        return true;
    }

    public function describeScopes(array $scopes): array
    {
        $details = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $meta = self::SCOPE_META[$scope] ?? [
                'label' => $scope,
                'description' => '该应用请求的自定义授权范围',
                'risk' => 'medium',
            ];
            $details[] = [
                'scope' => $scope,
                'label' => (string) __($meta['label']),
                'description' => (string) __($meta['description']),
                'risk' => $meta['risk'],
                'risk_label' => $this->riskLabel((string) $meta['risk']),
            ];
        }

        return $details;
    }

    public function loadBinding(int $bindingId): ?AccountBinding
    {
        $binding = $this->newBindingModel()->load($bindingId);
        return $binding->getId() ? $binding : null;
    }

    public function listTrustedApps(int $limit = 50): array
    {
        $items = $this->newTrustedAppModel()
            ->order(TrustedApp::schema_fields_ID, 'DESC')
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        return is_array($items) ? $items : [];
    }

    public function listDeveloperApplicationsForCustomer(int $customerId, int $limit = 50): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $items = $this->newTrustedAppModel()
            ->where(TrustedApp::schema_fields_APPLICANT_CUSTOMER_ID, $customerId)
            ->where(TrustedApp::schema_fields_STATUS, [TrustedApp::STATUS_ACTIVE, TrustedApp::STATUS_DISABLED], 'in')
            ->order(TrustedApp::schema_fields_ID, 'DESC')
            ->pagination(1, $limit)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        return is_array($items) ? $items : [];
    }

    public function countBindingsForApp(int $appId): int
    {
        if ($appId <= 0) {
            return 0;
        }

        return (int) $this->newBindingModel()
            ->where(AccountBinding::schema_fields_APP_ID, $appId)
            ->where(AccountBinding::schema_fields_STATUS, AccountBinding::STATUS_ACTIVE)
            ->count();
    }

    private function findOrCreateBinding(TrustedApp $app, Customer $customer): AccountBinding
    {
        $binding = $this->newBindingModel()
            ->where(AccountBinding::schema_fields_APP_ID, $app->getId())
            ->where(AccountBinding::schema_fields_LOCAL_CUSTOMER_ID, (int) $customer->getId())
            ->find()
            ->fetch();

        if (!$binding->getId()) {
            $binding->clear()
                ->setAppId($app->getId())
                ->setLocalCustomerId((int) $customer->getId())
                ->setExternalSubjectId('')
                ->setExternalDisplayName('');
        }

        return $binding;
    }

    private function issueTokenPair(TrustedApp $app, AccountBinding $binding, array $scopes): array
    {
        $this->revokeBindingTokens($binding->getId(), [
            IdentityToken::TYPE_ACCESS_TOKEN,
            IdentityToken::TYPE_REFRESH_TOKEN,
        ]);

        $accessToken = $this->createToken($app, $binding, IdentityToken::TYPE_ACCESS_TOKEN, self::ACCESS_TOKEN_TTL, $scopes);
        $refreshToken = $this->createToken($app, $binding, IdentityToken::TYPE_REFRESH_TOKEN, self::REFRESH_TOKEN_TTL, $scopes);

        return [
            'access_token' => $accessToken->getToken(),
            'refresh_token' => $refreshToken->getToken(),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'expire_time' => $accessToken->getExpiresAt(),
            'scopes' => $scopes,
            'app' => [
                'app_id' => $app->getId(),
                'client_id' => $app->getClientId(),
                'name' => $app->getName(),
                'type' => $app->getAppType(),
            ],
            'binding' => [
                'binding_id' => $binding->getId(),
                'local_customer_id' => $binding->getLocalCustomerId(),
                'external_subject_id' => $binding->getExternalSubjectId(),
            ],
        ];
    }

    private function createToken(TrustedApp $app, AccountBinding $binding, string $type, int $ttl, array $scopes): IdentityToken
    {
        $token = $this->newTokenModel();
        $token->setAppId($app->getId())
            ->setBindingId($binding->getId())
            ->setLocalCustomerId($binding->getLocalCustomerId())
            ->setToken('mpt_' . bin2hex(random_bytes(32)))
            ->setType($type)
            ->setScopes($scopes)
            ->setExpiresAt(time() + $ttl)
            ->setRevokedAt(0)
            ->save();

        return $token;
    }

    private function revokeBindingTokens(int $bindingId, array $types = []): void
    {
        $query = $this->newTokenModel()
            ->where(IdentityToken::schema_fields_BINDING_ID, $bindingId)
            ->where(IdentityToken::schema_fields_REVOKED_AT, 0);
        if (!empty($types)) {
            $query->where(IdentityToken::schema_fields_TYPE, $types, 'in');
        }

        $rows = $query->select()->fetchArray();
        foreach ($rows as $row) {
            $token = $this->newTokenModel()->load((int) ($row[IdentityToken::schema_fields_ID] ?? 0));
            if ($token->getId()) {
                $token->setRevokedAt(time())->save();
            }
        }
    }

    private function listActiveTokensForBinding(int $bindingId): array
    {
        if ($bindingId <= 0) {
            return [];
        }

        $rows = $this->newTokenModel()
            ->where(IdentityToken::schema_fields_BINDING_ID, $bindingId)
            ->where(IdentityToken::schema_fields_REVOKED_AT, 0)
            ->select()
            ->fetch()
            ->getItems() ?? [];

        $tokens = [];
        foreach ((array) $rows as $token) {
            if (!$token instanceof IdentityToken || !$token->getId() || $token->isExpired() || $token->isRevoked()) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function resolveBindingScopes(AccountBinding $binding, array $tokens, TrustedApp $app): array
    {
        foreach ($tokens as $token) {
            if ($token instanceof IdentityToken && $token->getType() === IdentityToken::TYPE_ACCESS_TOKEN) {
                return $token->getScopes();
            }
        }

        foreach ($tokens as $token) {
            if ($token instanceof IdentityToken) {
                return $token->getScopes();
            }
        }

        return $app->getAllowedScopes();
    }

    private function riskLabel(string $risk): string
    {
        return match ($risk) {
            'low' => (string) __('低风险'),
            'high' => (string) __('高风险'),
            default => (string) __('中风险'),
        };
    }

    private function normalizeRequestedScopes(TrustedApp $app, array $requestedScopes): array
    {
        $allowed = $app->getAllowedScopes();
        $requested = array_values(array_unique(array_filter(array_map(static fn($scope) => trim((string) $scope), $requestedScopes))));
        if (empty($requested)) {
            $requested = in_array('profile.basic', $allowed, true) ? ['profile.basic'] : [$allowed[0]];
        }

        $invalid = array_values(array_diff($requested, $allowed));
        if (!empty($invalid)) {
            throw new \InvalidArgumentException((string) __('以下授权范围不可用：%{1}', [implode(', ', $invalid)]));
        }

        if (!in_array('profile.basic', $requested, true) && in_array('profile.basic', $allowed, true)) {
            array_unshift($requested, 'profile.basic');
        }

        return array_values(array_unique($requested));
    }

    private function assertNoDuplicateDeveloperApplication(int $customerId, string $redirectUri): void
    {
        $redirectUri = trim($redirectUri);
        if ($redirectUri === '') {
            return;
        }

        $existing = $this->newTrustedAppModel()
            ->where(TrustedApp::schema_fields_APPLICANT_CUSTOMER_ID, $customerId)
            ->where(TrustedApp::schema_fields_REDIRECT_URI, $redirectUri)
            ->where(TrustedApp::schema_fields_STATUS, [TrustedApp::STATUS_ACTIVE, TrustedApp::STATUS_DISABLED], 'in')
            ->where(TrustedApp::schema_fields_APPLICATION_STATUS, [TrustedApp::APPLICATION_PENDING, TrustedApp::APPLICATION_APPROVED], 'in')
            ->find()
            ->fetch();
        if ($existing instanceof TrustedApp && $existing->getId()) {
            throw new \InvalidArgumentException((string) __('该回调地址已经提交过 Multipass 管理申请'));
        }
    }

    private function normalizeConfigFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private function redirectUriMatches(TrustedApp $app, string $redirectUri): bool
    {
        return $app->getRedirectUri() !== '' && hash_equals($app->getRedirectUri(), $redirectUri);
    }

    private function normalizeTrustedDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $host = (string) (parse_url($value, PHP_URL_HOST) ?: $value);
        $host = strtolower(trim($host));
        return preg_replace('/[^a-z0-9.-]/', '', $host) ?? '';
    }
}
