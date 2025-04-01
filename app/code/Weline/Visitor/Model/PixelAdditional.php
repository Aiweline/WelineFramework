<?php

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class PixelAdditional extends Model
{

    public const fields_ID = 'pixel_id';
    public const fields_TOTAL_EVENT_DATA = 'total_event_data';


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
        if ($setup->tableExist()) {
            return;
        }
        /** @var Pixel $pixel */
        $pixel = obj(Pixel::class);
        $setup->createTable('weline 访客像素统计-附加数据')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                'ID'
            )
            ->addColumn(
                self::fields_TOTAL_EVENT_DATA,
                TableInterface::column_type_TEXT,
                null,
                '',
                '总事件数据'
            )
            ->addForeignKey(
                'FK_pixel_id',
                self::fields_ID,
                $pixel->getTable(),
                Pixel::fields_ID,
                true)
            ->create();
    }

    public function getPixelId(): int
    {
        return $this->getData(self::fields_ID);
    }

    public function setPixelId(int $pixel_id): self
    {
        return $this->setData(self::fields_ID, $pixel_id);
    }

    public function getTotalEventData(): string
    {
        return $this->getData(self::fields_TOTAL_EVENT_DATA);
    }

    public function setTotalEventData(string $total_event_data): self
    {
        return $this->setData(self::fields_TOTAL_EVENT_DATA, $total_event_data);
    }
}