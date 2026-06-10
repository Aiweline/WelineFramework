<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Ai\Service\SecretStoreService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Interface\SocialPlatformConfigTesterInterface;
use Weline\Social\Model\SocialPlatformAccount;

class SocialAccountService
{
    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SecretStoreService $secretStore,
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?SocialPlatformIconService $iconService = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $rows = $this->newAccount()->reset()
            ->order(SocialPlatformAccount::schema_fields_ID, 'DESC')
            ->select()
            ->fetch();

        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items)) {
            return [];
        }

        $accounts = [];
        foreach ($items as $item) {
            if ($item instanceof SocialPlatformAccount) {
                $accounts[] = $this->enrichAccount($item->toSafeArray());
            } elseif (\is_array($item)) {
                unset($item[SocialPlatformAccount::schema_fields_CREDENTIALS_ENCRYPTED]);
                $accounts[] = $this->enrichAccount($item);
            }
        }

        return $accounts;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function saveCredentialAccount(array $params): array
    {
        $platformCode = \strtolower(\trim((string)($params['platform_code'] ?? $params['platform'] ?? '')));
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__('未知社媒平台：%{1}', [$platformCode]));
        }

        $definition = $provider->getDefinition();
        $authMode = \trim((string)($params['auth_mode'] ?? ($definition['auth_modes'][0] ?? 'credential')));
        $accountName = \trim((string)($params['account_name'] ?? $definition['title'] ?? $platformCode));
        if ($accountName === '') {
            throw new \InvalidArgumentException((string)__('账户名称不能为空。'));
        }
        $profileUrl = $this->normalizeProfileUrl($params['profile_url'] ?? '');
        $widgetEnabled = $this->toBool($params['widget_enabled'] ?? false);
        $publishRequested = $authMode !== 'profile_only' && $this->toBool($params['publish_enabled'] ?? true);
        if ($widgetEnabled && $profileUrl === '') {
            throw new \InvalidArgumentException((string)__('启用社媒账户部件时必须填写公开主页 URL。'));
        }

        $inputCredentials = \is_array($params['credentials'] ?? null) ? $this->normalizeCredentials($params['credentials']) : [];
        $hasCredentialInput = $inputCredentials !== [];
        $scopes = \is_array($params['scopes'] ?? null) ? \array_values(\array_map('strval', $params['scopes'])) : [];
        $tokenExpiresAt = \trim((string)($params['token_expires_at'] ?? ''));
        $sortOrder = (int)($params['sort_order'] ?? 1000);
        $now = \date('Y-m-d H:i:s');

        $accountId = (int)($params['account_id'] ?? 0);
        $account = $accountId > 0 ? $this->getAccount($accountId) : null;
        $account ??= $this->findAccount($platformCode, $accountName) ?? $this->newAccount();
        if (!$account->getId()) {
            $account->setData(SocialPlatformAccount::schema_fields_CREATED_AT, $now);
        }

        $credentials = $inputCredentials;
        if (!$hasCredentialInput && $account->getId()) {
            $credentials = $this->getCredentials($account);
        }

        $testResult = [
            'success' => true,
            'message' => (string)__('账户已保存为仅展示模式，未启用发布。'),
        ];
        if ($publishRequested) {
            $testResult = $provider instanceof SocialPlatformConfigTesterInterface
                ? $provider->testConfig($credentials, ['platform_code' => $platformCode, 'auth_mode' => $authMode])
                : ['success' => false, 'message' => (string)__('该平台未提供配置检测器。')];
        }

        $publishEnabled = $publishRequested && !empty($testResult['success']) ? 1 : 0;
        $status = ($widgetEnabled || $publishEnabled)
            ? SocialPlatformAccount::STATUS_ACTIVE
            : SocialPlatformAccount::STATUS_DISABLED;

        $account->setData(SocialPlatformAccount::schema_fields_PLATFORM_CODE, $platformCode)
            ->setData(SocialPlatformAccount::schema_fields_ACCOUNT_NAME, $accountName)
            ->setData(SocialPlatformAccount::schema_fields_AUTH_MODE, $authMode)
            ->setData(SocialPlatformAccount::schema_fields_CREDENTIALS_ENCRYPTED, $this->encryptCredentials($credentials))
            ->setData(SocialPlatformAccount::schema_fields_TOKEN_EXPIRES_AT, $tokenExpiresAt !== '' ? $tokenExpiresAt : null)
            ->setData(SocialPlatformAccount::schema_fields_PROFILE_URL, $profileUrl)
            ->setData(SocialPlatformAccount::schema_fields_WIDGET_ENABLED, $widgetEnabled ? 1 : 0)
            ->setData(SocialPlatformAccount::schema_fields_PUBLISH_ENABLED, $publishEnabled)
            ->setData(SocialPlatformAccount::schema_fields_SORT_ORDER, $sortOrder)
            ->setData(SocialPlatformAccount::schema_fields_STATUS, $status)
            ->setData(SocialPlatformAccount::schema_fields_TEST_STATUS, $publishRequested ? (!empty($testResult['success']) ? SocialPlatformAccount::TEST_STATUS_PASSED : SocialPlatformAccount::TEST_STATUS_FAILED) : SocialPlatformAccount::TEST_STATUS_UNTESTED)
            ->setData(SocialPlatformAccount::schema_fields_TEST_MESSAGE, (string)($testResult['message'] ?? ''))
            ->setData(SocialPlatformAccount::schema_fields_TESTED_AT, $publishRequested ? $now : null)
            ->setData(SocialPlatformAccount::schema_fields_REMOTE_ACCOUNT_ID, (string)($params['remote_account_id'] ?? ''))
            ->setData(SocialPlatformAccount::schema_fields_REMOTE_ACCOUNT_NAME, (string)($params['remote_account_name'] ?? $accountName))
            ->setData(SocialPlatformAccount::schema_fields_UPDATED_AT, $now)
            ->setScopes($scopes)
            ->save();

        return [
            'success' => !empty($testResult['success']),
            'message' => (string)($testResult['message'] ?? ''),
            'account' => $this->enrichAccount($account->toSafeArray()),
        ];
    }

    public function getAccount(int $accountId): ?SocialPlatformAccount
    {
        if ($accountId <= 0) {
            return null;
        }
        $account = $this->newAccount();
        $account->load($accountId);

        return $account->getId() ? $account : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountSafeArray(int $accountId): array
    {
        $account = $this->getAccount($accountId);
        return $account instanceof SocialPlatformAccount ? $this->enrichAccount($account->toSafeArray()) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCredentials(SocialPlatformAccount $account): array
    {
        $encrypted = (string)$account->getData(SocialPlatformAccount::schema_fields_CREDENTIALS_ENCRYPTED);
        if ($encrypted === '') {
            return [];
        }
        $decoded = $this->secretStore->decryptConfig($encrypted);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function testAccount(int $accountId): array
    {
        $account = $this->getAccount($accountId);
        if (!$account instanceof SocialPlatformAccount) {
            throw new \InvalidArgumentException((string)__('账户不存在。'));
        }

        $provider = $this->registry->getProvider((string)$account->getData(SocialPlatformAccount::schema_fields_PLATFORM_CODE));
        if (!$provider instanceof SocialPlatformConfigTesterInterface) {
            throw new \RuntimeException((string)__('平台未提供配置检测器。'));
        }

        $result = $provider->testConfig($this->getCredentials($account), ['account_id' => $accountId]);
        $now = \date('Y-m-d H:i:s');
        $account->setData(SocialPlatformAccount::schema_fields_TEST_STATUS, !empty($result['success']) ? SocialPlatformAccount::TEST_STATUS_PASSED : SocialPlatformAccount::TEST_STATUS_FAILED)
            ->setData(SocialPlatformAccount::schema_fields_TEST_MESSAGE, (string)($result['message'] ?? ''))
            ->setData(SocialPlatformAccount::schema_fields_TESTED_AT, $now)
            ->setData(SocialPlatformAccount::schema_fields_STATUS, !empty($result['success']) ? SocialPlatformAccount::STATUS_ACTIVE : SocialPlatformAccount::STATUS_DISABLED)
            ->setData(SocialPlatformAccount::schema_fields_PUBLISH_ENABLED, !empty($result['success']) ? 1 : 0)
            ->setData(SocialPlatformAccount::schema_fields_UPDATED_AT, $now)
            ->save();

        return [
            'success' => !empty($result['success']),
            'message' => (string)($result['message'] ?? ''),
            'account' => $this->enrichAccount($account->toSafeArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disableAccount(int $accountId): array
    {
        $account = $this->getAccount($accountId);
        if (!$account instanceof SocialPlatformAccount) {
            throw new \InvalidArgumentException((string)__('账户不存在。'));
        }

        $account->setData(SocialPlatformAccount::schema_fields_STATUS, SocialPlatformAccount::STATUS_DISABLED)
            ->setData(SocialPlatformAccount::schema_fields_WIDGET_ENABLED, 0)
            ->setData(SocialPlatformAccount::schema_fields_PUBLISH_ENABLED, 0)
            ->setData(SocialPlatformAccount::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'))
            ->save();

        return [
            'success' => true,
            'message' => (string)__('账户已禁用。'),
            'account' => $this->enrichAccount($account->toSafeArray()),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listWidgetAccounts(array $filters = []): array
    {
        $platformFilter = \array_values(\array_filter(\array_map(
            static fn(mixed $value): string => \strtolower(\trim((string)$value)),
            (array)($filters['platforms'] ?? [])
        )));
        $limit = (int)($filters['limit'] ?? 24);
        $limit = \max(1, \min(100, $limit));

        $rows = $this->newAccount()->reset()
            ->where(SocialPlatformAccount::schema_fields_STATUS, SocialPlatformAccount::STATUS_ACTIVE)
            ->where(SocialPlatformAccount::schema_fields_WIDGET_ENABLED, 1)
            ->where(SocialPlatformAccount::schema_fields_PROFILE_URL, '', '!=')
            ->order(SocialPlatformAccount::schema_fields_SORT_ORDER, 'ASC')
            ->order(SocialPlatformAccount::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $accounts = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $platformCode = \strtolower((string)($row[SocialPlatformAccount::schema_fields_PLATFORM_CODE] ?? ''));
            if ($platformFilter !== [] && !\in_array($platformCode, $platformFilter, true)) {
                continue;
            }
            try {
                $profileUrl = $this->normalizeProfileUrl($row[SocialPlatformAccount::schema_fields_PROFILE_URL] ?? '');
            } catch (\Throwable) {
                continue;
            }
            if ($profileUrl === '') {
                continue;
            }
            unset($row[SocialPlatformAccount::schema_fields_CREDENTIALS_ENCRYPTED]);
            $row[SocialPlatformAccount::schema_fields_PROFILE_URL] = $profileUrl;
            $accounts[] = $this->enrichAccount($row);
        }

        return $accounts;
    }

    private function encryptCredentials(array $credentials): string
    {
        return $this->secretStore->encryptConfig($credentials);
    }

    private function findAccount(string $platformCode, string $accountName): ?SocialPlatformAccount
    {
        $account = $this->newAccount();
        $account->reset()
            ->where(SocialPlatformAccount::schema_fields_PLATFORM_CODE, $platformCode)
            ->where(SocialPlatformAccount::schema_fields_ACCOUNT_NAME, $accountName)
            ->find()
            ->fetch();

        return $account->getId() ? $account : null;
    }

    private function newAccount(): SocialPlatformAccount
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialPlatformAccount::class);
    }

    private function normalizeProfileUrl(mixed $value): string
    {
        $url = \trim((string)$value);
        if ($url === '') {
            return '';
        }
        if (\filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException((string)__('公开主页 URL 格式不正确。'));
        }
        $scheme = \strtolower((string)\parse_url($url, PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException((string)__('公开主页 URL 只允许 http 或 https。'));
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    private function normalizeCredentials(array $credentials): array
    {
        $normalized = [];
        foreach ($credentials as $key => $value) {
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            if (\is_array($value)) {
                $normalized[$key] = $value;
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        $normalized = \strtolower(\trim((string)$value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function enrichAccount(array $account): array
    {
        unset($account[SocialPlatformAccount::schema_fields_CREDENTIALS_ENCRYPTED]);
        $platformCode = \strtolower((string)($account[SocialPlatformAccount::schema_fields_PLATFORM_CODE] ?? $account['platform_code'] ?? ''));
        $provider = $this->registry->getProvider($platformCode);
        $definition = $provider ? $provider->getDefinition() : ['code' => $platformCode, 'title' => $platformCode];
        $iconService = $this->iconService ?? ObjectManager::getInstance(SocialPlatformIconService::class);
        $definition = $iconService->enrichDefinition($definition);

        $account['account_id'] = (int)($account['account_id'] ?? $account[SocialPlatformAccount::schema_fields_ID] ?? 0);
        $account['platform_code'] = $platformCode;
        $account['platform_title'] = (string)($definition['title'] ?? $platformCode);
        $account['icon_svg'] = (string)($definition['icon_svg'] ?? '');
        $account['brand_color'] = (string)($definition['brand_color'] ?? '#475569');
        $account['profile_url'] = (string)($account['profile_url'] ?? $account[SocialPlatformAccount::schema_fields_PROFILE_URL] ?? '');
        $account['widget_enabled'] = (int)($account['widget_enabled'] ?? $account[SocialPlatformAccount::schema_fields_WIDGET_ENABLED] ?? 0);
        $account['publish_enabled'] = (int)($account['publish_enabled'] ?? $account[SocialPlatformAccount::schema_fields_PUBLISH_ENABLED] ?? 0);
        $account['sort_order'] = (int)($account['sort_order'] ?? $account[SocialPlatformAccount::schema_fields_SORT_ORDER] ?? 1000);

        return $account;
    }
}
