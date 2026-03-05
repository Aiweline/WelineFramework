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

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名购买订单表')]
#[Index(name: 'uk_order_no', columns: ['order_no'], type: 'UNIQUE')]
#[Index(name: 'idx_account_id', columns: ['account_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
class DomainPurchaseOrder extends Model
{
    public const schema_table = 'weline_websites_domain_purchase_order';
    public const schema_primary_key = 'order_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '订单ID')]
    public const schema_fields_ID = 'order_id';
    #[Col('int', 11, nullable: false, comment: '域名商账号ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 64, nullable: false, comment: '订单号')]
    public const schema_fields_ORDER_NO = 'order_no';
    #[Col('int', 11, nullable: true, default: 0, comment: '域名总数')]
    public const schema_fields_TOTAL_COUNT = 'total_count';
    #[Col('int', 11, nullable: true, default: 0, comment: '成功数')]
    public const schema_fields_SUCCESS_COUNT = 'success_count';
    #[Col('int', 11, nullable: true, default: 0, comment: '失败数')]
    public const schema_fields_FAIL_COUNT = 'fail_count';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public array $_unit_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'order_no', 'status'];

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
            // 自动生成订单号
            if (!$this->getData(self::schema_fields_ORDER_NO)) {
                $this->setData(self::schema_fields_ORDER_NO, 'DP' . \date('YmdHis') . \str_pad((string)\random_int(0, 9999), 4, '0', STR_PAD_LEFT));
            }
        }
    }

    // =============== Getter / Setter ===============

    public function getOrderId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function getOrderNo(): string
    {
        return (string) $this->getData(self::schema_fields_ORDER_NO);
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

    public function getTotalCount(): int
    {
        return (int) $this->getData(self::schema_fields_TOTAL_COUNT);
    }

    public function getSuccessCount(): int
    {
        return (int) $this->getData(self::schema_fields_SUCCESS_COUNT);
    }

    public function getFailCount(): int
    {
        return (int) $this->getData(self::schema_fields_FAIL_COUNT);
    }
}

