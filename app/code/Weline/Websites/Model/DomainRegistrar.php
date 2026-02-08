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

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainRegistrar extends Model
{
    public const fields_ID = 'registrar_id';
    public const fields_CODE = 'code';                  // 适配器标识，如 aws_route53
    public const fields_NAME = 'name';                  // 显示名称
    public const fields_DESCRIPTION = 'description';    // 描述
    public const fields_STATUS = 'status';              // 状态：active / disabled
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public array $_unit_primary_keys = ['registrar_id'];
    public array $_index_sort_keys = ['registrar_id', 'code', 'name'];

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

        $setup->createTable(__('域名商渠道表'))
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('域名商ID')
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                __('适配器标识')
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                __('显示名称')
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                __('描述')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'active'",
                __('状态')
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('创建时间')
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('更新时间')
            )
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_code', self::fields_CODE)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
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

    // =============== Getter / Setter ===============

    public function getRegistrarId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function setCode(string $code): self
    {
        $this->setData(self::fields_CODE, $code);
        return $this;
    }

    public function getCode(): string
    {
        return (string) $this->getData(self::fields_CODE);
    }

    public function setName(string $name): self
    {
        $this->setData(self::fields_NAME, $name);
        return $this;
    }

    public function getName(): string
    {
        return (string) $this->getData(self::fields_NAME);
    }

    public function setDescription(string $description): self
    {
        $this->setData(self::fields_DESCRIPTION, $description);
        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->getData(self::fields_DESCRIPTION);
    }

    public function setStatus(string $status): self
    {
        $this->setData(self::fields_STATUS, $status);
        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    // =============== 业务方法 ===============

    /**
     * 根据 code 加载域名商
     */
    public function loadByCode(string $code): self
    {
        $this->clearQuery()
            ->where(self::fields_CODE, $code)
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
            ->where(self::fields_STATUS, self::STATUS_ACTIVE)
            ->order(self::fields_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 获取所有域名商列表
     */
    public function getAllRegistrars(): array
    {
        return $this->clearQuery()
            ->order(self::fields_NAME, 'ASC')
            ->select()
            ->fetchArray();
    }
}
