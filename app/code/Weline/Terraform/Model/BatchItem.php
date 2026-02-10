<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Terraform\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * Terraform 批次域名项
 *
 * @package Weline_Terraform
 */
class BatchItem extends Model
{
    public const table = 'terraform_batch_item';

    public string $_primary_key = 'item_id';
    public array $_unit_primary_keys = ['item_id'];

    public const fields_ITEM_ID = 'item_id';
    public const fields_BATCH_ID = 'batch_id';
    public const fields_DOMAIN_NAME = 'domain_name';
    public const fields_SITE_ID = 'site_id';
    public const fields_PROVIDER = 'provider';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_ZONE_ID = 'zone_id';
    public const fields_STATUS = 'status';
    public const fields_MESSAGE = 'message';
    public const fields_DNS_RECORD = 'dns_record';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_ITEM_ID;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Terraform批次域名项')
                ->addColumn(self::fields_ITEM_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '项ID')
                ->addColumn(self::fields_BATCH_ID, TableInterface::column_type_INTEGER, null, 'not null', '批次ID')
                ->addColumn(self::fields_DOMAIN_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '域名')
                ->addColumn(self::fields_SITE_ID, TableInterface::column_type_INTEGER, null, 'default 0', '网站ID')
                ->addColumn(self::fields_PROVIDER, TableInterface::column_type_VARCHAR, 64, 'not null', '供应商代码')
                ->addColumn(self::fields_ACCOUNT_ID, TableInterface::column_type_INTEGER, null, 'not null', '账户ID')
                ->addColumn(self::fields_ZONE_ID, TableInterface::column_type_VARCHAR, 128, 'default \'\'', 'Zone ID')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'success\'', '状态')
                ->addColumn(self::fields_MESSAGE, TableInterface::column_type_TEXT, null, 'null', '结果消息')
                ->addColumn(self::fields_DNS_RECORD, TableInterface::column_type_TEXT, null, 'null', 'DNS记录JSON')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_batch_id', self::fields_BATCH_ID, '批次索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_domain', self::fields_DOMAIN_NAME, '域名索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->create();
        }
    }

    public function beforeSave(): self
    {
        $now = time();
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, $now);
        }
        $this->setData(self::fields_UPDATED_AT, $now);
        return parent::beforeSave();
    }
}
