<?php
declare(strict_types=1);
namespace Weline\Acl\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * IP白名单模型
 */
#[Table(comment: 'IP白名单表')]
#[Index(name: 'idx_ip', columns: ['ip'], comment: 'IP地址索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '启用状态索引')]
class IpWhitelist extends Model
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 45, nullable: false, comment: 'IP地址')]
    public const schema_fields_IP = 'ip';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'int', nullable: false, default: 1, comment: '是否启用')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
/**
     * 检查IP是否在白名单中
     * 
     * @param string $ip
     * @return bool
     */
    public static function isWhitelisted(string $ip): bool
    {
        try {
            $count = w_obj(self::class)
                ->reset()
                ->where(self::schema_fields_IP, $ip)
                ->where(self::schema_fields_IS_ACTIVE, 1)
                ->count();
            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
