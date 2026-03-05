<?php

declare(strict_types=1);

namespace WelineTools\FontSubLetter\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '字体处理记录')]
#[Index(name: 'idx_user_id', columns: ['user_id'], comment: '用户ID索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_created_at', columns: ['created_at'], comment: '创建时间索引')]
class FontRecord extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const schema_table = 'weline_font_sub_letter_records';
    public const schema_primary_key = 'id';

    #[Col(type: 'bigint', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'bigint', nullable: true, default: 0, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '原始文件名')]
    public const schema_fields_ORIGINAL_FILENAME = 'original_filename';
    #[Col(type: 'varchar', length: 500, nullable: false, comment: '原始文件路径')]
    public const schema_fields_ORIGINAL_PATH = 'original_path';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '处理后文件名')]
    public const schema_fields_PROCESSED_FILENAME = 'processed_filename';
    #[Col(type: 'varchar', length: 500, nullable: true, comment: '处理后文件路径')]
    public const schema_fields_PROCESSED_PATH = 'processed_path';
    #[Col(type: 'text', nullable: true, comment: '提取的字符')]
    public const schema_fields_EXTRACTED_CHARS = 'extracted_chars';
    #[Col(type: 'text', nullable: true, comment: '自定义字符')]
    public const schema_fields_CUSTOM_CHARS = 'custom_chars';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '字体格式')]
    public const schema_fields_FONT_FORMAT = 'font_format';
    #[Col(type: 'bigint', nullable: false, comment: '文件大小')]
    public const schema_fields_FILE_SIZE = 'file_size';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'uploaded', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col(type: 'int', nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'int', nullable: true, default: 0, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_UPLOADED => __('已上传'),
            self::STATUS_PROCESSING => __('处理中'),
            self::STATUS_COMPLETED => __('已完成'),
            self::STATUS_FAILED => __('失败')
        ];
    }

    public function getStatusText(): string
    {
        $options = $this->getStatusOptions();
        return $options[$this->getData(self::schema_fields_STATUS)] ?? $this->getData(self::schema_fields_STATUS);
    }

    public function getFileSizeFormatted(): string
    {
        $size = $this->getData(self::schema_fields_FILE_SIZE);
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    public function getCreatedAtFormatted(): string
    {
        return date('Y-m-d H:i:s', $this->getData(self::schema_fields_CREATED_AT));
    }

    public function load(string|int $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
    {
        $this->clear();

        $query = $this->select();

        if ($value === null) {
            $query->where(self::schema_fields_ID, $field_or_pk_value);
        } else {
            $query->where($field_or_pk_value, $value);
        }

        $query->limit(1)
              ->find()
              ->fetch();

        return $this;
    }
}
