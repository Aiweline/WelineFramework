<?php

declare(strict_types=1);

namespace Weline\I18n\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'I18n language support requests')]
#[Index(name: 'uk_i18n_language_request_public_id', columns: ['public_id'], type: 'UNIQUE')]
#[Index(name: 'idx_i18n_language_request_website_created', columns: ['website_id', 'created_at'])]
#[Index(name: 'idx_i18n_language_request_customer', columns: ['customer_id'])]
#[Index(name: 'idx_i18n_language_request_email', columns: ['email'])]
#[Index(name: 'idx_i18n_language_request_ip_created', columns: ['ip_hash', 'created_at'])]
final class LanguageSupportRequest extends Model
{
    public const schema_table = 'weline_i18n_language_support_request';
    public const schema_primary_key = 'request_id';

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Request ID')]
    public const schema_fields_ID = 'request_id';
    #[Col('varchar', 40, nullable: false, comment: 'Public request number')]
    public const schema_fields_PUBLIC_ID = 'public_id';
    #[Col('int', 11, nullable: false, default: 0, comment: 'Website ID; 0 is the valid default website')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 11, nullable: true, comment: 'Frontend customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 120, nullable: false, comment: 'Applicant name')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 190, nullable: false, comment: 'Applicant email')]
    public const schema_fields_EMAIL = 'email';
    #[Col('varchar', 190, nullable: false, comment: 'Source hostname')]
    public const schema_fields_SOURCE_DOMAIN = 'source_domain';
    #[Col('varchar', 64, nullable: false, comment: 'Privacy-preserving source IP hash')]
    public const schema_fields_IP_HASH = 'ip_hash';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_ID,
        self::schema_fields_PUBLIC_ID,
        self::schema_fields_WEBSITE_ID,
        self::schema_fields_CUSTOMER_ID,
    ];
}
