<?php
namespace Weline\Visitor\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'weline 访客像素统计-附加数据')]
class PixelAdditional extends Model
{
    public const schema_table = 'w_pixel_additional';
    public const schema_primary_key = 'pixel_additional_id';
    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'pixel_additional_id';
    #[Col('bigint', 0, nullable: false, comment: '像素ID')]
    public const schema_fields_PIXEL_ID = 'pixel_id';
    #[Col('text', comment: '总事件数据')]
    public const schema_fields_TOTAL_EVENT_DATA = 'total_event_data';
public function getPixelId(): int
    {
        return (int)$this->getData(self::schema_fields_PIXEL_ID);
    }
    public function setPixelId(int $pixel_id): self
    {
        return $this->setData(self::schema_fields_PIXEL_ID, $pixel_id);
    }
    public function getTotalEventData(): string
    {
        return (string)$this->getData(self::schema_fields_TOTAL_EVENT_DATA);
    }
    public function setTotalEventData(string $total_event_data): self
    {
        return $this->setData(self::schema_fields_TOTAL_EVENT_DATA, $total_event_data);
    }
    
    /**
     * 获取当前记录的ID
     * 
     * @param mixed $default 默认值
     * @return int
     */
    public function getId(mixed $default = 0): int
    {
        return (int)$this->getData(self::schema_fields_ID, $default);
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
            ->where(self::schema_fields_PIXEL_ID, $pixelId)
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