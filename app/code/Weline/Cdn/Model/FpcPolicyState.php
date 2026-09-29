<?php
declare(strict_types=1);
namespace Weline\Cdn\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 声明、覆盖与待同步目标在同一事务中提交，彼此独立更新。 */
#[Table(comment: 'FPC 策略控制面状态')]
class FpcPolicyState extends Model
{
    public const schema_table = 'cdn_fpc_policy_state';
    public const schema_primary_key = 'state_id';
    public string $_primary_key = 'state_id';
    public array $_unit_primary_keys = ['state_id'];
    #[Col('int', primaryKey: true, nullable: false, comment: '控制面单例ID')]
    public const schema_fields_ID = 'state_id';
    #[Col('longtext', nullable: false, comment: '声明、覆盖和持久变更状态JSON')]
    public const schema_fields_STATE = 'state_json';
    #[Col('datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED = 'updated_at';
    public function _init(): void { $this->useMainDbMaster(); }
}
