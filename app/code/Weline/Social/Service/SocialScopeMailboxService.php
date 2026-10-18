<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 网站范围社媒注册专用邮箱：编排落在 Social，开箱/改密落在 Mail。
 */
class SocialScopeMailboxService
{
    public const LOCAL_PREFIX = 'social';

    public function __construct(
        private readonly SocialWebsiteAccountService $websiteAccountService,
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getScopeSocialMailbox(array $params): array
    {
        if (!$this->mailAvailable()) {
            return $this->payload(false, (string)__('未启用企业邮箱模块，无法开通社媒专用邮箱。'), [
                'state' => 'mail_unavailable',
            ]);
        }

        $scope = $this->resolveWebsiteScope($params);
        if ($scope === null) {
            return $this->payload(false, (string)__('请先选择站点范围。'), [
                'state' => 'need_scope',
            ]);
        }

        $domain = $this->resolveDomainForWebsite($scope);
        if (empty($domain['success'])) {
            return $this->payload(false, (string)($domain['message'] ?? __('本站企业邮箱未在本机可用。')), [
                'state' => 'domain_unavailable',
                'website_id' => (int)$scope['website_id'],
                'scope_key' => (string)$scope['scope_key'],
            ]);
        }

        $localPart = $this->localPartForWebsite($scope);
        $email = $localPart . '@' . (string)$domain['domain_name'];
        $accountId = $this->findAccountIdByEmail($email);

        if ($accountId <= 0) {
            return $this->payload(true, '', [
                'state' => 'missing',
                'website_id' => (int)$scope['website_id'],
                'scope_key' => (string)$scope['scope_key'],
                'domain_id' => (int)$domain['domain_id'],
                'domain_name' => (string)$domain['domain_name'],
                'local_part' => $localPart,
                'email' => $email,
                'account_id' => 0,
                'password_once' => '',
                'has_password_once' => false,
            ]);
        }

        return $this->payload(true, '', [
            'state' => 'ready',
            'website_id' => (int)$scope['website_id'],
            'scope_key' => (string)$scope['scope_key'],
            'domain_id' => (int)$domain['domain_id'],
            'domain_name' => (string)$domain['domain_name'],
            'local_part' => $localPart,
            'email' => $email,
            'account_id' => $accountId,
            'password_once' => '',
            'has_password_once' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function ensureScopeSocialMailbox(array $params): array
    {
        $current = $this->getScopeSocialMailbox($params);
        if (empty($current['success']) && (string)($current['state'] ?? '') !== 'missing') {
            return $current;
        }
        if ((string)($current['state'] ?? '') === 'ready' && (int)($current['account_id'] ?? 0) > 0) {
            $current['message'] = (string)__('社媒专用邮箱已存在。');

            return $current;
        }
        if (!$this->mailAvailable()) {
            return $this->payload(false, (string)__('未启用企业邮箱模块，无法开通社媒专用邮箱。'), [
                'state' => 'mail_unavailable',
            ]);
        }

        $domainId = (int)($current['domain_id'] ?? 0);
        $localPart = (string)($current['local_part'] ?? '');
        if ($domainId <= 0 || $localPart === '') {
            return $this->payload(false, (string)__('无法解析本站邮局域名。'), [
                'state' => 'domain_unavailable',
            ]);
        }

        $ensureClass = 'Weline\\Mail\\Service\\MailAccountEnsureService';
        $ensure = $this->om()->getInstance($ensureClass);
        $created = $ensure->ensureMailbox(
            $domainId,
            $localPart,
            (string)__('社媒注册专用邮箱')
        );
        if (empty($created['success'])) {
            return $this->payload(false, (string)($created['message'] ?? __('开通社媒专用邮箱失败')), [
                'state' => 'error',
                'code' => (int)($created['code'] ?? 422),
            ]);
        }

        $passwordOnce = (string)($created['smtp_password_once'] ?? '');

        return $this->payload(true, (string)($created['message'] ?? __('社媒专用邮箱已开通。')), [
            'state' => 'ready',
            'website_id' => (int)($current['website_id'] ?? 0),
            'scope_key' => (string)($current['scope_key'] ?? ''),
            'domain_id' => (int)($created['domain_id'] ?? $domainId),
            'domain_name' => (string)($created['domain_name'] ?? ''),
            'local_part' => $localPart,
            'email' => (string)($created['email'] ?? ''),
            'account_id' => (int)($created['account_id'] ?? 0),
            'password_once' => $passwordOnce,
            'has_password_once' => $passwordOnce !== '',
            'created' => !empty($created['created']),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resetScopeSocialMailboxPassword(array $params): array
    {
        $current = $this->getScopeSocialMailbox($params);
        if ((string)($current['state'] ?? '') !== 'ready' || (int)($current['account_id'] ?? 0) <= 0) {
            return $this->payload(false, (string)__('请先开通社媒专用邮箱。'), [
                'state' => (string)($current['state'] ?? 'missing'),
            ]);
        }

        $password = trim((string)($params['password'] ?? ''));
        if ($password === '') {
            $password = bin2hex(random_bytes(12));
        }
        if (\strlen($password) < 8) {
            return $this->payload(false, (string)__('密码至少 8 位。'), [
                'state' => 'ready',
                'account_id' => (int)$current['account_id'],
                'email' => (string)$current['email'],
            ]);
        }

        $mgmtClass = 'Weline\\Mail\\Service\\MailAccountManagementService';
        $mgmt = $this->om()->getInstance($mgmtClass);
        $reset = $mgmt->provisionOrResetPassword((int)$current['account_id'], $password);
        if (empty($reset['success'])) {
            return $this->payload(false, (string)($reset['message'] ?? __('重置密码失败')), [
                'state' => 'ready',
                'account_id' => (int)$current['account_id'],
                'email' => (string)$current['email'],
                'code' => (int)($reset['code'] ?? 422),
            ]);
        }

        return $this->payload(true, (string)__('社媒邮箱密码已更新，请立即复制保存。'), [
            'state' => 'ready',
            'website_id' => (int)($current['website_id'] ?? 0),
            'scope_key' => (string)($current['scope_key'] ?? ''),
            'domain_id' => (int)($current['domain_id'] ?? 0),
            'domain_name' => (string)($current['domain_name'] ?? ''),
            'local_part' => (string)($current['local_part'] ?? ''),
            'email' => (string)$current['email'],
            'account_id' => (int)$current['account_id'],
            'password_once' => $password,
            'has_password_once' => true,
        ]);
    }

    private function mailAvailable(): bool
    {
        return \class_exists('Weline\\Mail\\Service\\MailAccountEnsureService')
            && \class_exists('Weline\\Mail\\Model\\MailAccount')
            && \class_exists('Weline\\Mail\\Model\\MailDomain');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function resolveWebsiteScope(array $params): ?array
    {
        $websiteId = (int)($params['website_id'] ?? $params['scope_id'] ?? -1);
        if ($websiteId < 0 && isset($params['scope_key'])) {
            $parts = \explode(':', (string)$params['scope_key']);
            if (\count($parts) >= 2) {
                $websiteId = (int)$parts[1];
            }
        }
        if ($websiteId < 0) {
            return null;
        }

        foreach ($this->websiteAccountService->listWebsites() as $website) {
            if ((int)($website['website_id'] ?? -1) !== $websiteId) {
                continue;
            }
            $scopeKey = 'website:' . $websiteId . ':website_default:0';

            return [
                'website_id' => $websiteId,
                'scope_id' => $websiteId,
                'scope_type' => 'website',
                'scope_key' => $scopeKey,
                'code' => (string)($website['code'] ?? ''),
                'name' => (string)($website['name'] ?? ''),
                'url' => (string)($website['url'] ?? ''),
            ];
        }

        return [
            'website_id' => $websiteId,
            'scope_id' => $websiteId,
            'scope_type' => 'website',
            'scope_key' => 'website:' . $websiteId . ':website_default:0',
            'code' => '',
            'name' => '',
            'url' => '',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveDomainForWebsite(array $scope): array
    {
        $ensureClass = 'Weline\\Mail\\Service\\MailAccountEnsureService';
        $ensure = $this->om()->getInstance($ensureClass);
        $host = $this->hostFromUrl((string)($scope['url'] ?? ''));
        if ($host !== '') {
            $matchedId = $this->matchActiveDomainId($host);
            if ($matchedId > 0) {
                return $ensure->resolveActiveDomain($matchedId);
            }
        }

        return $ensure->resolveActiveDomain(0);
    }

    private function hostFromUrl(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        if (!\preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = \strtolower((string)\parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return '';
        }
        if (\str_starts_with($host, 'www.')) {
            $host = \substr($host, 4);
        }

        return $host;
    }

    private function matchActiveDomainId(string $host): int
    {
        $customerClass = 'Weline\\Mail\\Service\\MailCustomerAccountService';
        $domainClass = 'Weline\\Mail\\Model\\MailDomain';
        $customer = $this->om()->getInstance($customerClass);
        $bestId = 0;
        $bestLen = -1;
        foreach ($customer->getActiveDomains() as $domain) {
            if (!$domain instanceof $domainClass || !$domain->getId()) {
                continue;
            }
            $name = \strtolower(\trim((string)$domain->getData($domainClass::schema_fields_DOMAIN_NAME)));
            if ($name === '' || $customer->isFakeDomain($domain)) {
                continue;
            }
            if ($host === $name || \str_ends_with($host, '.' . $name)) {
                $len = \strlen($name);
                if ($len > $bestLen) {
                    $bestId = (int)$domain->getId();
                    $bestLen = $len;
                }
            }
        }

        return $bestId;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function localPartForWebsite(array $scope): string
    {
        $code = \strtolower(\trim((string)($scope['code'] ?? '')));
        $code = \preg_replace('/[^a-z0-9]+/', '-', $code) ?? '';
        $code = \trim($code, '-');
        if ($code === '') {
            $code = 'w' . (int)($scope['website_id'] ?? 0);
        }
        $local = self::LOCAL_PREFIX . '-' . $code;
        if (\strlen($local) > 64) {
            $local = \substr($local, 0, 64);
            $local = \rtrim($local, '-.');
        }
        if (!\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $local)) {
            $local = self::LOCAL_PREFIX . '-w' . (int)($scope['website_id'] ?? 0);
        }

        return $local;
    }

    private function findAccountIdByEmail(string $email): int
    {
        $accountClass = 'Weline\\Mail\\Model\\MailAccount';
        $model = $this->om()->getInstance($accountClass);
        $found = $model->clear()->where($accountClass::schema_fields_EMAIL, $email)->find()->fetch();
        if (!$found->getId()) {
            return 0;
        }
        if ((string)$found->getData($accountClass::schema_fields_STATUS) === 'disabled') {
            return 0;
        }

        return (int)$found->getId();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(bool $success, string $message, array $extra = []): array
    {
        return \array_merge([
            'success' => $success,
            'message' => $message,
            'state' => (string)($extra['state'] ?? ($success ? 'ready' : 'error')),
            'email' => '',
            'account_id' => 0,
            'password_once' => '',
            'has_password_once' => false,
        ], $extra, [
            'success' => $success,
            'message' => $message,
        ]);
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }
}
