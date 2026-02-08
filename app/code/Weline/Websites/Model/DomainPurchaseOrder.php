<?php
declare(strict_types=1);

/**
 * 域名购买订单模型
 *
 * 记录批量购买域名的订单信息。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainPurchaseOrder extends Model
{
    public const fields_ID = 'order_id';
    public const fields_ACCOUNT_ID = 'account_id';        // 关联域名商账号 ID
    public const fields_ORDER_NO = 'order_no';            // 订单号（系统生成）
    public const fields_TOTAL_COUNT = 'total_count';      // 域名总数
    public const fields_SUCCESS_COUNT = 'success_count';  // 成功数
    public const fields_FAIL_COUNT = 'fail_count';        // 失败数
    public const fields_STATUS = 'status';                // pending / processing / completed / failed
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public array $_unit_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'order_no', 'status'];

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

        $setup->createTable(__('域名购买订单表'))
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('订单ID')
            )
            ->addColumn(
                self::fields_ACCOUNT_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                __('域名商账号ID')
            )
            ->addColumn(
                self::fields_ORDER_NO,
                TableInterface::column_type_VARCHAR,
                64,
                'not null',
                __('订单号')
            )
            ->addColumn(
                self::fields_TOTAL_COUNT,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('域名总数')
            )
            ->addColumn(
                self::fields_SUCCESS_COUNT,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('成功数')
            )
            ->addColumn(
                self::fields_FAIL_COUNT,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('失败数')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'pending'",
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
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_order_no', self::fields_ORDER_NO)
            ->addIndex(TableInterface::index_type_KEY, 'idx_account_id', self::fields_ACCOUNT_ID)
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
            // 自动生成订单号
            if (!$this->getData(self::fields_ORDER_NO)) {
                $this->setData(self::fields_ORDER_NO, 'DP' . \date('YmdHis') . \str_pad((string)\random_int(0, 9999), 4, '0', STR_PAD_LEFT));
            }
        }
    }

    // =============== Getter / Setter ===============

    public function getOrderId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function getOrderNo(): string
    {
        return (string) $this->getData(self::fields_ORDER_NO);
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

    public function getTotalCount(): int
    {
        return (int) $this->getData(self::fields_TOTAL_COUNT);
    }

    public function getSuccessCount(): int
    {
        return (int) $this->getData(self::fields_SUCCESS_COUNT);
    }

    public function getFailCount(): int
    {
        return (int) $this->getData(self::fields_FAIL_COUNT);
    }
}
