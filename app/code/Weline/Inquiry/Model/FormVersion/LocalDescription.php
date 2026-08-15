<?php

declare(strict_types=1);

namespace Weline\Inquiry\Model\FormVersion;

use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inquiry\Model\FormVersion;

#[Table(comment: '询盘表单版本多语言文案')]
#[Index(name: 'idx_inquiry_version_locale', columns: ['version_id', 'local_code'], type: 'UNIQUE')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'weline_inquiry_form_version_local';
    public const schema_primary_key = 'local_description_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '翻译 ID')]
    public const schema_fields_LOCAL_DESCRIPTION_ID = 'local_description_id';
    #[Col('int', nullable: false, comment: '版本 ID')]
    public const schema_fields_ID = FormVersion::schema_fields_ID;
    #[Col('text', nullable: false, comment: '语言文案 JSON')]
    public const schema_fields_CONFIG = 'config';
}
