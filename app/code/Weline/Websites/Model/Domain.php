<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名模型
 *
 * 存储从域名商同步的域名数据，支持本地分页、搜索和批量管理
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 域名模型（根域名）
 *
 * 从域名商 API 同步的根域名主数据，用于本地管理
 * 
 * 注意：此模型只存储根域名（如 example.com），解析状态/HTTPS状态/建站就绪
 * 应该使用 DomainPool 模型（存储具体子域名如 www.example.com）
 * 
 * @see DomainPool 可建站的具体域名池
 */
#[Table(comment: '域名表 - 同步自域名商')]
#[Index(name: 'uk_account_domain', columns: ['account_id', 'domain'], type: 'UNIQUE')]
#[Index(name: 'idx_account_id', columns: ['account_id'])]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_expires_at', columns: ['expires_at'])]
#[Index(name: 'idx_resolve_status', columns: ['resolve_status'])]
#[Index(name: 'idx_https_status', columns: ['https_status'])]
#[Index(name: 'idx_site_ready', columns: ['site_ready'])]
class Domain extends Model
{
    public const schema_table = 'weline_websites_domain';
    public const schema_primary_key = 'domain_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '域名ID')]
    public const schema_fields_ID = 'domain_id';

    #[Col('int', 11, nullable: false, comment: '域名商账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';

    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';

    #[Col('varchar', 20, nullable: true, default: 'active', comment: '标准化状态')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 50, nullable: true, default: '', comment: '域名商原始状态')]
    public const schema_fields_REGISTRAR_STATUS = 'registrar_status';

    #[Col('date', nullable: true, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    #[Col('text', nullable: true, comment: 'DNS服务器JSON')]
    public const schema_fields_NAMESERVERS = 'nameservers';

    #[Col('text', nullable: true, comment: '域名商原始数据JSON')]
    public const schema_fields_EXTRA_DATA = 'extra_data';

