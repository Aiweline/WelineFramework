<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 表单提交记录模型
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '页面构建器-表单提交记录表')]
#[Index(name: 'idx_page_id', columns: ['page_id'], comment: '页面ID索引')]
#[Index(name: 'idx_email', columns: ['email'], comment: '邮箱索引')]
#[Index(name: 'idx_phone', columns: ['phone'], comment: '电话索引')]
#[Index(name: 'idx_status', columns: ['status'], comment: '状态索引')]
#[Index(name: 'idx_submitted_at', columns: ['submitted_at'], comment: '提交时间索引')]
class FormSubmission extends Model
{
    public const schema_table = 'guolairen_page_builder_form_submission';
    public const schema_primary_key = 'submission_id';


    // 字段定义
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '提交记录ID')]
    public const schema_fields_ID = 'submission_id';
    #[Col(type: 'int', nullable: true, comment: '关联页面ID')]
    public const schema_fields_PAGE_ID = 'page_id';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '邮箱')]
    public const schema_fields_EMAIL = 'email';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '电话')]
    public const schema_fields_PHONE = 'phone';
    #[Col(type: 'text', nullable: true, comment: '额外字段(JSON)')]
    public const schema_fields_EXTRA_FIELDS = 'extra_fields';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'IP地址')]
    public const schema_fields_IP_ADDRESS = 'ip_address';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '用户代理')]
    public const schema_fields_USER_AGENT = 'user_agent';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '来源页面')]
    public const schema_fields_REFERER = 'referer';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'new', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '提交时间')]
    public const schema_fields_SUBMITTED_AT = 'submitted_at';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    
    // 状态常量
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_SPAM = 'spam';
    
    /**
     * 获取额外字段
     */
    public function getExtraFields(): array
    {
        $extra = $this->getData(self::schema_fields_EXTRA_FIELDS);
        return $extra ? json_decode($extra ?? '', true) : [];
    }
    
    /**
     * 设置额外字段
     */
    public function setExtraFields(array $fields): self
    {
        $this->setData(self::schema_fields_EXTRA_FIELDS, json_encode($fields));
        return $this;
    }
    
    /**
     * 获取所有唯一的额外字段键
     */
    public static function getUniqueExtraFieldKeys(): array
    {
        $model = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        $submissions = $model->select()->fetch()->getItems();
        
        $keys = [];
        foreach ($submissions as $submission) {
            $extraFields = $submission->getExtraFields();
            foreach (array_keys($extraFields) as $key) {
                if (!in_array($key, $keys)) {
                    $keys[] = $key;
                }
            }
        }
        
        return $keys;
    }

}


