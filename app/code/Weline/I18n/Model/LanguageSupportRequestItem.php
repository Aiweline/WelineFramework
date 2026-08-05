<?php

declare(strict_types=1);

namespace Weline\I18n\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Locales requested by I18n language support requests')]
#[Index(name: 'uk_i18n_language_request_locale', columns: ['request_id', 'locale_code'], type: 'UNIQUE')]
#[Index(name: 'idx_i18n_language_request_status_site', columns: ['status', 'website_id'])]
#[Index(name: 'idx_i18n_language_request_locale_status', columns: ['locale_code', 'status'])]
final class LanguageSupportRequestItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_READY = 'ready';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_READY,
        self::STATUS_REJECTED,
        self::STATUS_ASSIGNED,
    ];

    public const schema_table = 'weline_i18n_language_support_request_item';
    public const schema_primary_key = 'item_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Request item ID')]
    public const schema_fields_ID = 'item_id';
    #[Col('int', 11, nullable: false, comment: 'Request ID')]
    public const schema_fields_REQUEST_ID = 'request_id';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID snapshot')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 32, nullable: false, comment: 'Canonical locale code')]
    public const schema_fields_LOCALE = 'locale_code';
    #[Col('varchar', 3, nullable: false, default: '', comment: 'Country or UN M49 code')]
    public const schema_fields_COUNTRY = 'country_code';
    #[Col('varchar', 16, nullable: false, default: self::STATUS_PENDING, comment: 'Workflow status')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: true, comment: 'Reviewing backend user ID')]
    public const schema_fields_REVIEWED_BY = 'reviewed_by';
    #[Col('varchar', 1000, nullable: true, comment: 'Review note')]
    public const schema_fields_REVIEW_NOTE = 'review_note';
    #[Col('datetime', nullable: true, comment: 'Reviewed at')]
    public const schema_fields_REVIEWED_AT = 'reviewed_at';
    #[Col('datetime', nullable: true, comment: 'Became ready at')]
    public const schema_fields_READY_AT = 'ready_at';
    #[Col('datetime', nullable: true, comment: 'Assigned to website at')]
    public const schema_fields_ASSIGNED_AT = 'assigned_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_ID,
        self::schema_fields_REQUEST_ID,
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_LOCALE,
        self::schema_fields_STATUS,
    ];
}