    #[Col('datetime', nullable: true, comment: '最后同步时间')]
    public const schema_fields_SYNCED_AT = 'synced_at';

    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('varchar', 50, nullable: true, default: '', comment: 'CDN供应商代码')]
    public const schema_fields_CDN_PROVIDER = 'cdn_provider';

    #[Col('int', 11, nullable: true, default: 0, comment: 'CDN账户ID')]
    public const schema_fields_CDN_ACCOUNT_ID = 'cdn_account_id';

    #[Col('varchar', 50, nullable: true, default: '', comment: 'DNS服务商代码')]
    public const schema_fields_DNS_PROVIDER = 'dns_provider';

    #[Col('int', 11, nullable: true, default: 0, comment: 'DNS管理账户ID（第三方DNS服务商）')]
    public const schema_fields_DNS_ACCOUNT_ID = 'dns_account_id';

    #[Col('smallint', 1, nullable: true, default: 0, comment: 'DNS切换后待推送记录：1=定时任务将把本地记录推送到新账户')]
    public const schema_fields_DNS_MIGRATION_PENDING = 'dns_migration_pending';

    #[Col('smallint', 1, nullable: true, default: 0, comment: 'DNS/CDN服务切换待执行：1=生命周期完成后定时任务将自动切换DNS/CDN服务商')]
    public const schema_fields_DNS_SWITCH_PENDING = 'dns_switch_pending';

    #[Col('smallint', 1, nullable: true, default: 0, comment: 'DNS/CDN 延迟切换：1=购买时未立即切换（等注册完成），定时任务在生命周期完成后转为 dns_switch_pending 并执行')]
    public const schema_fields_DNS_SWITCH_DEFERRED = 'dns_switch_deferred';

    #[Col('varchar', 20, nullable: true, default: 'pending', comment: '解析状态')]
    public const schema_fields_RESOLVE_STATUS = 'resolve_status';

    #[Col('varchar', 45, nullable: true, default: '', comment: '解析到的IPv4')]
    public const schema_fields_RESOLVED_IP = 'resolved_ip';

    #[Col('varchar', 45, nullable: true, default: '', comment: '解析到的IPv6')]
    public const schema_fields_RESOLVED_IPV6 = 'resolved_ipv6';

    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否指向本服务器')]
    public const schema_fields_IS_LOCAL_SERVER = 'is_local_server';

    #[Col('datetime', nullable: true, comment: '解析检测时间')]
    public const schema_fields_RESOLVE_CHECKED_AT = 'resolve_checked_at';

    #[Col('text', nullable: true, comment: '解析错误信息')]
    public const schema_fields_RESOLVE_ERROR = 'resolve_error';

    #[Col('varchar', 20, nullable: true, default: 'none', comment: 'HTTPS证书状态')]
    public const schema_fields_HTTPS_STATUS = 'https_status';

    #[Col('date', nullable: true, comment: '证书过期时间')]
    public const schema_fields_HTTPS_EXPIRES_AT = 'https_expires_at';

    #[Col('text', nullable: true, comment: '证书申请错误')]
    public const schema_fields_HTTPS_ERROR = 'https_error';

    #[Col('datetime', nullable: true, comment: '证书申请时间')]
    public const schema_fields_HTTPS_REQUESTED_AT = 'https_requested_at';

    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否可建站')]
    public const schema_fields_SITE_READY = 'site_ready';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';

    // 解析状态常量
    public const RESOLVE_STATUS_PENDING = 'pending';
    public const RESOLVE_STATUS_RESOLVED = 'resolved';
    public const RESOLVE_STATUS_ERROR = 'error';

    // HTTPS 状态常量
    public const HTTPS_STATUS_NONE = 'none';
    public const HTTPS_STATUS_PENDING = 'pending';
    public const HTTPS_STATUS_VALID = 'valid';
    public const HTTPS_STATUS_EXPIRED = 'expired';
    public const HTTPS_STATUS_ERROR = 'error';

    /**
     * 保存前自动更新时间戳和建站状态
     * 确保 date 类型字段不为空字符串（PostgreSQL 不接受 ''，必须为 NULL）
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }

        $domain = $this->getData(self::schema_fields_DOMAIN);
        if ($domain) {
            $this->setData(self::schema_fields_DOMAIN, \strtolower(\trim($domain)));
        }

        // PostgreSQL date 类型不接受空字符串，统一转为 null
        foreach ([self::schema_fields_EXPIRES_AT, self::schema_fields_HTTPS_EXPIRES_AT] as $dateField) {
            $v = $this->getData($dateField);
            if ($v === '' || $v === null) {
                $this->setData($dateField, null);
            }
        }

        // 自动计算建站就绪状态
        $this->calculateSiteReady();
    }

    // =============== Getter/Setter 方法 ===============

    public function getDomainId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function getAccountId(): int
    {
        return (int) $this->getData(self::schema_fields_ACCOUNT_ID);
    }

    public function setAccountId(int $accountId): self
    {
        $this->setData(self::schema_fields_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
    }

    public function setDomain(string $domain): self
    {
        $this->setData(self::schema_fields_DOMAIN, \strtolower(\trim($domain)));
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }

    public function getRegistrarStatus(): string
    {
        return (string) $this->getData(self::schema_fields_REGISTRAR_STATUS);
    }

    public function setRegistrarStatus(string $status): self
    {
        $this->setData(self::schema_fields_REGISTRAR_STATUS, $status);
        return $this;
    }

    public function getExpiresAt(): string
    {
        return (string) $this->getData(self::schema_fields_EXPIRES_AT);
    }

    public function setExpiresAt(?string $date): self
    {
        $this->setData(
            self::schema_fields_EXPIRES_AT,
            ($date !== null && $date !== '') ? $date : null
        );
        return $this;
    }

    public function getNameservers(): array
    {
        $ns = $this->getData(self::schema_fields_NAMESERVERS);
        if (\is_string($ns) && $ns !== '') {
            $decoded = \json_decode($ns, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setNameservers(array $nameservers): self
    {
        $this->setData(self::schema_fields_NAMESERVERS, \json_encode($nameservers, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getExtraData(): array
    {
        $data = $this->getData(self::schema_fields_EXTRA_DATA);
        if (\is_string($data) && $data !== '') {
            $decoded = \json_decode($data, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setExtraData(array $data): self
    {
        $this->setData(self::schema_fields_EXTRA_DATA, \json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getSyncedAt(): string
    {
        return (string) $this->getData(self::schema_fields_SYNCED_AT);
    }

    public function setSyncedAt(string $datetime): self
    {
        $this->setData(self::schema_fields_SYNCED_AT, $datetime);
        return $this;
    }

    // =============== v1.5.0 新增 Getter/Setter ===============

    public function getCdnProvider(): string
    {
        return (string) $this->getData(self::schema_fields_CDN_PROVIDER);
    }

    public function setCdnProvider(string $provider): self
    {
        $this->setData(self::schema_fields_CDN_PROVIDER, $provider);
        return $this;
    }

    public function getCdnAccountId(): int
    {
        return (int) $this->getData(self::schema_fields_CDN_ACCOUNT_ID);
    }

    public function setCdnAccountId(int $accountId): self
    {
        $this->setData(self::schema_fields_CDN_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getDnsProvider(): string
    {
        return (string) $this->getData(self::schema_fields_DNS_PROVIDER);
    }

    public function setDnsProvider(string $provider): self
    {
        $this->setData(self::schema_fields_DNS_PROVIDER, $provider);
        return $this;
    }

    public function getDnsAccountId(): int
    {
        return (int) $this->getData(self::schema_fields_DNS_ACCOUNT_ID);
    }

    public function setDnsAccountId(int $accountId): self
    {
        $this->setData(self::schema_fields_DNS_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getDnsMigrationPending(): int
    {
        return (int) ($this->getData(self::schema_fields_DNS_MIGRATION_PENDING) ?? 0);
    }

    public function setDnsMigrationPending(int $value): self
    {
        $this->setData(self::schema_fields_DNS_MIGRATION_PENDING, $value);
        return $this;
    }

    public function getDnsSwitchPending(): int
    {
        return (int) ($this->getData(self::schema_fields_DNS_SWITCH_PENDING) ?? 0);
    }

    public function setDnsSwitchPending(int $value): self
    {
        $this->setData(self::schema_fields_DNS_SWITCH_PENDING, $value);
        return $this;
    }

    public function getDnsSwitchDeferred(): int
    {
        return (int) ($this->getData(self::schema_fields_DNS_SWITCH_DEFERRED) ?? 0);
    }

    public function setDnsSwitchDeferred(int $value): self
    {
        $this->setData(self::schema_fields_DNS_SWITCH_DEFERRED, $value);
        return $this;
    }

    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_RESOLVE_STATUS) ?: self::RESOLVE_STATUS_PENDING);
    }

    public function setResolveStatus(string $status): self
    {
        $this->setData(self::schema_fields_RESOLVE_STATUS, $status);
        return $this;
    }

    public function getResolvedIp(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVED_IP);
    }

    public function setResolvedIp(string $ip): self
    {
        $this->setData(self::schema_fields_RESOLVED_IP, $ip);
        return $this;
    }

    public function getResolvedIpv6(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVED_IPV6);
    }

    public function setResolvedIpv6(string $ip): self
    {
        $this->setData(self::schema_fields_RESOLVED_IPV6, $ip);
        return $this;
    }

    public function isLocalServer(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_LOCAL_SERVER);
    }

    public function setIsLocalServer(bool $isLocal): self
    {
        $this->setData(self::schema_fields_IS_LOCAL_SERVER, $isLocal ? 1 : 0);
        return $this;
    }

    public function getResolveCheckedAt(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVE_CHECKED_AT);
    }

    public function setResolveCheckedAt(string $datetime): self
    {
        $this->setData(self::schema_fields_RESOLVE_CHECKED_AT, $datetime);
        return $this;
    }

    public function getResolveError(): string
    {
        return (string) $this->getData(self::schema_fields_RESOLVE_ERROR);
    }

    public function setResolveError(string $error): self
    {
        $this->setData(self::schema_fields_RESOLVE_ERROR, $error);
        return $this;
    }

    public function getHttpsStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_HTTPS_STATUS) ?: self::HTTPS_STATUS_NONE);
    }

    public function setHttpsStatus(string $status): self
    {
        $this->setData(self::schema_fields_HTTPS_STATUS, $status);
        return $this;
    }

    public function getHttpsExpiresAt(): string
    {
        return (string) $this->getData(self::schema_fields_HTTPS_EXPIRES_AT);
    }

    public function setHttpsExpiresAt(?string $date): self
    {
        $this->setData(
            self::schema_fields_HTTPS_EXPIRES_AT,
            ($date !== null && $date !== '') ? $date : null
        );
        return $this;
    }

    public function getHttpsError(): string
    {
        return (string) $this->getData(self::schema_fields_HTTPS_ERROR);
    }

    public function setHttpsError(string $error): self
    {
        $this->setData(self::schema_fields_HTTPS_ERROR, $error);
        return $this;
    }

    public function getHttpsRequestedAt(): string
    {
        return (string) $this->getData(self::schema_fields_HTTPS_REQUESTED_AT);
    }

    public function setHttpsRequestedAt(string $datetime): self
    {
        $this->setData(self::schema_fields_HTTPS_REQUESTED_AT, $datetime);
        return $this;
    }

    public function isSiteReady(): bool
    {
        return (bool) $this->getData(self::schema_fields_SITE_READY);
    }

    public function setSiteReady(bool $ready): self
    {
        $this->setData(self::schema_fields_SITE_READY, $ready ? 1 : 0);
        return $this;
    }

    /**
     * 计算并更新建站就绪状态
     */
    public function calculateSiteReady(): bool
    {
        $ready = $this->getStatus() === self::STATUS_ACTIVE
            && $this->getResolveStatus() === self::RESOLVE_STATUS_RESOLVED
            && $this->isLocalServer()
            && $this->getHttpsStatus() === self::HTTPS_STATUS_VALID;

        $this->setSiteReady($ready);
        return $ready;
    }

    /**
     * 获取建站就绪状态详情
     */
    public function getSiteReadyDetails(): array
    {
        return [
            'domain_active' => $this->getStatus() === self::STATUS_ACTIVE,
            'resolve_ok' => $this->getResolveStatus() === self::RESOLVE_STATUS_RESOLVED,
            'is_local' => $this->isLocalServer(),
            'https_valid' => $this->getHttpsStatus() === self::HTTPS_STATUS_VALID,
            'site_ready' => $this->isSiteReady(),
        ];
    }

    // =============== 业务方法 ===============

    /**
     * 根据域名和账户加载记录
     */
    public function loadByDomainAndAccount(string $domain, int $accountId): self
    {
        $this->clearQuery()
            ->where(self::schema_fields_DOMAIN, \strtolower(\trim($domain)))
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->find()
            ->fetch();
        return $this;
    }

    /**
     * 获取分页域名列表
     *
     * @param array $filters 筛选条件 [account_id, status, search]
     * @param int $page 页码（从1开始）
     * @param int $limit 每页数量
     * @return array{items: array, total: int, page: int, limit: int, pages: int}
     */
    public function getPagedList(array $filters, int $page = 1, int $limit = 20): array
    {
        $query = $this->newQuery();

        if (!empty($filters['account_id'])) {
            $query->where(self::schema_fields_ACCOUNT_ID, (int) $filters['account_id']);
        }

        if (!empty($filters['status'])) {
            $query->where(self::schema_fields_STATUS, $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . \trim($filters['search']) . '%';
            $query->where(self::schema_fields_DOMAIN, $search, 'like');
        }

        $items = $query->order(self::schema_fields_EXPIRES_AT, 'ASC')
            ->order(self::schema_fields_DOMAIN, 'ASC')
            ->pagination($page, $limit)
            ->select()
            ->fetchArray();

        $pagination = $query->pagination;

        return [
            'items' => $items,
            'total' => (int) ($pagination['totalSize'] ?? 0),
            'page' => (int) ($pagination['page'] ?? $page),
            'limit' => (int) ($pagination['pageSize'] ?? $limit),
            'pages' => (int) ($pagination['lastPage'] ?? 1),
        ];
    }

    /**
     * 批量更新或插入域名
     *
     * @param int $accountId 账户ID
     * @param array $domains 域名数据数组
     * @return array{synced: int, added: int, updated: int}
     */
    public function syncDomains(int $accountId, array $domains): array
    {
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $now = \date('Y-m-d H:i:s');

        foreach ($domains as $domainData) {
            $domainName = \strtolower(\trim((string) ($domainData['domain'] ?? '')));
            if ($domainName === '') {
                continue;
            }

            // 跨账户去重：如果域名已被其他账户拉取，跳过
            $existing = clone $this;
            $existing->clearQuery()
                ->where(self::schema_fields_DOMAIN, $domainName)
                ->find()
                ->fetch();
            if ($existing->getDomainId() && $existing->getAccountId() !== $accountId) {
                $skipped++;
                continue;
            }

            $model = $existing->getDomainId() ? $existing : clone $this;
            $isNew = !$model->getDomainId();

            $model->setAccountId($accountId);
            $model->setDomain($domainName);
            $model->setStatus((string) ($domainData['status'] ?? self::STATUS_ACTIVE));
            $model->setRegistrarStatus((string) ($domainData['registrar_status'] ?? ''));
            $expiresAt = $domainData['expires_at'] ?? null;
            $model->setExpiresAt($expiresAt !== null && $expiresAt !== '' ? (string) $expiresAt : null);

            if (isset($domainData['nameservers'])) {
                $ns = \is_array($domainData['nameservers']) ? $domainData['nameservers'] : [];
                $model->setNameservers($ns);
            }

            if (isset($domainData['extra_data'])) {
                $extra = \is_array($domainData['extra_data']) ? $domainData['extra_data'] : [];
                $model->setExtraData($extra);
            }

            $model->setSyncedAt($now);
            $model->save();

            if ($isNew) {
                $added++;
            } else {
                $updated++;
            }
        }

        return [
            'synced' => $added + $updated,
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * 删除账户下不在列表中的域名（用于同步后清理已删除的域名）
     *
     * @param int $accountId 账户ID
     * @param array $domainNames 当前有效的域名列表
     * @return int 删除数量
     */
    public function removeStale(int $accountId, array $domainNames): int
    {
        if ($domainNames === []) {
            return 0;
        }

        $domainNames = \array_map(fn($d) => \strtolower(\trim($d)), $domainNames);

        $existing = $this->clearQuery()
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->fields(self::schema_fields_ID . ',' . self::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();

        $deleted = 0;
        foreach ($existing as $row) {
            if (!\in_array(\strtolower($row[self::schema_fields_DOMAIN]), $domainNames, true)) {
                $this->clearData(true);
                $this->where(self::schema_fields_ID, $row[self::schema_fields_ID])->delete()->fetch();
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 获取指定 ID 的域名列表
     *
     * @param array $domainIds 域名ID数组
     * @return array
     */
    public function getByIds(array $domainIds): array
    {
        if ($domainIds === []) {
            return [];
        }

        return $this->clearQuery()
            ->where(self::schema_fields_ID, $domainIds, 'IN')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取状态选项
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => __('正常'),
            self::STATUS_PENDING => __('处理中'),
            self::STATUS_EXPIRED => __('已过期'),
            self::STATUS_SUSPENDED => __('已暂停'),
        ];
    }

    /**
     * 获取解析状态选项
     */
    public static function getResolveStatusOptions(): array
    {
        return [
            self::RESOLVE_STATUS_PENDING => __('待检测'),
            self::RESOLVE_STATUS_RESOLVED => __('已解析'),
            self::RESOLVE_STATUS_ERROR => __('解析错误'),
        ];
    }

    /**
     * 获取 HTTPS 状态选项
     */
    public static function getHttpsStatusOptions(): array
    {
        return [
            self::HTTPS_STATUS_NONE => __('无证书'),
            self::HTTPS_STATUS_PENDING => __('申请中'),
            self::HTTPS_STATUS_VALID => __('有效'),
            self::HTTPS_STATUS_EXPIRED => __('已过期'),
            self::HTTPS_STATUS_ERROR => __('申请错误'),
        ];
    }
}
