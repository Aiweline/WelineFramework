<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * 购物目录（GMC）站点专用邮箱：编排落在 Feed，开箱/改密经 Mail Query。
 *
 * local_part = feed-{站点code}@本站企业邮箱域名。
 */
final class FeedScopeMailboxService
{
    public const LOCAL_PREFIX = 'feed';

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getScopeFeedMailbox(array $params): array
    {
        $website = $this->resolveWebsite($params);
        if ($website === null) {
            return $this->payload(false, (string)__('请先选择站点范围。'), [
                'state' => 'need_scope',
            ]);
        }

        $domain = $this->resolveDomain($website);
        if (empty($domain['success'])) {
            return $this->payload(false, (string)($domain['message'] ?? __('本站企业邮箱未在本机可用。')), [
                'state' => (string)($domain['state'] ?? 'domain_unavailable'),
                'website_id' => (int)$website['website_id'],
            ]);
        }

        $localPart = $this->localPartForWebsite($website);
        $email = $localPart . '@' . (string)$domain['domain_name'];
        $accountId = $this->findAccountIdByEmail($email);

        return $this->payload(true, '', [
            'state' => $accountId > 0 ? 'ready' : 'missing',
            'website_id' => (int)$website['website_id'],
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
    public function ensureScopeFeedMailbox(array $params): array
    {
        $current = $this->getScopeFeedMailbox($params);
        if (empty($current['success']) && (string)($current['state'] ?? '') !== 'missing') {
            return $current;
        }
        if ((string)($current['state'] ?? '') === 'ready' && (int)($current['account_id'] ?? 0) > 0) {
            $current['message'] = (string)__('FE 专用邮箱已存在。');

            return $current;
        }

        $domainId = (int)($current['domain_id'] ?? 0);
        $localPart = (string)($current['local_part'] ?? '');
        if ($domainId <= 0 || $localPart === '') {
            return $this->payload(false, (string)__('无法解析本站邮局域名。'), [
                'state' => 'domain_unavailable',
            ]);
        }

        try {
            $created = \w_query('mail', 'ensureMailbox', [
                'domain_id' => $domainId,
                'local_part' => $localPart,
                'display_name' => (string)__('FE 专用邮箱'),
            ], 'backend');
        } catch (\Throwable $e) {
            return $this->payload(false, $e->getMessage(), [
                'state' => 'error',
                'code' => 422,
            ]);
        }
        if (!\is_array($created) || empty($created['success'])) {
            return $this->payload(false, (string)($created['message'] ?? __('开通 FE 专用邮箱失败')), [
                'state' => 'error',
                'code' => (int)($created['code'] ?? 422),
            ]);
        }

        $passwordOnce = (string)($created['smtp_password_once'] ?? '');

        return $this->payload(true, (string)($created['message'] ?? __('FE 专用邮箱已开通。')), [
            'state' => 'ready',
            'website_id' => (int)($current['website_id'] ?? 0),
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
    public function resetScopeFeedMailboxPassword(array $params): array
    {
        $current = $this->getScopeFeedMailbox($params);
        if ((string)($current['state'] ?? '') !== 'ready' || (int)($current['account_id'] ?? 0) <= 0) {
            return $this->payload(false, (string)__('请先开通 FE 专用邮箱。'), [
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

        try {
            $reset = \w_query('mail', 'resetMailboxPassword', [
                'account_id' => (int)$current['account_id'],
                'password' => $password,
            ], 'backend');
        } catch (\Throwable $e) {
            return $this->payload(false, $e->getMessage(), [
                'state' => 'ready',
                'account_id' => (int)$current['account_id'],
                'email' => (string)$current['email'],
            ]);
        }
        if (!\is_array($reset) || empty($reset['success'])) {
            return $this->payload(false, (string)($reset['message'] ?? __('重置密码失败')), [
                'state' => 'ready',
                'account_id' => (int)$current['account_id'],
                'email' => (string)$current['email'],
                'code' => (int)($reset['code'] ?? 422),
            ]);
        }

        return $this->payload(true, (string)__('FE 邮箱密码已更新，请立即复制保存。'), [
            'state' => 'ready',
            'website_id' => (int)($current['website_id'] ?? 0),
            'domain_id' => (int)($current['domain_id'] ?? 0),
            'domain_name' => (string)($current['domain_name'] ?? ''),
            'local_part' => (string)($current['local_part'] ?? ''),
            'email' => (string)$current['email'],
            'account_id' => (int)$current['account_id'],
            'password_once' => $password,
            'has_password_once' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function resolveWebsite(array $params): ?array
    {
        $websiteId = (int)($params['website_id'] ?? $params['scope_id'] ?? -1);
        if ($websiteId < 0) {
            return null;
        }

        try {
            $rows = \w_query('websites', 'getWebsiteList', [], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }
        if (\is_array($rows)) {
            foreach ($rows as $row) {
                if (!\is_array($row) || (int)($row['website_id'] ?? -1) !== $websiteId) {
                    continue;
                }

                return [
                    'website_id' => $websiteId,
                    'code' => (string)($row['code'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'url' => (string)($row['url'] ?? ''),
                ];
            }
        }

        return [
            'website_id' => $websiteId,
            'code' => '',
            'name' => '',
            'url' => '',
        ];
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    private function resolveDomain(array $website): array
    {
        $host = $this->hostFromUrl((string)($website['url'] ?? ''));
        try {
            $domain = \w_query('mail', 'resolveActiveMailDomain', [
                'host' => $host,
            ], 'backend');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'state' => 'mail_unavailable',
            ];
        }

        return \is_array($domain) ? $domain : [
            'success' => false,
            'message' => (string)__('本站企业邮箱未在本机可用。'),
        ];
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

    /**
     * @param array<string, mixed> $website
     */
    private function localPartForWebsite(array $website): string
    {
        $code = \strtolower(\trim((string)($website['code'] ?? '')));
        $code = \preg_replace('/[^a-z0-9]+/', '-', $code) ?? '';
        $code = \trim($code, '-');
        if ($code === '') {
            $code = 'w' . (int)($website['website_id'] ?? 0);
        }
        $local = self::LOCAL_PREFIX . '-' . $code;
        if (\strlen($local) > 64) {
            $local = \substr($local, 0, 64);
            $local = \rtrim($local, '-.');
        }
        if (!\preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $local)) {
            $local = self::LOCAL_PREFIX . '-w' . (int)($website['website_id'] ?? 0);
        }

        return $local;
    }

    private function findAccountIdByEmail(string $email): int
    {
        try {
            $found = \w_query('mail', 'resolveLocalMailboxByEmail', [
                'email' => $email,
            ], 'backend');
        } catch (\Throwable) {
            return 0;
        }
        if (!\is_array($found) || empty($found['local'])) {
            return 0;
        }

        return (int)($found['mailbox']['account_id'] ?? 0);
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
}
