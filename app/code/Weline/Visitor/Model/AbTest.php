<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * A/B测试模型
 * 
 * 用于管理A/B测试配置和数据
 */
class AbTest extends Model
{
    public const fields_ID = 'test_id';
    public const fields_TEST_ID = 'test_id';
    public const fields_WEBSITE_ID = 'website_id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_STATUS = 'status';
    public const fields_START_DATE = 'start_date';
    public const fields_END_DATE = 'end_date';
    public const fields_VARIANT_A = 'variant_a';
    public const fields_VARIANT_B = 'variant_b';
    public const fields_TRAFFIC_SPLIT = 'traffic_split';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public string $table = 'w_ab_test';

    public const status_ACTIVE = 'active';
    public const status_PAUSED = 'paused';
    public const status_COMPLETED = 'completed';
    public const status_DRAFT = 'draft';

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
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('weline A/B测试')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_BIGINT,
                0,
                'primary key auto_increment',
                'ID'
            )
            ->addColumn(
                self::fields_TEST_ID,
                TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '测试ID（唯一标识）'
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '站点ID'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '测试名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                null,
                '',
                '测试描述'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                'not null default \'draft\'',
                '状态：active-进行中, paused-暂停, completed-已完成, draft-草稿'
            )
            ->addColumn(
                self::fields_START_DATE,
                TableInterface::column_type_DATETIME,
                null,
                '',
                '开始时间'
            )
            ->addColumn(
                self::fields_END_DATE,
                TableInterface::column_type_DATETIME,
                null,
                '',
                '结束时间'
            )
            ->addColumn(
                self::fields_VARIANT_A,
                TableInterface::column_type_TEXT,
                null,
                '',
                '变体A配置（JSON格式）'
            )
            ->addColumn(
                self::fields_VARIANT_B,
                TableInterface::column_type_TEXT,
                null,
                '',
                '变体B配置（JSON格式）'
            )
            ->addColumn(
                self::fields_TRAFFIC_SPLIT,
                TableInterface::column_type_VARCHAR,
                20,
                'default \'50:50\'',
                '流量分配比例（A:B，如50:50）'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                TableInterface::column_type_DATETIME,
                null,
                'not null default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_test_id',
                self::fields_TEST_ID,
                '测试ID唯一索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_website_id',
                self::fields_WEBSITE_ID,
                '站点ID索引'
            )
            ->addIndex(
                TableInterface::index_type_KEY,
                'idx_status',
                self::fields_STATUS,
                '状态索引'
            )
            ->create();
    }

    /**
     * 获取测试ID
     */
    public function getTestId(): string
    {
        return (string)$this->getData(self::fields_TEST_ID);
    }

    /**
     * 设置测试ID
     */
    public function setTestId(string $testId): static
    {
        return $this->setData(self::fields_TEST_ID, $testId);
    }

    /**
     * 获取站点ID
     */
    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::fields_WEBSITE_ID);
    }

    /**
     * 设置站点ID
     */
    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::fields_WEBSITE_ID, $websiteId);
    }

    /**
     * 获取测试名称
     */
    public function getName(): string
    {
        return (string)$this->getData(self::fields_NAME);
    }

    /**
     * 设置测试名称
     */
    public function setName(string $name): static
    {
        return $this->setData(self::fields_NAME, $name);
    }

    /**
     * 获取状态
     */
    public function getStatus(): string
    {
        return (string)$this->getData(self::fields_STATUS);
    }

    /**
     * 设置状态
     */
    public function setStatus(string $status): static
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    /**
     * 获取所有活跃的测试
     */
    public static function getActiveTests(?int $websiteId = null): array
    {
        $model = w_obj(self::class)->reset()
            ->where(self::fields_STATUS, self::status_ACTIVE);
        
        if ($websiteId !== null) {
            $model->where(self::fields_WEBSITE_ID, $websiteId);
        }
        
        return $model->select()->fetchArray();
    }

    /**
     * 根据测试ID获取测试
     */
    public static function getByTestId(string $testId): ?self
    {
        $model = w_obj(self::class)->reset()
            ->where(self::fields_TEST_ID, $testId)
            ->find()
            ->fetch();
        
        return $model->getId() ? $model : null;
    }
}

