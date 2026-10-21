<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

/**
 * 后台开通/删除邮局域名（弹层 BinQuery / Controller 共用）。
 * 删除的是 Mail 邮局域配置卡片，不是 Websites 域名池条目。
 */
final class MailDomainAdminService
{
    /**
     * @param callable(string):bool|null $isWebsiteDomainCandidate
     * @return array{success:bool,message:mixed,code?:int,domain_id?:int}
     */
    public function createDomain(
        string $domain,
        string $hostname = '',
        string $engine = 'stalwart',
        int $quotaMb = 1024,
        ?callable $isWebsiteDomainCandidate = null
    ): array {
        $domain = $this->normalizeMailboxDomainName($domain);
        $hostname = strtolower(trim($hostname));
        $engine = strtolower(trim($engine));
        $quotaMb = max(128, $quotaMb);

        if ($domain === '') {
            return $this->fail(__('请选择或输入邮箱域名'), 400);
        }
        if ($hostname === '') {
            $hostname = 'mail.' . $domain;
        }
        if (!in_array($engine, ['stalwart', 'fake'], true)) {
            return $this->fail(__('邮件引擎参数无效'), 400);
        }
        if ($engine === 'fake' && !$this->isFakeTestDomain($domain)) {
            return $this->fail(__('Fake 测试引擎只允许 .invalid 或 .test 域名'), 400);
        }
        if ($engine !== 'fake') {
            $ok = $isWebsiteDomainCandidate !== null
                ? (bool)$isWebsiteDomainCandidate($domain)
                    || (bool)$isWebsiteDomainCandidate('www.' . $domain)
                : $this->defaultIsWebsiteDomainCandidate($domain);
            if (!$ok) {
                return $this->fail(__('真实邮箱域名必须先从 Websites 域名候选中选择'), 400);
            }
        }

        /** @var MailDomain $model */
        $model = ObjectManager::getInstance(MailDomain::class);
        $existing = $model->clear()->where(MailDomain::schema_fields_DOMAIN_NAME, $domain)->find()->fetch();
        if ($existing->getId()) {
            return $this->fail(__('邮箱域名已存在'), 409);
        }

        $now = date('Y-m-d H:i:s');
        $model->clear()
            ->setData(MailDomain::schema_fields_DOMAIN_NAME, $domain)
            ->setData(MailDomain::schema_fields_HOSTNAME, $hostname)
            ->setData(MailDomain::schema_fields_ENGINE, $engine)
            ->setData(MailDomain::schema_fields_STATUS, 'pending')
            ->setData(MailDomain::schema_fields_DEFAULT_QUOTA_MB, $quotaMb)
            ->setData(MailDomain::schema_fields_CREATED_AT, $now)
            ->setData(MailDomain::schema_fields_UPDATED_AT, $now)
            ->save();

        return [
            'success' => true,
            'message' => __('邮箱域名已创建，可在域名列表中开启或继续创建邮箱账号'),
            'domain_id' => (int)$model->getId(),
        ];
    }

    /**
     * 删除邮箱域名配置卡片及其下账号；不删除 Websites 域名池。
     *
     * @return array{success:bool,message:mixed,code?:int,deleted_accounts?:int}
     */
    public function deleteDomain(int $domainId): array
    {
        if ($domainId <= 0) {
            return $this->fail(__('邮箱域名 ID 无效'), 400);
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return $this->fail(__('邮箱域名不存在'), 404);
        }

        $hostname = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_HOSTNAME)));

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        $isFake = ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain);

        /** @var list<MailAccount> $accounts */
        $accounts = ObjectManager::getInstance(MailAccount::class)
            ->clear()
            ->where(MailAccount::schema_fields_DOMAIN_ID, $domainId)
            ->select()
            ->fetch()
            ->getItems();

        $deletedAccounts = 0;
        try {
            foreach ($accounts as $account) {
                if (!$account instanceof MailAccount || !$account->getId()) {
                    continue;
                }
                $email = strtolower(trim((string)$account->getData(MailAccount::schema_fields_EMAIL)));
                if (!$isFake && $email !== '') {
                    try {
                        ObjectManager::getInstance(StalwartManagementAdapter::class)->deleteAccount($email);
                    } catch (\Throwable) {
                        // 引擎侧可能已无此账号；仍删除本地行以便拆掉邮局域卡片
                    }
                }
                $account->delete();
                $deletedAccounts++;
            }
            $domain->delete();
            if (str_starts_with($hostname, 'mail.')) {
                try {
                    ObjectManager::getInstance(\Weline\Mail\Service\MailWebVhostService::class)->removeForHostname($hostname);
                } catch (\Throwable $vhostEx) {
                    w_log_warning('[Mail] ' . __('删除邮箱域名后移除邮件 Web 代理失败：%{1}', [$vhostEx->getMessage()]), ['hostname' => $hostname], 'mail');
                }
            }
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->fail($exception->getMessage());
        } catch (\Throwable) {
            return $this->fail(__('邮箱域名删除失败，请检查 Stalwart 管理连接'), 500);
        }

        return [
            'success' => true,
            'message' => __(
                '已移除邮箱域名配置 %{1}（账号 %{2} 个）。网站域名本身未删除。',
                [$domainName, $deletedAccounts]
            ),
            'deleted_accounts' => $deletedAccounts,
        ];
    }

    /**
     * 邮局域存 apex：剥离前导 www.，避免 mail 主机无法挂在 www 子域下。
     */
    public function normalizeMailboxDomainName(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }

    private function defaultIsWebsiteDomainCandidate(string $domain): bool
    {
        $want = [
            $domain => true,
            'www.' . $domain => true,
        ];
        try {
            $rows = w_query('websites', 'getDomainPoolList', ['limit' => 2000]);
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = $this->normalizeMailboxDomainName(
                    (string)($row['domain'] ?? $row['domain_name'] ?? '')
                );
                $raw = strtolower(trim((string)($row['domain'] ?? $row['domain_name'] ?? '')));
                if (isset($want[$name]) || isset($want[$raw])) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /** @return array{success:bool,message:mixed,code:int} */
    private function fail(mixed $message, int $code = 422): array
    {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }
}
