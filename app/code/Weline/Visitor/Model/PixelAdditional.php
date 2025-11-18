<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PixelAdditional extends Model
{
    public const fields_ID = 'pixel_additional_id';
    public const fields_PIXEL_ID = 'pixel_id';
    public const fields_TOTAL_EVENT_DATA = 'total_event_data';
    
    public string $table = 'w_pixel_additional';


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
        // 检查并添加缺失的字段
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }
        
        // 如果表已存在，检查是否需要添加新字段
        // 目前所有字段都在install中定义，暂不需要升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        /** @var Pixel $pixel */
        $pixel = w_obj(Pixel::class);
        $setup->createTable('weline 访客像素统计-附加数据')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_BIGINT,
                0,
                'primary key auto_increment',
                'ID'
            )
            ->addColumn(
                self::fields_PIXEL_ID,
                TableInterface::column_type_BIGINT,
                0,
                'not null',
                '像素ID'
            )
            ->addColumn(
                self::fields_TOTAL_EVENT_DATA,
                TableInterface::column_type_TEXT,
                null,
                '',
                '总事件数据'
            )
//            ->addForeignKey(
//                'FK_pixel_id',
//                self::fields_ID,
//                $pixel->getTable(),
//                Pixel::fields_ID,
//                true)
            ->create();
    }

    public function getPixelId(): int
    {
        return (int)$this->getData(self::fields_PIXEL_ID);
    }

    public function setPixelId(int $pixel_id): self
    {
        return $this->setData(self::fields_PIXEL_ID, $pixel_id);
    }

    public function getTotalEventData(): string
    {
        return (string)$this->getData(self::fields_TOTAL_EVENT_DATA);
    }

    public function setTotalEventData(string $total_event_data): self
    {
        return $this->setData(self::fields_TOTAL_EVENT_DATA, $total_event_data);
    }
    
    /**
     * 获取当前记录的ID
     * 
     * @param mixed $default 默认值
     * @return int
     */
    public function getId(mixed $default = 0): int
    {
        return (int)$this->getData(self::fields_ID, $default);
    }
    
    /**
     * 获取像素ID对应的附加数据
     * 
     * @param int $pixelId 像素ID
     * @return PixelAdditional|null
     */
    public static function getByPixelId(int $pixelId): ?PixelAdditional
    {
        $model = w_obj(self::class);
        $result = $model->reset()
            ->where(self::fields_PIXEL_ID, $pixelId)
            ->find()
            ->fetch();
        
        if ($result && $result->getId()) {
            return $result;
        }
        
        return null;
    }
    
    /**
     * 获取像素ID对应的附加数据（数组格式）
     * 
     * @param int $pixelId 像素ID
     * @return array|null
     */
    public static function getEventDataByPixelId(int $pixelId): ?array
    {
        $additional = self::getByPixelId($pixelId);
        if (!$additional) {
            return null;
        }
        
        $eventData = json_decode($additional->getTotalEventData(), true);
        return is_array($eventData) ? $eventData : null;
    }
    
    /**
     * 获取A/B测试数据（从附加数据中提取）
     * 
     * @param int $pixelId 像素ID
     * @return array|null 返回包含testId和variant的数组，如果没有则返回null
     */
    public static function getAbTestDataByPixelId(int $pixelId): ?array
    {
        $eventData = self::getEventDataByPixelId($pixelId);
        if (!$eventData) {
            return null;
        }
        
        $testId = $eventData['testId'] ?? $eventData['test_id'] ?? null;
        $variant = $eventData['variant'] ?? $eventData['testVariant'] ?? null;
        
        if ($testId === null && $variant === null) {
            return null;
        }
        
        return [
            'testId' => $testId,
            'variant' => $variant
        ];
    }
}