<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailDomain;

/**
 * 后台开通邮局域名（弹层 BinQuery / Controller 共用）。
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
        $domain = strtolower(trim($domain));
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

    private function isFakeTestDomain(string $domain): bool
    {
        return str_ends_with($domain, '.invalid') || str_ends_with($domain, '.test');
    }

    private function defaultIsWebsiteDomainCandidate(string $domain): bool
    {
        try {
            $rows = w_query('websites', 'getDomainPoolList', ['limit' => 2000]);
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = strtolower(trim((string)($row['domain'] ?? $row['domain_name'] ?? '')));
                if ($name === $domain) {
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
