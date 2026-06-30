<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social scope account defaults')]
#[Index(name: 'idx_social_site_account_website', columns: ['website_id'])]
#[Index(name: 'idx_social_site_account_scope', columns: ['scope_type', 'scope_id'])]
#[Index(name: 'idx_social_site_account_child', columns: ['scope_type', 'scope_id', 'child_scope_type', 'child_scope_id'])]
#[Index(name: 'idx_social_site_account_account', columns: ['account_id'])]
#[Index(name: 'idx_social_site_account_platform', columns: ['platform_code'])]
#[Index(name: 'idx_social_site_account_status', columns: ['status'])]
#[Index(name: 'uniq_social_scope_account', columns: ['scope_type', 'scope_id', 'child_scope_type', 'child_scope_id', 'account_id'], type: 'UNIQUE')]
class SocialWebsiteAccount extends Model
{
    public const schema_table = 'weline_social_website_account';
    public const schema_primary_key = 'relation_id';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const SCOPE_TYPE_WEBSITE = 'website';
    public const CHILD_SCOPE_TYPE_WEBSITE_DEFAULT = 'website_default';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Relation ID')]
    public const schema_fields_ID = 'relation_id';
    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 32, nullable: false, default: 'website', comment: 'First level scope type')]
    public const schema_fields_SCOPE_TYPE = 'scope_type';
    #[Col('int', 0, nullable: false, default: 0, comment: 'First level scope ID')]
    public const schema_fields_SCOPE_ID = 'scope_id';
    #[Col('varchar', 32, nullable: false, default: 'website_default', comment: 'Second level scope type')]
    public const schema_fields_CHILD_SCOPE_TYPE = 'child_scope_type';
    #[Col('int', 0, nullable: false, default: 0, comment: 'Second level scope ID')]
    public const schema_fields_CHILD_SCOPE_ID = 'child_scope_id';
    #[Col('int', 0, nullable: false, comment: 'Social account ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 64, nullable: false, comment: 'Social platform code')]
    public const schema_fields_PLATFORM_CODE = 'platform_code';
    #[Col('int', 1, nullable: false, default: 1, comment: 'Default for website publishing')]
    public const schema_fields_IS_DEFAULT = 'is_default';
    #[Col('int', 11, nullable: false, default: 1000, comment: 'Default account sort order')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('varchar', 32, nullable: false, default: 'active', comment: 'Relation status')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['relation_id'];
    public array $_index_sort_keys = [
        'website_id',
        'scope_type',
        'scope_id',
        'child_scope_type',
        'child_scope_id',
        'account_id',
        'platform_code',
        'status',
        'sort_order',
    ];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'relation_id' => (int)$this->getId(),
            'website_id' => (int)$this->getData(self::schema_fields_WEBSITE_ID),
            'scope_type' => (string)$this->getData(self::schema_fields_SCOPE_TYPE),
            'scope_id' => (int)$this->getData(self::schema_fields_SCOPE_ID),
            'child_scope_type' => (string)$this->getData(self::schema_fields_CHILD_SCOPE_TYPE),
            'child_scope_id' => (int)$this->getData(self::schema_fields_CHILD_SCOPE_ID),
            'scope_key' => \implode(':', [
                (string)$this->getData(self::schema_fields_SCOPE_TYPE),
                (string)(int)$this->getData(self::schema_fields_SCOPE_ID),
                (string)$this->getData(self::schema_fields_CHILD_SCOPE_TYPE),
                (string)(int)$this->getData(self::schema_fields_CHILD_SCOPE_ID),
            ]),
            'account_id' => (int)$this->getData(self::schema_fields_ACCOUNT_ID),
            'platform_code' => (string)$this->getData(self::schema_fields_PLATFORM_CODE),
            'is_default' => (int)$this->getData(self::schema_fields_IS_DEFAULT),
            'sort_order' => (int)$this->getData(self::schema_fields_SORT_ORDER),
            'status' => (string)$this->getData(self::schema_fields_STATUS),
            'created_at' => (string)$this->getData(self::schema_fields_CREATED_AT),
            'updated_at' => (string)$this->getData(self::schema_fields_UPDATED_AT),
        ];
    }
}
