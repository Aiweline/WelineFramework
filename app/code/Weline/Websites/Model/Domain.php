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

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

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
class Domain extends Model
{
    public const fields_ID = 'domain_id';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_DOMAIN = 'domain';
    public const fields_STATUS = 'status';
    public const fields_REGISTRAR_STATUS = 'registrar_status';
    public const fields_EXPIRES_AT = 'expires_at';
    public const fields_NAMESERVERS = 'nameservers';
    public const fields_EXTRA_DATA = 'extra_data';
    public const fields_SYNCED_AT = 'synced_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // v1.5.0 新增字段 - CDN/DNS 归属信息（保留在 Domain 模型）
    public const fields_CDN_PROVIDER = 'cdn_provider';
    public const fields_CDN_ACCOUNT_ID = 'cdn_account_id';
    public const fields_DNS_PROVIDER = 'dns_provider';
    public const fields_DNS_ACCOUNT_ID = 'dns_account_id';
    
    // v1.5.0 新增字段 - 以下字段已废弃，应使用 DomainPool 模型中的对应字段
    // @deprecated v1.6.0 使用 DomainPool 模型代替
    public const fields_RESOLVE_STATUS = 'resolve_status';
    public const fields_RESOLVED_IP = 'resolved_ip';
    public const fields_RESOLVED_IPV6 = 'resolved_ipv6';
    public const fields_IS_LOCAL_SERVER = 'is_local_server';
    public const fields_RESOLVE_CHECKED_AT = 'resolve_checked_at';
    public const fields_RESOLVE_ERROR = 'resolve_error';
    public const fields_HTTPS_STATUS = 'https_status';
    public const fields_HTTPS_EXPIRES_AT = 'https_expires_at';
    public const fields_HTTPS_ERROR = 'https_error';
    public const fields_HTTPS_REQUESTED_AT = 'https_requested_at';
    public const fields_SITE_READY = 'site_ready';

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
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }

        // v1.5.0: 新增 CDN/DNS/解析/HTTPS 相关字段
        // alterTable()->addColumn 签名: (field_name, after_column, type, length, options, comment)
        $alter = $setup->alterTable();
        $hasChanges = false;

        if (!$setup->hasField(self::fields_CDN_PROVIDER)) {
            $alter->addColumn(self::fields_CDN_PROVIDER, self::fields_UPDATED_AT, TableInterface::column_type_VARCHAR, 50, "default ''", 'CDN供应商代码');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_CDN_ACCOUNT_ID)) {
            $alter->addColumn(self::fields_CDN_ACCOUNT_ID, self::fields_CDN_PROVIDER, TableInterface::column_type_INTEGER, 11, 'default 0', 'CDN账户ID');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_DNS_PROVIDER)) {
            $alter->addColumn(self::fields_DNS_PROVIDER, self::fields_CDN_ACCOUNT_ID, TableInterface::column_type_VARCHAR, 50, "default ''", 'DNS服务商代码');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_DNS_ACCOUNT_ID)) {
            $alter->addColumn(self::fields_DNS_ACCOUNT_ID, self::fields_DNS_PROVIDER, TableInterface::column_type_INTEGER, 11, 'default 0', 'DNS管理账户ID（第三方DNS服务商）');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_RESOLVE_STATUS)) {
            $alter->addColumn(self::fields_RESOLVE_STATUS, self::fields_DNS_PROVIDER, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '解析状态');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_RESOLVED_IP)) {
            $alter->addColumn(self::fields_RESOLVED_IP, self::fields_RESOLVE_STATUS, TableInterface::column_type_VARCHAR, 45, "default ''", '解析到的IPv4');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_RESOLVED_IPV6)) {
            $alter->addColumn(self::fields_RESOLVED_IPV6, self::fields_RESOLVED_IP, TableInterface::column_type_VARCHAR, 45, "default ''", '解析到的IPv6');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_IS_LOCAL_SERVER)) {
            $alter->addColumn(self::fields_IS_LOCAL_SERVER, self::fields_RESOLVED_IPV6, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否指向本服务器');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_RESOLVE_CHECKED_AT)) {
            $alter->addColumn(self::fields_RESOLVE_CHECKED_AT, self::fields_IS_LOCAL_SERVER, TableInterface::column_type_DATETIME, 0, '', '解析检测时间');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_RESOLVE_ERROR)) {
            $alter->addColumn(self::fields_RESOLVE_ERROR, self::fields_RESOLVE_CHECKED_AT, TableInterface::column_type_TEXT, 0, '', '解析错误信息');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_HTTPS_STATUS)) {
            $alter->addColumn(self::fields_HTTPS_STATUS, self::fields_RESOLVE_ERROR, TableInterface::column_type_VARCHAR, 20, "default 'none'", 'HTTPS证书状态');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_HTTPS_EXPIRES_AT)) {
            $alter->addColumn(self::fields_HTTPS_EXPIRES_AT, self::fields_HTTPS_STATUS, TableInterface::column_type_DATE, 0, '', '证书过期时间');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_HTTPS_ERROR)) {
            $alter->addColumn(self::fields_HTTPS_ERROR, self::fields_HTTPS_EXPIRES_AT, TableInterface::column_type_TEXT, 0, '', '证书申请错误');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_HTTPS_REQUESTED_AT)) {
            $alter->addColumn(self::fields_HTTPS_REQUESTED_AT, self::fields_HTTPS_ERROR, TableInterface::column_type_DATETIME, 0, '', '证书申请时间');
            $hasChanges = true;
        }

        if (!$setup->hasField(self::fields_SITE_READY)) {
            $alter->addColumn(self::fields_SITE_READY, self::fields_HTTPS_REQUESTED_AT, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否可建站');
            $hasChanges = true;
        }

        if ($hasChanges) {
            $alter->alter();
        }

        // 添加索引
        if (!$setup->hasIndex('idx_resolve_status')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_resolve_status', self::fields_RESOLVE_STATUS)
                ->alter();
        }

        if (!$setup->hasIndex('idx_https_status')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_https_status', self::fields_HTTPS_STATUS)
                ->alter();
        }

        if (!$setup->hasIndex('idx_site_ready')) {
            $setup->alterTable()
                ->addIndex(TableInterface::index_type_KEY, 'idx_site_ready', self::fields_SITE_READY)
                ->alter();
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('域名表 - 同步自域名商')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '域名ID')
            ->addColumn(self::fields_ACCOUNT_ID, TableInterface::column_type_INTEGER, 11, 'not null', '域名商账户ID')
            ->addColumn(self::fields_DOMAIN, TableInterface::column_type_VARCHAR, 255, 'not null', '域名')
            ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'active'", '标准化状态')
            ->addColumn(self::fields_REGISTRAR_STATUS, TableInterface::column_type_VARCHAR, 50, "default ''", '域名商原始状态')
            ->addColumn(self::fields_EXPIRES_AT, TableInterface::column_type_DATE, 0, '', '过期时间')
            ->addColumn(self::fields_NAMESERVERS, TableInterface::column_type_TEXT, 0, '', 'DNS服务器JSON')
            ->addColumn(self::fields_EXTRA_DATA, TableInterface::column_type_TEXT, 0, '', '域名商原始数据JSON')
            ->addColumn(self::fields_SYNCED_AT, TableInterface::column_type_DATETIME, 0, '', '最后同步时间')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_account_domain', [self::fields_ACCOUNT_ID, self::fields_DOMAIN])
            ->addIndex(TableInterface::index_type_KEY, 'idx_account_id', self::fields_ACCOUNT_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(TableInterface::index_type_KEY, 'idx_expires_at', self::fields_EXPIRES_AT)
            ->create();
    }

    /**
     * 保存前自动更新时间戳和建站状态
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);

        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }

        $domain = $this->getData(self::fields_DOMAIN);
        if ($domain) {
            $this->setData(self::fields_DOMAIN, \strtolower(\trim($domain)));
        }

        // 自动计算建站就绪状态
        $this->calculateSiteReady();
    }

    // =============== Getter/Setter 方法 ===============

    public function getDomainId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function getAccountId(): int
    {
        return (int) $this->getData(self::fields_ACCOUNT_ID);
    }

    public function setAccountId(int $accountId): self
    {
        $this->setData(self::fields_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
    }

    public function setDomain(string $domain): self
    {
        $this->setData(self::fields_DOMAIN, \strtolower(\trim($domain)));
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }

    public function getRegistrarStatus(): string
    {
        return (string) $this->getData(self::fields_REGISTRAR_STATUS);
    }

    public function setRegistrarStatus(string $status): self
    {
        $this->setData(self::fields_REGISTRAR_STATUS, $status);
        return $this;
    }

    public function getExpiresAt(): string
    {
        return (string) $this->getData(self::fields_EXPIRES_AT);
    }

    public function setExpiresAt(string $date): self
    {
        $this->setData(self::fields_EXPIRES_AT, $date);
        return $this;
    }

    public function getNameservers(): array
    {
        $ns = $this->getData(self::fields_NAMESERVERS);
        if (\is_string($ns) && $ns !== '') {
            $decoded = \json_decode($ns, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setNameservers(array $nameservers): self
    {
        $this->setData(self::fields_NAMESERVERS, \json_encode($nameservers, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getExtraData(): array
    {
        $data = $this->getData(self::fields_EXTRA_DATA);
        if (\is_string($data) && $data !== '') {
            $decoded = \json_decode($data, true);
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function setExtraData(array $data): self
    {
        $this->setData(self::fields_EXTRA_DATA, \json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function getSyncedAt(): string
    {
        return (string) $this->getData(self::fields_SYNCED_AT);
    }

    public function setSyncedAt(string $datetime): self
    {
        $this->setData(self::fields_SYNCED_AT, $datetime);
        return $this;
    }

    // =============== v1.5.0 新增 Getter/Setter ===============

    public function getCdnProvider(): string
    {
        return (string) $this->getData(self::fields_CDN_PROVIDER);
    }

    public function setCdnProvider(string $provider): self
    {
        $this->setData(self::fields_CDN_PROVIDER, $provider);
        return $this;
    }

    public function getCdnAccountId(): int
    {
        return (int) $this->getData(self::fields_CDN_ACCOUNT_ID);
    }

    public function setCdnAccountId(int $accountId): self
    {
        $this->setData(self::fields_CDN_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getDnsProvider(): string
    {
        return (string) $this->getData(self::fields_DNS_PROVIDER);
    }

    public function setDnsProvider(string $provider): self
    {
        $this->setData(self::fields_DNS_PROVIDER, $provider);
        return $this;
    }

    public function getDnsAccountId(): int
    {
        return (int) $this->getData(self::fields_DNS_ACCOUNT_ID);
    }

    public function setDnsAccountId(int $accountId): self
    {
        $this->setData(self::fields_DNS_ACCOUNT_ID, $accountId);
        return $this;
    }

    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::fields_RESOLVE_STATUS) ?: self::RESOLVE_STATUS_PENDING);
    }

    public function setResolveStatus(string $status): self
    {
        $this->setData(self::fields_RESOLVE_STATUS, $status);
        return $this;
    }

    public function getResolvedIp(): string
    {
        return (string) $this->getData(self::fields_RESOLVED_IP);
    }

    public function setResolvedIp(string $ip): self
    {
        $this->setData(self::fields_RESOLVED_IP, $ip);
        return $this;
    }

    public function getResolvedIpv6(): string
    {
        return (string) $this->getData(self::fields_RESOLVED_IPV6);
    }

    public function setResolvedIpv6(string $ip): self
    {
        $this->setData(self::fields_RESOLVED_IPV6, $ip);
        return $this;
    }

    public function isLocalServer(): bool
    {
        return (bool) $this->getData(self::fields_IS_LOCAL_SERVER);
    }

    public function setIsLocalServer(bool $isLocal): self
    {
        $this->setData(self::fields_IS_LOCAL_SERVER, $isLocal ? 1 : 0);
        return $this;
    }

    public function getResolveCheckedAt(): string
    {
        return (string) $this->getData(self::fields_RESOLVE_CHECKED_AT);
    }

    public function setResolveCheckedAt(string $datetime): self
    {
        $this->setData(self::fields_RESOLVE_CHECKED_AT, $datetime);
        return $this;
    }

    public function getResolveError(): string
    {
        return (string) $this->getData(self::fields_RESOLVE_ERROR);
    }

    public function setResolveError(string $error): self
    {
        $this->setData(self::fields_RESOLVE_ERROR, $error);
        return $this;
    }

    public function getHttpsStatus(): string
    {
        return (string) ($this->getData(self::fields_HTTPS_STATUS) ?: self::HTTPS_STATUS_NONE);
    }

    public function setHttpsStatus(string $status): self
    {
        $this->setData(self::fields_HTTPS_STATUS, $status);
        return $this;
    }

    public function getHttpsExpiresAt(): string
    {
        return (string) $this->getData(self::fields_HTTPS_EXPIRES_AT);
    }

    public function setHttpsExpiresAt(string $date): self
    {
        $this->setData(self::fields_HTTPS_EXPIRES_AT, $date);
        return $this;
    }

    public function getHttpsError(): string
    {
        return (string) $this->getData(self::fields_HTTPS_ERROR);
    }

    public function setHttpsError(string $error): self
    {
        $this->setData(self::fields_HTTPS_ERROR, $error);
        return $this;
    }

    public function getHttpsRequestedAt(): string
    {
        return (string) $this->getData(self::fields_HTTPS_REQUESTED_AT);
    }

    public function setHttpsRequestedAt(string $datetime): self
    {
        $this->setData(self::fields_HTTPS_REQUESTED_AT, $datetime);
        return $this;
    }

    public function isSiteReady(): bool
    {
        return (bool) $this->getData(self::fields_SITE_READY);
    }

    public function setSiteReady(bool $ready): self
    {
        $this->setData(self::fields_SITE_READY, $ready ? 1 : 0);
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
            ->where(self::fields_DOMAIN, \strtolower(\trim($domain)))
            ->where(self::fields_ACCOUNT_ID, $accountId)
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
            $query->where(self::fields_ACCOUNT_ID, (int) $filters['account_id']);
        }

        if (!empty($filters['status'])) {
            $query->where(self::fields_STATUS, $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . \trim($filters['search']) . '%';
            $query->where(self::fields_DOMAIN, $search, 'like');
        }

        $items = $query->order(self::fields_EXPIRES_AT, 'ASC')
            ->order(self::fields_DOMAIN, 'ASC')
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
                ->where(self::fields_DOMAIN, $domainName)
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
            $model->setExpiresAt((string) ($domainData['expires_at'] ?? ''));

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
            ->where(self::fields_ACCOUNT_ID, $accountId)
            ->fields(self::fields_ID . ',' . self::fields_DOMAIN)
            ->select()
            ->fetchArray();

        $deleted = 0;
        foreach ($existing as $row) {
            if (!\in_array(\strtolower($row[self::fields_DOMAIN]), $domainNames, true)) {
                $this->clearData(true);
                $this->where(self::fields_ID, $row[self::fields_ID])->delete()->fetch();
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
            ->where(self::fields_ID, $domainIds, 'IN')
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
