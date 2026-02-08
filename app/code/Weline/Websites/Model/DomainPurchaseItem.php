<?php
declare(strict_types=1);

/**
 * 域名购买条目模型
 *
 * 记录购买订单中的每一个域名的详细信息。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainPurchaseItem extends Model
{
    public const fields_ID = 'item_id';
    public const fields_ORDER_ID = 'order_id';              // 关联订单 ID
    public const fields_DOMAIN = 'domain';                  // 域名
    public const fields_YEARS = 'years';                    // 购买年限
    public const fields_WEBSITE_ID = 'website_id';          // 绑定站点 ID（可选，0=不绑定）
    public const fields_AUTO_CREATE_SITE = 'auto_create_site'; // yes/no 是否自动建站
    public const fields_STATUS = 'status';                  // pending / success / failed
    public const fields_ERROR_MESSAGE = 'error_message';    // 失败原因
    public const fields_PRICE = 'price';                    // 价格
    public const fields_CURRENCY = 'currency';              // 币种
    public const fields_CREATED_AT = 'created_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_id', 'domain'];

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

        $setup->createTable(__('域名购买条目表'))
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('条目ID')
            )
            ->addColumn(
                self::fields_ORDER_ID,
                TableInterface::column_type_INTEGER,
                11,
                'not null',
                __('订单ID')
            )
            ->addColumn(
                self::fields_DOMAIN,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                __('域名')
            )
            ->addColumn(
                self::fields_YEARS,
                TableInterface::column_type_INTEGER,
                11,
                'default 1',
                __('购买年限')
            )
            ->addColumn(
                self::fields_WEBSITE_ID,
                TableInterface::column_type_INTEGER,
                11,
                'default 0',
                __('绑定站点ID')
            )
            ->addColumn(
                self::fields_AUTO_CREATE_SITE,
                TableInterface::column_type_VARCHAR,
                10,
                "default 'no'",
                __('是否自动建站')
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                20,
                "default 'pending'",
                __('状态')
            )
            ->addColumn(
                self::fields_ERROR_MESSAGE,
                TableInterface::column_type_VARCHAR,
                500,
                "default ''",
                __('失败原因')
            )
            ->addColumn(
                self::fields_PRICE,
                TableInterface::column_type_DECIMAL,
                '10,2',
                'default 0.00',
                __('价格')
            )
            ->addColumn(
                self::fields_CURRENCY,
                TableInterface::column_type_VARCHAR,
                10,
                "default 'USD'",
                __('币种')
            )
            ->addColumn(
                self::fields_CREATED_AT,
                TableInterface::column_type_DATETIME,
                0,
                '',
                __('创建时间')
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID)
            ->addIndex(TableInterface::index_type_KEY, 'idx_domain', self::fields_DOMAIN)
            ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->create();
    }

    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::fields_ID)) {
            $this->setData(self::fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }

    // =============== Getter / Setter ===============

    public function getItemId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
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

    /**
     * 获取指定订单的所有条目
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->clearQuery()
            ->where(self::fields_ORDER_ID, $orderId)
            ->order(self::fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }
}
