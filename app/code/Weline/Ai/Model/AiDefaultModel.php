<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * AI默认模型管理数据模型
 * 
 * 功能：
 * - 管理不同服务类型的默认模型
 * - 支持模型优先级设置
 * - 提供模型删除保护机制
 * - 记录模型使用统计
 */
class AiDefaultModel extends Model
{
    public const table = 'ai_default_model';
    
    // 字段常量
    public const fields_ID = 'id';
    public const fields_SERVICE_TYPE = 'service_type';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_PRIORITY = 'priority';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_DESCRIPTION = 'description';
    public const fields_CREATED_TIME = 'created_time';
    public const fields_UPDATED_TIME = 'updated_time';

    // 服务类型常量
    public const SERVICE_TYPE_CHAT = 'chat';
    public const SERVICE_TYPE_TRANSLATION = 'translation';
    public const SERVICE_TYPE_COMPLETION = 'completion';
    public const SERVICE_TYPE_EMBEDDING = 'embedding';
    public const SERVICE_TYPE_IMAGE = 'image';
    public const SERVICE_TYPE_AUDIO = 'audio';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_SERVICE_TYPE, TableInterface::column_type_VARCHAR, 50, 'not null', '服务类型')
                ->addColumn(self::fields_MODEL_CODE, TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
                ->addColumn(self::fields_PRIORITY, TableInterface::column_type_INTEGER, 3, 'not null default 1', '优先级')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_INTEGER, 1, 'not null default 1', '是否激活')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, 'null', '描述')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->addColumn(self::fields_UPDATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '更新时间')
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_service_model', [self::fields_SERVICE_TYPE, self::fields_MODEL_CODE], '服务类型模型唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_service_type', self::fields_SERVICE_TYPE, '服务类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_model_code', self::fields_MODEL_CODE, '模型代码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_priority', self::fields_PRIORITY, '优先级索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '激活状态索引')
                ->create();
        }
    }

    /**
     * 获取指定服务类型的默认模型
     * 
     * @param string $serviceType
     * @return string|null
     */
    public static function getDefaultModelCode(string $serviceType): ?string
    {
        $defaultModel = new self();
        $model = $defaultModel->reset()
            ->where(self::fields_SERVICE_TYPE, $serviceType)
            ->where(self::fields_IS_ACTIVE, 1)
            ->order(self::fields_PRIORITY, 'DESC')
            ->find()
            ->fetch();
        
        return $model ? $model->getData(self::fields_MODEL_CODE) : null;
    }

    /**
     * 获取模型代码
     * 
     * @return string
     */
    public function getModelCode(): string
    {
        return $this->getData(self::fields_MODEL_CODE) ?? '';
    }

    /**
     * 设置默认模型
     * 
     * @param string $serviceType
     * @param string $modelCode
     * @param int $priority
     * @param string $description
     * @return bool
     */
    public static function setDefaultModel(string $serviceType, string $modelCode, int $priority = 1, string $description = ''): bool
    {
        $defaultModel = new self();
        
        // 检查是否已存在
        $existingModel = $defaultModel->reset()
            ->where(self::fields_SERVICE_TYPE, $serviceType)
            ->where(self::fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();
        
        if ($existingModel) {
            // 更新现有记录
            $existingModel->setData(self::fields_PRIORITY, $priority);
            $existingModel->setData(self::fields_IS_ACTIVE, 1);
            if ($description) {
                $existingModel->setData(self::fields_DESCRIPTION, $description);
            }
            $existingModel->save();
        } else {
            // 创建新记录
            $defaultModel->setData([
                self::fields_SERVICE_TYPE => $serviceType,
                self::fields_MODEL_CODE => $modelCode,
                self::fields_PRIORITY => $priority,
                self::fields_IS_ACTIVE => 1,
                self::fields_DESCRIPTION => $description
            ])->save();
        }
        
        return true;
    }

    /**
     * 检查模型是否被设为默认模型
     * 
     * @param string $modelCode
     * @return bool
     */
    public static function isDefaultModel(string $modelCode): bool
    {
        $defaultModel = new self();
        $count = $defaultModel->reset()
            ->where(self::fields_MODEL_CODE, $modelCode)
            ->where(self::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->count();
        
        return $count > 0;
    }

    /**
     * 获取模型的默认服务类型列表
     * 
     * @param string $modelCode
     * @return array
     */
    public static function getModelServiceTypes(string $modelCode): array
    {
        $defaultModel = new self();
        $models = $defaultModel->reset()
            ->where(self::fields_MODEL_CODE, $modelCode)
            ->where(self::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();
        
        $serviceTypes = [];
        foreach ($models as $model) {
            $serviceTypes[] = $model->getData(self::fields_SERVICE_TYPE);
        }
        
        return $serviceTypes;
    }

    /**
     * 移除默认模型设置
     * 
     * @param string $serviceType
     * @param string $modelCode
     * @return bool
     */
    public static function removeDefaultModel(string $serviceType, string $modelCode): bool
    {
        $defaultModel = new self();
        $model = $defaultModel->reset()
            ->where(self::fields_SERVICE_TYPE, $serviceType)
            ->where(self::fields_MODEL_CODE, $modelCode)
            ->find()
            ->fetch();
        
        if ($model) {
            $model->setData(self::fields_IS_ACTIVE, 0);
            $model->save();
            return true;
        }
        
        return false;
    }

    /**
     * 获取所有服务类型
     * 
     * @return array
     */
    public static function getAllServiceTypes(): array
    {
        return [
            self::SERVICE_TYPE_CHAT => '聊天对话',
            self::SERVICE_TYPE_TRANSLATION => '文本翻译',
            self::SERVICE_TYPE_COMPLETION => '文本补全',
            self::SERVICE_TYPE_EMBEDDING => '文本嵌入',
            self::SERVICE_TYPE_IMAGE => '图像生成',
            self::SERVICE_TYPE_AUDIO => '音频处理'
        ];
    }

    /**
     * 检查是否为激活状态
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->getData(self::fields_IS_ACTIVE);
    }

    /**
     * 保存前的数据处理
     * 
     * @return $this
     */
    public function beforeSave(): self
    {
        parent::beforeSave();
        
        $currentTime = time();
        if (!$this->getId()) {
            $this->setData(self::fields_CREATED_TIME, $currentTime);
        }
        $this->setData(self::fields_UPDATED_TIME, $currentTime);
        
        return $this;
    }
}
