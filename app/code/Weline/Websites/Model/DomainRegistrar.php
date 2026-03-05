<?php
declare(strict_types=1);

/**
 * 域名商模型
 *
 * 记录系统支持的域名商渠道类型（如 AWS Route53、阿里云域名、Azure DNS 等）。
 * 每个渠道对应一个适配器实现。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名商渠道表')]
#[Index(name: 'uk_code', columns: ['code'], type: 'UNIQUE')]
#[Index(name: 'idx_status', columns: ['status'])]
class DomainRegistrar extends Model
{
    public const schema_table = 'weline_websites_domain_registrar';
    public const schema_primary_key = 'registrar_id';

    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '域名商ID')]
    public const schema_fields_ID = 'registrar_id';
    #[Col('varchar', 100, nullable: false, comment: '适配器标识')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 255, nullable: false, comment: '显示名称')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 500, nullable: true, default: '', comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('varchar', 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = ['registrar_id'];
    public array $_index_sort_keys = ['registrar_id', 'code', 'name'];

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

    // =============== Getter / Setter ===============

    public function getRegistrarId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::schema_fields_CODE, $code);
        return $this;
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::schema_fields_CODE);
    }

    public function setName(string $name): self
    {
        $this->setData(self::schema_fields_NAME, $name);
        return $this;
    }

    public function getName(): string
    {
        return (string) $this->getData(self::schema_fields_NAME);
    }

    public function setDescription(string $description): self
    {
        $this->setData(self::schema_fields_DESCRIPTION, $description);
        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->getData(self::schema_fields_DESCRIPTION);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::schema_fields_STATUS, $status);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }

    // =============== 业务方法 ===============

    /**
     * 根据 code 加载域名商
     */
    public function loadByCode(string $code): self
    {
        $this->clearQuery()
            ->where(self::schema_fields_CODE, $code)
            ->find()
            ->fetch();
        return $this;
    }

    /**
     * 获取所有活跃的域名商
     */
    public function getActiveRegistrars(): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::schema_fields_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取所有域名商列表
     */
    public function getAllRegistrars(): array
    {
        return $this->clearQuery()
            ->order(self::schema_fields_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
}
