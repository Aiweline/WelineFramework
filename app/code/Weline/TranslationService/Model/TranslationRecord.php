<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\TranslationService\Model;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 翻译记录模型 */
#[Table(comment: '翻译记录表')]
#[Index(name: 'idx_w_translation_record_provider_id', columns: ['provider_id'])]
#[Index(name: 'idx_w_translation_record_provider_code', columns: ['provider_code'])]
#[Index(name: 'idx_w_translation_record_status', columns: ['status'])]
#[Index(name: 'idx_w_translation_record_created_at', columns: ['created_at'])]
#[Index(name: 'idx_w_translation_record_module_name', columns: ['module_name'])]
class TranslationRecord extends AbstractModel
{
    public string $module_name = '';
    public const schema_table = 'w_translation_record';
    public const schema_primary_key = 'record_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '记录ID')]
    public const schema_fields_ID = 'record_id';
    #[Col('int', nullable: false, comment: '渠道ID')]
    public const schema_fields_PROVIDER_ID = 'provider_id';
    #[Col('varchar', 50, nullable: false, comment: '渠道代码')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('text', nullable: false, comment: '源文本')]
    public const schema_fields_SOURCE_TEXT = 'source_text';
    #[Col('text', comment: '翻译文本')]
    public const schema_fields_TRANSLATED_TEXT = 'translated_text';
    #[Col('varchar', 20, nullable: false, comment: '源语言代码')]
    public const schema_fields_SOURCE_LANGUAGE = 'source_language';
    #[Col('varchar', 20, nullable: false, comment: '目标语言代码')]
    public const schema_fields_TARGET_LANGUAGE = 'target_language';
    #[Col('int', comment: '字符数')]
    public const schema_fields_CHARACTER_COUNT = 'character_count';
    #[Col('decimal', '10,6', default: '0', comment: '翻译成本')]
    public const schema_fields_COST = 'cost';
    #[Col('int', comment: '响应时间毫秒')]
    public const schema_fields_RESPONSE_TIME = 'response_time';
    #[Col('varchar', 20, default: 'success', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('text', comment: '请求数据JSON')]
    public const schema_fields_REQUEST_DATA = 'request_data';
    #[Col('text', comment: '响应数据JSON')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col('varchar', 100, nullable: true, comment: '模块名')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';
    public array $_unit_primary_keys = ['record_id'];
    public array $_index_sort_keys = ['record_id', 'provider_id', 'status', 'created_at'];
    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
    }
    /**
     * 获取请求数据（JSON格式）
     */
    public function getRequestData(): array
    {
        $data = $this->getData(self::schema_fields_REQUEST_DATA);
        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }
        return is_array($data) ? $data : [];
    }
    /**
     * 设置请求数据（JSON格式）
     */
    public function setRequestData(array $data): self
    {
        $this->setData(self::schema_fields_REQUEST_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 获取响应数据（JSON格式）
     */
    public function getResponseData(): array
    {
        $data = $this->getData(self::schema_fields_RESPONSE_DATA);
        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }
        return is_array($data) ? $data : [];
    }
    /**
     * 设置响应数据（JSON格式）
     */
    public function setResponseData(array $data): self
    {
        $this->setData(self::schema_fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_SUCCESS;
    }
    /**
     * 是否失败
     */
    public function isFailed(): bool
    {
        return $this->getData(self::schema_fields_STATUS) === self::STATUS_FAILED;
    }
}
