<?php

namespace WelineTools\FontSubLetter\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class FontRecord extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const table = 'weline_font_sub_letter_records';
    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_ORIGINAL_FILENAME = 'original_filename';
    public const fields_ORIGINAL_PATH = 'original_path';
    public const fields_PROCESSED_FILENAME = 'processed_filename';
    public const fields_PROCESSED_PATH = 'processed_path';
    public const fields_EXTRACTED_CHARS = 'extracted_chars';
    public const fields_CUSTOM_CHARS = 'custom_chars';
    public const fields_FONT_FORMAT = 'font_format';
    public const fields_FILE_SIZE = 'file_size';
    public const fields_STATUS = 'status';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
        return $options[$this->getData('status')] ?? $this->getData('status');
    }

    public function getFileSizeFormatted(): string
    {
        $size = $this->getData('file_size');
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
        return date('Y-m-d H:i:s', $this->getData('created_at'));
    }

    public function load(string|int $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
    {
        $this->clear();
        
        $query = $this->select();
        
        if ($value === null) {
            // Assume $field_or_pk_value is the primary key value
            $query->where(self::fields_ID, $field_or_pk_value);
        } else {
            // $field_or_pk_value is the field, $value is the value
            $query->where($field_or_pk_value, $value);
        }
        
        $query->limit(1)
              ->find()
              ->fetch();
        
        return $this;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 检查并添加缺失的字段
        $this->addMissingColumns($setup);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('字体处理记录')
                ->addColumn(self::fields_ID, TableInterface::column_type_BIGINT, 0, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_USER_ID, TableInterface::column_type_BIGINT, 0, 'default 0', '用户ID')
                ->addColumn(self::fields_ORIGINAL_FILENAME, TableInterface::column_type_VARCHAR, 255, 'not null', '原始文件名')
                ->addColumn(self::fields_ORIGINAL_PATH, TableInterface::column_type_VARCHAR, 500, 'not null', '原始文件路径')
                ->addColumn(self::fields_PROCESSED_FILENAME, TableInterface::column_type_VARCHAR, 255, '', '处理后文件名')
                ->addColumn(self::fields_PROCESSED_PATH, TableInterface::column_type_VARCHAR, 500, '', '处理后文件路径')
                ->addColumn(self::fields_EXTRACTED_CHARS, TableInterface::column_type_TEXT, 0, '', '提取的字符')
                ->addColumn(self::fields_CUSTOM_CHARS, TableInterface::column_type_TEXT, 0, '', '自定义字符')
                ->addColumn(self::fields_FONT_FORMAT, TableInterface::column_type_VARCHAR, 10, 'not null', '字体格式')
                ->addColumn(self::fields_FILE_SIZE, TableInterface::column_type_BIGINT, 0, 'not null', '文件大小')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_VARCHAR, 20, "default 'uploaded'", '状态')
                ->addColumn(self::fields_ERROR_MESSAGE, TableInterface::column_type_TEXT, 0, '', '错误信息')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_INTEGER, 0, "default 0", '创建时间')
                ->addColumn(self::fields_UPDATED_AT, TableInterface::column_type_INTEGER, 0, "default 0", '更新时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID, '用户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT, '创建时间索引')
                ->create();
        } else {
            // 如果表已存在，添加缺失的字段
            $this->addMissingColumns($setup);
        }
    }

    private function addMissingColumns(ModelSetup $setup): void
    {
        // 暂时简化，通过setup:upgrade命令来添加字段
        // 这些字段将在下次setup:upgrade时自动添加
    }
}
