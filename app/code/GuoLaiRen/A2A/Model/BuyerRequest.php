<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A buyer request created before agent quote selection')]
#[Index(name: 'uk_a2a_buyer_request_public_id', columns: [self::schema_fields_PUBLIC_ID], type: 'UNIQUE', comment: 'Public buyer request ID')]
#[Index(name: 'uk_a2a_buyer_request_code', columns: [self::schema_fields_CODE], type: 'UNIQUE', comment: 'Prototype request code')]
#[Index(name: 'idx_a2a_buyer_request_status', columns: [self::schema_fields_STATUS], type: 'KEY', comment: 'Buyer request status')]
class BuyerRequest extends Model
{
    public const schema_table = 'guolairen_a2a_buyer_request';
    public const schema_primary_key = 'buyer_request_id';

    public const STATUS_QUOTE_READY = 'quote_ready';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Buyer request ID')]
    public const schema_fields_ID = 'buyer_request_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Public buyer request ID')]
    public const schema_fields_PUBLIC_ID = 'public_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Prototype request code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Buyer request title')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'varchar', length: 64, nullable: false, default: 'prototype-buyer', comment: 'Buyer reference')]
    public const schema_fields_BUYER_REFERENCE = 'buyer_reference';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Request category')]
    public const schema_fields_CATEGORY = 'category';

    #[Col(type: 'text', nullable: true, comment: 'Requirement summary')]
    public const schema_fields_REQUIREMENT_SUMMARY = 'requirement_summary';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Budget amount')]
    public const schema_fields_BUDGET_AMOUNT = 'budget_amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: 'Request risk level')]
    public const schema_fields_RISK_LEVEL = 'risk_level';

    #[Col(type: 'varchar', length: 32, nullable: false, default: self::STATUS_QUOTE_READY, comment: 'Buyer request status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'longtext', nullable: true, comment: 'Acceptance rules JSON')]
    public const schema_fields_ACCEPTANCE_RULES_JSON = 'acceptance_rules_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Buyer request metadata JSON')]
    public const schema_fields_METADATA_JSON = 'metadata_json';

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

        $this->setData(self::schema_fields_CODE, \strtolower(\trim((string)$this->getData(self::schema_fields_CODE))));
        $this->setData(self::schema_fields_CURRENCY_CODE, \strtoupper((string)($this->getData(self::schema_fields_CURRENCY_CODE) ?: 'USD')));
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
