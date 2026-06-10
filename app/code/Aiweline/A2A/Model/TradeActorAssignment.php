<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A order actor assignment for role-owned trade actions')]
#[Index(name: 'uk_a2a_trade_actor_assignment_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public actor assignment ID')]
#[Index(name: 'uk_a2a_trade_actor_assignment_order_role', columns: [self::schema_fields_ORDER_PUBLIC_ID, self::schema_fields_ROLE_CODE], type: 'UNIQUE', comment: 'One actor assignment per order role')]
#[Index(name: 'idx_a2a_trade_actor_assignment_actor', columns: [self::schema_fields_ACTOR_TYPE, self::schema_fields_ACTOR_REFERENCE], type: 'KEY', comment: 'Actor reference lookup')]
#[Index(name: 'idx_a2a_trade_actor_assignment_binding', columns: [self::schema_fields_AUTH_BINDING_STATUS], type: 'KEY', comment: 'Auth binding status lookup')]
#[Index(name: 'idx_a2a_trade_actor_assignment_bound_subject', columns: [self::schema_fields_BOUND_SUBJECT_TYPE, self::schema_fields_BOUND_SUBJECT_REFERENCE], type: 'KEY', comment: 'Bound account or group lookup')]
class TradeActorAssignment extends Model
{
    public const schema_table = 'aiweline_a2a_trade_actor_assignment';
    public const schema_primary_key = 'actor_assignment_id';

    public const STATUS_ACTIVE = 'active';
    public const BINDING_NEEDS_ACCOUNT = 'needs_account_binding';
    public const BINDING_ACCOUNT_BOUND = 'account_bound';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Actor assignment ID')]
    public const schema_fields_ID = 'actor_assignment_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public actor assignment ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Formal trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 32, nullable: false, comment: 'A2A trade role code')]
    public const schema_fields_ROLE_CODE = 'role_code';

    #[Col(type: 'varchar', length: 40, nullable: false, comment: 'Actor type')]
    public const schema_fields_ACTOR_TYPE = 'actor_type';

    #[Col(type: 'varchar', length: 160, nullable: false, comment: 'Actor stable reference')]
    public const schema_fields_ACTOR_REFERENCE = 'actor_reference';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Actor display snapshot')]
    public const schema_fields_ACTOR_DISPLAY = 'actor_display';

    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: 'Role ownership scope')]
    public const schema_fields_OWNERSHIP_SCOPE = 'ownership_scope';

    #[Col(type: 'varchar', length: 40, nullable: false, default: self::BINDING_NEEDS_ACCOUNT, comment: 'Auth binding status')]
    public const schema_fields_AUTH_BINDING_STATUS = 'auth_binding_status';

    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: 'Bound account, organization, or ACL group type')]
    public const schema_fields_BOUND_SUBJECT_TYPE = 'bound_subject_type';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Bound account, organization, or ACL group reference')]
    public const schema_fields_BOUND_SUBJECT_REFERENCE = 'bound_subject_reference';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Bound subject display label')]
    public const schema_fields_BOUND_SUBJECT_DISPLAY = 'bound_subject_display';

    #[Col(type: 'varchar', length: 48, nullable: false, default: '', comment: 'Verification level snapshot')]
    public const schema_fields_VERIFICATION_LEVEL = 'verification_level';

    #[Col(type: 'varchar', length: 24, nullable: false, default: self::STATUS_ACTIVE, comment: 'Assignment status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Actor assignment metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Last ACL check time')]
    public const schema_fields_LAST_CHECKED_AT = 'last_checked_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Actor assignment bound at')]
    public const schema_fields_BOUND_AT = 'bound_at';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }

        $this->setData(self::schema_fields_ORDER_PUBLIC_ID, \strtoupper(\trim((string)$this->getData(self::schema_fields_ORDER_PUBLIC_ID))));
        $this->setData(self::schema_fields_ROLE_CODE, \strtolower(\trim((string)$this->getData(self::schema_fields_ROLE_CODE))));
        $this->setData(self::schema_fields_ACTOR_TYPE, \strtolower(\trim((string)$this->getData(self::schema_fields_ACTOR_TYPE))));
        $this->setData(self::schema_fields_ACTOR_REFERENCE, \strtolower(\trim((string)$this->getData(self::schema_fields_ACTOR_REFERENCE))));
        $this->setData(self::schema_fields_BOUND_SUBJECT_TYPE, \strtolower(\trim((string)$this->getData(self::schema_fields_BOUND_SUBJECT_TYPE))));
        $this->setData(self::schema_fields_BOUND_SUBJECT_REFERENCE, \strtolower(\trim((string)$this->getData(self::schema_fields_BOUND_SUBJECT_REFERENCE))));
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }
}
