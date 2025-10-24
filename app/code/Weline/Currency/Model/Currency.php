<?php

namespace Weline\Currency\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Currency extends Model
{
    public const fields_ID = 'currency_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_RATE = 'rate';
    public const fields_SYMBOL = 'symbol';
    public const fields_POSITION = 'position';
    public const fields_FORMAT = 'format';
    public const fields_STATUS = 'status';

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
        $setup->createTable('货币')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                11,
                'auto_increment primary key',
                'ID'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                3,
                'not null',
                '货币代码'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '货币名称'
            )
            ->addColumn(
                self::fields_RATE,
                TableInterface::column_type_DECIMAL,
                10,
                'not null',
                '汇率'
            )
            ->addColumn(
                self::fields_SYMBOL,
                TableInterface::column_type_VARCHAR,
                10,
                'not null',
                '货币符号'
            )
            ->addColumn(
                self::fields_POSITION,
                TableInterface::column_type_VARCHAR,
                10,
                'not null',
                '货币符号位置'
            )
            ->addColumn(
                self::fields_FORMAT,
                TableInterface::column_type_VARCHAR,
                10,
                'not null',
                '货币格式'
            )
            ->addColumn(
                self::fields_STATUS,
                TableInterface::column_type_VARCHAR,
                10,
                'not null',
                '货币状态'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_currency_code',
                self::fields_CODE,
                '货币代码索引'
            )
            ->create();

        # 设置默认货币
        $this->setCode('CNY')
            ->setName('人民币')
            ->setRate(1)
            ->setSymbol('￥')
            ->setPosition('left')
            ->setFormat('1,0')
            ->setStatus(true)
            ->save();
        $this->setCode('USD')
            ->setName('美刀')
            ->setRate(8)
            ->setSymbol('$')
            ->setPosition('left')
            ->setFormat('1,0')
            ->setStatus(true)
            ->save();
    }

    public function getCode(): string
    {
        return $this->getData(self::fields_CODE);
    }

    public function setCode(string $code): self
    {
        return $this->setData(self::fields_CODE, $code);
    }

    public function getName(): string
    {
        return $this->getData(self::fields_NAME);
    }

    public function setName(string $name): self
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getRate(): float
    {
        return $this->getData(self::fields_RATE);
    }

    public function setRate(float $rate): self
    {
        return $this->setData(self::fields_RATE, $rate);
    }

    public function getSymbol(): string
    {
        return $this->getData(self::fields_SYMBOL);
    }

    public function setSymbol(string $symbol): self
    {
        return $this->setData(self::fields_SYMBOL, $symbol);
    }

    public function getPosition(): string
    {
        return $this->getData(self::fields_POSITION) ?? 'left';
    }

    public function setPosition(string $position): self
    {
        return $this->setData(self::fields_POSITION, $position);
    }

    public function getFormat(): string
    {
        return $this->getData(self::fields_FORMAT) ?? '1,0';
    }

    public function setFormat(string $format): self
    {
        # 校验format格式
        $formats = explode(',', $format);
        if (count($formats) != 2) {
            throw new \InvalidArgumentException(__('货币格式错误,示例:2,4'));
        }
        # 都是数字
        foreach ($formats as $format_) {
            if (!is_numeric($format_)) {
                throw new \InvalidArgumentException('货币格式错误,示例:2,4');
            }
        }
        return $this->setData(self::fields_FORMAT, $format);
    }

    public function getStatus(): bool
    {
        return (bool)$this->getData(self::fields_STATUS);
    }

    public function setStatus(bool $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function getCurrency(): string
    {
        return $this->getCode();
    }
}