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
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 翻译记录模型
 */
class TranslationRecord extends AbstractModel
{
    public const table = 'w_translation_record';
    
    public const fields_ID = 'record_id';
    public const fields_PROVIDER_ID = 'provider_id';
    public const fields_PROVIDER_CODE = 'provider_code';
    public const fields_SOURCE_TEXT = 'source_text';
    public const fields_TRANSLATED_TEXT = 'translated_text';
    public const fields_SOURCE_LANGUAGE = 'source_language';
    public const fields_TARGET_LANGUAGE = 'target_language';
    public const fields_CHARACTER_COUNT = 'character_count';
    public const fields_COST = 'cost';
    public const fields_RESPONSE_TIME = 'response_time';
    public const fields_STATUS = 'status';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_REQUEST_DATA = 'request_data';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_MODULE_NAME = 'module_name';
    public const fields_CREATED_AT = 'created_at';

    /**
     * 状态常量
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['record_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['record_id', 'provider_id', 'status', 'created_at'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 表结构已在Setup/Install.php中创建
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 获取请求数据（JSON格式）
     */
    public function getRequestData(): array
    {
        $data = $this->getData(self::fields_REQUEST_DATA);
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
        $this->setData(self::fields_REQUEST_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 获取响应数据（JSON格式）
     */
    public function getResponseData(): array
    {
        $data = $this->getData(self::fields_RESPONSE_DATA);
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
        $this->setData(self::fields_RESPONSE_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_SUCCESS;
    }

    /**
     * 是否失败
     */
    public function isFailed(): bool
    {
        return $this->getData(self::fields_STATUS) === self::STATUS_FAILED;
    }
}

