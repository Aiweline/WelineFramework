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

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名购买条目表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_domain', columns: ['domain'])]
#[Index(name: 'idx_status', columns: ['status'])]
class DomainPurchaseItem extends Model
{
    public const schema_table = 'weline_websites_domain_purchase_item';
    public const schema_primary_key = 'item_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '条目ID')]
    public const schema_fields_ID = 'item_id';
    #[Col('int', 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('int', 11, nullable: true, default: 1, comment: '购买年限')]
    public const schema_fields_YEARS = 'years';
    #[Col('int', 11, nullable: true, default: 0, comment: '绑定站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 10, nullable: true, default: 'no', comment: '是否自动建站')]
    public const schema_fields_AUTO_CREATE_SITE = 'auto_create_site';
    #[Col('varchar', 20, nullable: true, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 500, nullable: true, default: '', comment: '失败原因')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('decimal', '10,2', nullable: true, default: '0.00', comment: '价格')]
    public const schema_fields_PRICE = 'price';
    #[Col('varchar', 10, nullable: true, default: 'USD', comment: '币种')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['item_id', 'order_id', 'domain'];

    /**
     * 保存前自动更新时间戳
     */
    public function save_before(): void
    {
        parent::save_before();

        if (!$this->getData(self::schema_fields_ID)) {
            $this->setData(self::schema_fields_CREATED_AT, \date('Y-m-d H:i:s'));
        }
    }

    // =============== Getter / Setter ===============

    public function getItemId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
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

    /**
     * 获取指定订单的所有条目
     */
    public function getItemsByOrderId(int $orderId): array
    {
        return $this->clearQuery()
            ->where(self::schema_fields_ORDER_ID, $orderId)
            ->order(self::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }
}

