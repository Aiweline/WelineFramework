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
 * Terraform 批量执行记录
 *
 * @package Weline_Terraform
 */
class Batch extends Model
{
    public const table = 'terraform_batch';

    public string $_primary_key = 'batch_id';
    public array $_unit_primary_keys = ['batch_id'];

    public const fields_BATCH_ID = 'batch_id';
    public const fields_PROVIDER = 'provider';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_SITE_ID = 'site_id';
    public const fields_DOMAINS_RAW = 'domains_raw';
    public const fields_DNS_RECORD_TYPE = 'dns_record_type';
    public const fields_DNS_RECORD_VALUE = 'dns_record_value';
    public const fields_OVERRIDE = 'override';
    public const fields_STATUS = 'status';
    public const fields_RESULT_SUMMARY = 'result_summary';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL = 'partial';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::fields_BATCH_ID;
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
            $setup->createTable('Terraform批量执行记录')
                ->addColumn(self::fields_BATCH_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '批次ID')
                ->addColumn(self::fields_PROVIDER, TableInterface::column_type_VARCHAR, 64, 'not null', '供应商代码')
                ->addColumn(self::fields_ACCOUNT_ID, TableInterface::column_type_INTEGER, null, 'not null', '账户ID')
                ->addColumn(self::fields_SITE_ID, TableInterface::column_type_INTEGER, null, 'default 0', '网站ID')
                ->addColumn(self::fields_DOMAINS_RAW, TableInterface::column_type_TEXT, null, 'null', '域名原始输入')
                ->addColumn(self::fields_DNS_RECORD_TYPE, TableInterface::column_type_VARCHAR, 16, 'default \'\'', 'DNS记录类型')
                ->addColumn(self::fields_DNS_RECORD_VALUE, TableInterface::column_type_VARCHAR, 255, 'default \'\'', 'DNS记录值')
                ->addColumn(self::fields_OVERRIDE, TableInterface::column_type_INTEGER, 1, 'default 0', '是否覆盖已绑定域名')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, 'default \'pending\'', '执行状态')
                ->addColumn(self::fields_RESULT_SUMMARY, TableInterface::column_type_TEXT, null, 'null', '结果摘要JSON')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, null, 'default 0', '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_provider', self::fields_PROVIDER, '供应商索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_account', self::fields_ACCOUNT_ID, '账户索引')
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
