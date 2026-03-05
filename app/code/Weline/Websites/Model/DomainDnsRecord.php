<?php
declare(strict_types=1);

/**
 * Weline Websites - DNS 解析记录模型
 *
 * 存储域名的 DNS 解析记录，支持记录管理和状态追踪
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'DNS解析记录表')]
#[Index(name: 'idx_domain_id', columns: ['domain_id'])]
#[Index(name: 'idx_record_type', columns: ['record_type'])]
#[Index(name: 'uk_domain_type_host', columns: ['domain_id', 'record_type', 'host'], type: 'UNIQUE')]
class DomainDnsRecord extends Model
{
    public const schema_table = 'weline_websites_domain_dns_record';
    public const schema_primary_key = 'record_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '记录ID')]
    public const schema_fields_ID = 'record_id';
    #[Col('int', 11, nullable: false, comment: '域名ID')]
    public const schema_fields_DOMAIN_ID = 'domain_id';
    #[Col('varchar', 10, nullable: false, default: 'A', comment: '记录类型')]
    public const schema_fields_RECORD_TYPE = 'record_type';
    #[Col('varchar', 255, nullable: false, default: '@', comment: '主机记录')]
    public const schema_fields_HOST = 'host';
    #[Col('varchar', 500, nullable: false, comment: '记录值')]
    public const schema_fields_VALUE = 'value';
    #[Col('int', 11, nullable: true, default: 600, comment: 'TTL秒数')]
    public const schema_fields_TTL = 'ttl';
    #[Col('int', 11, nullable: true, default: 0, comment: '优先级(MX/SRV)')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('varchar', 100, nullable: true, default: '', comment: '远程记录ID')]
    public const schema_fields_REMOTE_RECORD_ID = 'remote_record_id';
    #[Col('smallint', 1, nullable: true, default: 0, comment: '是否指向本服务器')]
    public const schema_fields_IS_LOCAL_IP = 'is_local_ip';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: '解析状态')]
    public const schema_fields_RESOLVE_STATUS = 'resolve_status';
    #[Col('datetime', nullable: true, comment: '同步时间')]
    public const schema_fields_SYNCED_AT = 'synced_at';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATED_AT, $now);

        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
    }

    // =============== Getter/Setter 方法 ===============

    public function getRecordId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function getDomainId(): int
    {
        return (int) $this->getData(self::schema_fields_DOMAIN_ID);
    }

    public function setDomainId(int $domainId): self
    {
        $this->setData(self::schema_fields_DOMAIN_ID, $domainId);
        return $this;
    }

    public function getRecordType(): string
    {
        return (string) $this->getData(self::schema_fields_RECORD_TYPE);
    }

    public function setRecordType(string $type): self
    {
        $this->setData(self::schema_fields_RECORD_TYPE, \strtoupper($type));
        return $this;
    }

    public function getHost(): string
    {
        return (string) $this->getData(self::schema_fields_HOST);
    }

    public function setHost(string $host): self
    {
        $this->setData(self::schema_fields_HOST, $host);
        return $this;
    }

    public function getValue(): string
    {
        return (string) $this->getData(self::schema_fields_VALUE);
    }

    public function setValue(string $value): self
    {
        $this->setData(self::schema_fields_VALUE, $value);
        return $this;
    }

    public function getTtl(): int
    {
        return (int) ($this->getData(self::schema_fields_TTL) ?: 600);
    }

    public function setTtl(int $ttl): self
    {
        $this->setData(self::schema_fields_TTL, $ttl);
        return $this;
    }

    public function getPriority(): int
    {
        return (int) $this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        $this->setData(self::schema_fields_PRIORITY, $priority);
        return $this;
    }

    public function getRemoteRecordId(): string
    {
        return (string) $this->getData(self::schema_fields_REMOTE_RECORD_ID);
    }

    public function setRemoteRecordId(string $remoteId): self
    {
        $this->setData(self::schema_fields_REMOTE_RECORD_ID, $remoteId);
        return $this;
    }

    public function isLocalIp(): bool
    {
        return (bool) $this->getData(self::schema_fields_IS_LOCAL_IP);
    }

    public function setIsLocalIp(bool $isLocal): self
    {
        $this->setData(self::schema_fields_IS_LOCAL_IP, $isLocal ? 1 : 0);
        return $this;
    }

    public function getResolveStatus(): string
    {
        return (string) ($this->getData(self::schema_fields_RESOLVE_STATUS) ?: self::RESOLVE_PENDING);
    }

    public function setResolveStatus(string $status): self
    {
        $this->setData(self::schema_fields_RESOLVE_STATUS, $status);
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

    // =============== 业务方法 ===============

    /**
     * 根据域名 ID 获取所有记录
     */
    public function getByDomainId(int $domainId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_DOMAIN_ID, $domainId)
            ->order(self::schema_fields_RECORD_TYPE, 'ASC')
            ->order(self::schema_fields_HOST, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 根据域名 ID 和记录类型获取记录
     */
    public function getByDomainAndType(int $domainId, string $type): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_DOMAIN_ID, $domainId)
            ->where(self::schema_fields_RECORD_TYPE, \strtoupper($type))
            ->order(self::schema_fields_HOST, 'ASC')
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
                ->where(self::schema_fields_DOMAIN_ID, $domainId)
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
                ->where(self::schema_fields_DOMAIN_ID, $domainId)
                ->where(self::schema_fields_RECORD_TYPE, $type)
                ->where(self::schema_fields_HOST, $host)
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
            if (!\in_array((int) $row[self::schema_fields_ID], $existingIds, true)) {
                $this->clearQuery()
                    ->where(self::schema_fields_ID, $row[self::schema_fields_ID])
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

