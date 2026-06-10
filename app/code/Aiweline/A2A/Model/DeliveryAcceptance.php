<?php

declare(strict_types=1);

namespace Aiweline\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A delivery evidence and buyer acceptance record')]
#[Index(name: 'uk_a2a_delivery_acceptance_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public delivery acceptance ID')]
#[Index(name: 'uk_a2a_delivery_acceptance_order_public_id', columns: [self::schema_fields_ORDER_PUBLIC_ID], type: 'UNIQUE', comment: 'Trade order public ID')]
#[Index(name: 'idx_a2a_delivery_acceptance_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Delivery acceptance status')]
class DeliveryAcceptance extends Model
{
    public const schema_table = 'aiweline_a2a_delivery_acceptance';
    public const schema_primary_key = 'delivery_acceptance_id';

    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REWORK_REQUESTED = 'rework_requested';
    public const DECISION_ACCEPT = 'accept_release';
    public const DECISION_REWORK = 'request_rework';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Delivery acceptance ID')]
    public const schema_fields_ID = 'delivery_acceptance_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public delivery acceptance ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Trade order public ID')]
    public const schema_fields_ORDER_PUBLIC_ID = 'order_public_id';

    #[Col(type: 'varchar', length: 64, nullable: false, default: '', comment: 'Provider scope public ID')]
    public const schema_fields_PROVIDER_SCOPE_PUBLIC_ID = 'provider_scope_public_id';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name snapshot')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'longtext', nullable: false, comment: 'Delivery evidence JSON')]
    public const schema_fields_DELIVERY_EVIDENCE_JSON = 'delivery_evidence_json';

    #[Col(type: 'longtext', nullable: false, comment: 'Acceptance checklist JSON')]
    public const schema_fields_ACCEPTANCE_CHECKLIST_JSON = 'acceptance_checklist_json';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_ACCEPTED, comment: 'Delivery acceptance status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::DECISION_ACCEPT, comment: 'Buyer acceptance decision')]
    public const schema_fields_DECISION = 'decision';

    #[Col(type: 'longtext', nullable: true, comment: 'Delivery acceptance metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

    #[Col(type: 'datetime', nullable: true, comment: 'Delivery evidence submitted at')]
    public const schema_fields_SUBMITTED_AT = 'submitted_at';

    #[Col(type: 'datetime', nullable: true, comment: 'Buyer accepted at')]
    public const schema_fields_ACCEPTED_AT = 'accepted_at';

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
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getPublicId(): string
    {
        return (string)($this->getData(self::schema_fields_PUBLIC_ID) ?: '');
    }
}
