<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;

#[Table(comment: 'weline visitor pixel additional data')]
class PixelAdditional extends Model
{
    public const schema_table = 'w_pixel_additional';
    public const schema_primary_key = 'pixel_additional_id';

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'pixel_additional_id';

    #[Col('bigint', 0, nullable: false, comment: 'pixel id')]
    public const schema_fields_PIXEL_ID = 'pixel_id';

    #[Col('text', comment: 'total event data')]
    public const schema_fields_TOTAL_EVENT_DATA = 'total_event_data';

    public function save_before()
    {
        parent::save_before();

        $modelData = $this->getModelData();
        if (!array_key_exists(self::schema_fields_ID, $modelData) && $this->getId()) {
            $this->unsetData(self::schema_fields_ID);
            $this->unsetModelData(self::schema_fields_ID);
        }
    }

    public function getPixelId(): int
    {
        return (int)$this->getData(self::schema_fields_PIXEL_ID);
    }

    public function setPixelId(int|string $pixelId): self
    {
        return $this->setData(self::schema_fields_PIXEL_ID, (int)$pixelId);
    }

    public function getTotalEventData(): string
    {
        return (string)$this->getData(self::schema_fields_TOTAL_EVENT_DATA);
    }

    public function setTotalEventData(string $totalEventData): self
    {
        return $this->setData(self::schema_fields_TOTAL_EVENT_DATA, $totalEventData);
    }

    public function getId(mixed $default = 0): int
    {
        $value = $this->getData(self::schema_fields_ID);

        return ($value === null || $value === '') ? (int)$default : (int)$value;
    }

    public static function getByPixelId(int|string $pixelId): ?PixelAdditional
    {
        $pixelId = (int)$pixelId;
        $rows = w_obj(self::class)->reset()
            ->where(self::schema_fields_PIXEL_ID, $pixelId)
            ->select()
            ->fetchArray();

        if (!$rows) {
            return null;
        }

        $result = ObjectManager::make(self::class);
        $result->setObjectData($rows[0]);

        return $result;
    }

    public static function getEventDataByPixelId(int|string $pixelId): ?array
    {
        $additional = self::getByPixelId($pixelId);
        if (!$additional) {
            return null;
        }

        $eventData = json_decode($additional->getTotalEventData(), true);

        return is_array($eventData) ? $eventData : null;
    }

    public static function getAbTestDataByPixelId(int|string $pixelId): ?array
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
            'variant' => $variant,
        ];
    }
}
