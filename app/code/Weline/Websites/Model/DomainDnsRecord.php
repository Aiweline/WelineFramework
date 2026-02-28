<?php
declare(strict_types=1);

/**
 * Weline Websites - DNS 解析记录模型
 *
 * 存储域名的 DNS 解析记录，支持记录管理和状态追踪
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainDnsRecord extends Model
{
    public const fields_ID = 'record_id';
    public const fields_DOMAIN_ID = 'domain_id';
    public const fields_RECORD_TYPE = 'record_type';
    public const fields_HOST = 'host';
    public const fields_VALUE = 'value';
    public const fields_TTL = 'ttl';
    public const fields_PRIORITY = 'priority';
    public const fields_REMOTE_RECORD_ID = 'remote_record_id';
    public const fields_IS_LOCAL_IP = 'is_local_ip';
    public const fields_RESOLVE_STATUS = 'resolve_status';
    public const fields_SYNCED_AT = 'synced_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 记录类型常量
    public const TYPE_A = 'A';
    public const TYPE_AAAA = 'AAAA';
    public const TYPE_CNAME = 'CNAME';
    public const TYPE_MX = 'MX';
    public const TYPE_TXT = 'TXT';
    public const TYPE_NS = 'NS';
    public const TYPE_SRV = 'SRV';
    public const TYPE_CAA = 'CAA';

    // 解析状态常量
    public const RESOLVE_OK = 'ok';
    public const RESOLVE_PENDING = 'pending';
    public const RESOLVE_ERROR = 'error';

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

        $setup->createTable('DNS解析记录表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', '记录ID')
            ->addColumn(self::fields_DOMAIN_ID, TableInterface::column_type_INTEGER, 11, 'not null', '域名ID')
            ->addColumn(self::fields_RECORD_TYPE, TableInterface::column_type_VARCHAR, 10, "not null default 'A'", '记录类型')
            ->addColumn(self::fields_HOST, TableInterface::column_type_VARCHAR, 255, "not null default '@'", '主机记录')
            ->addColumn(self::fields_VALUE, TableInterface::column_type_VARCHAR, 500, 'not null', '记录值')
            ->addColumn(self::fields_TTL, TableInterface::column_type_INTEGER, 11, 'default 600', 'TTL秒数')
            ->addColumn(self::fields_PRIORITY, TableInterface::column_type_INTEGER, 11, 'default 0', '优先级(MX/SRV)')
            ->addColumn(self::fields_REMOTE_RECORD_ID, TableInterface::column_type_VARCHAR, 100, "default ''", '远程记录ID')
            ->addColumn(self::fields_IS_LOCAL_IP, TableInterface::column_type_SMALLINT, 1, 'default 0', '是否指向本服务器')
            ->addColumn(self::fields_RESOLVE_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'pending'", '解析状态')
            ->addColumn(self::fields_SYNCED_AT, TableInterface::column_type_DATETIME, 0, '', '同步时间')
            ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, 0, '', '创建时间')
            ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_DATETIME, 0, '', '更新时间')
            ->addIndex(TableInterface::index_type_KEY, 'idx_domain_id', self::fields_DOMAIN_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_record_type', self::fields_RECORD_TYPE)
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_domain_type_host', [self::fields_DOMAIN_ID, self::fields_RECORD_TYPE, self::fields_HOST])
            ->create();
    }

    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::fields_UPDATED_AT, $now);

        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
    }

    // =============== Getter/Setter 方法 ===============

    public function getRecordId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function getDomainId(): int
    {
        return (int) $this->getData(self::fields_DOMAIN_ID);
    }

    public function setDomainId(int $domainId): self
    {
        $this->setData(self::fields_DOMAIN_ID, $domainId);
        return $this;
    }

    public function getRecordType(): string
    {
        return (string) $this->getData(self::fields_RECORD_TYPE);
    }

    public function setRecordType(string $type): self
    {
        $this->setData(self::fields_RECORD_TYPE, \strtoupper($type));
        return $this;
    }

    public function getHost(): string
    {
        return (string) $this->getData(self::fields_HOST);
    }

    public function setHost(string $host): self
    {
        $this->setData(self::fields_HOST, $host);
        return $this;
    }

    public function getValue(): string
    {
        return (string) $this->getData(self::fields_VALUE);
    }

    public function setValue(string $value): self
    {
        $this->setData(self::fields_VALUE, $value);
        return $this;
    }

    public function getTtl(): int
    {
        return (int) ($this->getData(self::fields_TTL) ?: 600);
    }

    public function setTtl(int $ttl): self
    {
        $this->setData(self::fields_TTL, $ttl);
        return $this;
    }

    public function getPriority(): int
    {
        return (int) $this->getData(self::fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        $this->setData(self::fields_PRIORITY, $priority);
        return $this;
    }

    public function getRemoteRecordId(): string
    {
        return (string) $this->getData(self::fields_REMOTE_RECORD_ID);
    }

    public function setRemoteRecordId(string $remoteId): self
    {
        $this->setData(self::fields_REMOTE_RECORD_ID, $remoteId);
        return $this;
    }

    public function isLocalIp(): bool
    {
        return (bool) $this->getData(self::fields_IS_LOCAL_IP);
    }

    public function setIsLocalIp(bool $isLocal): self
    {
        $this->setData(self::fields_IS_LOCAL_IP, $isLocal ? 1 : 0);
        return $this;
    }

    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::fields_RESOLVE_STATUS) ?: self::RESOLVE_PENDING);
    }

    public function setResolveStatus(string $status): self
    {
        $this->setData(self::fields_RESOLVE_STATUS, $status);
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

    // =============== 业务方法 ===============

    /**
     * 根据域名 ID 获取所有记录
     */
    public function getByDomainId(int $domainId): array
    {
        return $this->clearQuery()
            ->where(self::fields_DOMAIN_ID, $domainId)
            ->order(self::fields_RECORD_TYPE, 'ASC')
            ->order(self::fields_HOST, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 根据域名 ID 和记录类型获取记录
     */
    public function getByDomainAndType(int $domainId, string $type): array
    {
        return $this->clearQuery()
            ->where(self::fields_DOMAIN_ID, $domainId)
            ->where(self::fields_RECORD_TYPE, \strtoupper($type))
            ->order(self::fields_HOST, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 删除域名的所有记录
     */
    public function deleteByDomainId(int $domainId): int
    {
        $records = $this->getByDomainId($domainId);
        $count = \count($records);

        if ($count > 0) {
            $this->clearQuery()
                ->where(self::fields_DOMAIN_ID, $domainId)
                ->delete()
                ->fetch();
        }

        return $count;
    }

    /**
     * 批量同步 DNS 记录
     *
     * @param int $domainId 域名 ID
     * @param array $records 远程记录数组
     * @return array{synced: int, added: int, updated: int, deleted: int}
     */
    public function syncRecords(int $domainId, array $records): array
    {
        $added = 0;
        $updated = 0;
        $now = \date('Y-m-d H:i:s');

        $existingIds = [];

        foreach ($records as $record) {
            $type = \strtoupper($record['type'] ?? self::TYPE_A);
            $host = $record['host'] ?? '@';
            $value = $record['value'] ?? '';

            if ($value === '') {
                continue;
            }

            $model = clone $this;
            $model->clearQuery()
                ->where(self::fields_DOMAIN_ID, $domainId)
                ->where(self::fields_RECORD_TYPE, $type)
                ->where(self::fields_HOST, $host)
                ->find()
                ->fetch();

            $isNew = !$model->getRecordId();

            $model->setDomainId($domainId);
            $model->setRecordType($type);
            $model->setHost($host);
            $model->setValue($value);
            $model->setTtl((int) ($record['ttl'] ?? 600));
            $model->setPriority((int) ($record['priority'] ?? 0));

            if (isset($record['remote_record_id'])) {
                $model->setRemoteRecordId((string) $record['remote_record_id']);
            }

            $model->setSyncedAt($now);
            $model->save();

            $existingIds[] = $model->getRecordId();

            if ($isNew) {
                $added++;
            } else {
                $updated++;
            }
        }

        // 删除不在列表中的旧记录
        $deleted = 0;
        $allRecords = $this->getByDomainId($domainId);
        foreach ($allRecords as $row) {
            if (!\in_array((int) $row[self::fields_ID], $existingIds, true)) {
                $this->clearQuery()
                    ->where(self::fields_ID, $row[self::fields_ID])
                    ->delete()
                    ->fetch();
                $deleted++;
            }
        }

        return [
            'synced' => $added + $updated,
            'added' => $added,
            'updated' => $updated,
            'deleted' => $deleted,
        ];
    }

    /**
     * 获取记录类型选项
     */
    public static function getRecordTypeOptions(): array
    {
        return [
            self::TYPE_A => 'A',
            self::TYPE_AAAA => 'AAAA',
            self::TYPE_CNAME => 'CNAME',
            self::TYPE_MX => 'MX',
            self::TYPE_TXT => 'TXT',
            self::TYPE_NS => 'NS',
            self::TYPE_SRV => 'SRV',
            self::TYPE_CAA => 'CAA',
        ];
    }
}
