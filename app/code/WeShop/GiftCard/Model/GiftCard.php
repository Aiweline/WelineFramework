<?php
declare(strict_types=1);
namespace WeShop\GiftCard\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 礼品卡模型
 */
#[Table(comment: 'WeShop礼品卡表')]
#[Index(name: 'idx_card_number', columns: ['card_number'], type: 'UNIQUE', comment: '卡号唯一索引')]
class GiftCard extends Model
{
    public const schema_table = 'weshop_gift_card';
    public const schema_primary_key = 'card_id';
    public string $indexer = 'gift_card_indexer';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '礼品卡ID')]
    public const schema_fields_ID = 'card_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '卡号')]
    public const schema_fields_CARD_NUMBER = 'card_number';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '面额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: 0.00, comment: '余额')]
    public const schema_fields_BALANCE = 'balance';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'active', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public array $_unit_primary_keys = ['card_id'];
    public array $_index_sort_keys = ['card_number', 'status'];
}
