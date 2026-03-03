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
    public const fields_ICON = 'icon';
    public const fields_THOUSAND_SEPARATOR = 'thousand_separator';
    public const fields_DECIMAL_SEPARATOR = 'decimal_separator';
    public const fields_BASE_CURRENCY = 'base_currency';

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
        if (!$setup->tableExist()) {
            $this->install($setup, $context);
            return;
        }

        // 添加icon字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_ICON)) {
            $setup->alterTable('添加货币图标字段')
                ->addColumn(
                    self::fields_ICON,
                    '',
                    TableInterface::column_type_VARCHAR,
                    255,
                    'null',
                    '货币图标'
                );
            
            // 为已有数据设置默认值：使用symbol字段值
            $this->getConnection()->query("UPDATE {$this->getTable()} SET `" . self::fields_ICON . "` = `" . self::fields_SYMBOL . "`");
        }

        // 添加thousand_separator字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_THOUSAND_SEPARATOR)) {
            $setup->alterTable('添加千分位分隔符字段')
                ->addColumn(
                    self::fields_THOUSAND_SEPARATOR,
                    '',
                    TableInterface::column_type_VARCHAR,
                    10,
                    "not null default ','",
                    '千分位分隔符'
                );
        }

        // 添加decimal_separator字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_DECIMAL_SEPARATOR)) {
            $setup->alterTable('添加小数分隔符字段')
                ->addColumn(
                    self::fields_DECIMAL_SEPARATOR,
                    '',
                    TableInterface::column_type_VARCHAR,
                    10,
                    "not null default '.'",
                    '小数分隔符'
                );
        }

        // 添加base_currency字段
        if (!$setup->getConnection()->getConnector()->hasField($this->getTable(), self::fields_BASE_CURRENCY)) {
            $setup->alterTable('添加基准货币字段')
                ->addColumn(
                    self::fields_BASE_CURRENCY,
                    '',
                    TableInterface::column_type_VARCHAR,
                    3,
                    "not null default 'CNY'",
                    '基准货币代码'
                );
        }
        
        // 确保CNY货币存在且为默认货币
        $cnyCurrency = clone $this;
        $cnyCurrency->clear()
            ->where(self::fields_CODE, 'CNY')
            ->find()
            ->fetch();
        
        if (!$cnyCurrency->getId()) {
            // 如果CNY不存在，创建它
            $this->clear()
                ->setCode('CNY')
                ->setName('人民币')
                ->setRate(1)
                ->setSymbol('￥')
                ->setPosition('left')
                ->setFormat('1,0')
                ->setStatus(true)
                ->setIcon('￥')
                ->setThousandSeparator(',')
                ->setDecimalSeparator('.')
                ->setBaseCurrency('CNY')
                ->save();
        } else {
            // 如果CNY已存在，确保它是基准货币且汇率为1
            $cnyCurrency->setBaseCurrency('CNY')
                ->setRate(1)
                ->setStatus(true)
                ->save();
        }
        
        // 确保配置中的基准货币为CNY
        try {
            /** @var \Weline\Currency\Model\Config $config */
            $config = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Currency\Model\Config::class);
            $currentBaseCurrency = $config->getBaseCurrency();
            if ($currentBaseCurrency !== 'CNY') {
                $config->setBaseCurrency('CNY');
            }
        } catch (\Throwable $e) {
            // 如果Config不可用，忽略错误
        }
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
            ->addColumn(
                self::fields_ICON,
                TableInterface::column_type_VARCHAR,
                255,
                'null',
                '货币图标'
            )
            ->addColumn(
                self::fields_THOUSAND_SEPARATOR,
                TableInterface::column_type_VARCHAR,
                10,
                "not null default ','",
                '千分位分隔符'
            )
            ->addColumn(
                self::fields_DECIMAL_SEPARATOR,
                TableInterface::column_type_VARCHAR,
                10,
                "not null default '.'",
                '小数分隔符'
            )
            ->addColumn(
                self::fields_BASE_CURRENCY,
                TableInterface::column_type_VARCHAR,
                3,
                "not null default 'CNY'",
                '基准货币代码'
            )
            ->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_currency_code',
                self::fields_CODE,
                '货币代码索引'
            )
            ->create();

        # 设置默认货币（CNY - 人民币）
        // 先检查CNY是否已存在，如果不存在则创建
        $cnyCurrency = clone $this;
        $cnyCurrency->clear()
            ->where(self::fields_CODE, 'CNY')
            ->find()
            ->fetch();
        
        if (!$cnyCurrency->getId()) {
            $this->clear()
                ->setCode('CNY')
                ->setName('人民币')
                ->setRate(1)
                ->setSymbol('￥')
                ->setPosition('left')
                ->setFormat('1,0')
                ->setStatus(true)
                ->setIcon('￥')
                ->setThousandSeparator(',')
                ->setDecimalSeparator('.')
                ->setBaseCurrency('CNY')
                ->save();
        } else {
            // 如果CNY已存在，确保它是基准货币
            $cnyCurrency->setBaseCurrency('CNY')
                ->setRate(1)
                ->setStatus(true)
                ->save();
        }
        
        # 设置USD货币（可选）
        $usdCurrency = clone $this;
        $usdCurrency->clear()
            ->where(self::fields_CODE, 'USD')
            ->find()
            ->fetch();
        
        if (!$usdCurrency->getId()) {
            $this->clear()
                ->setCode('USD')
                ->setName('美刀')
                ->setRate(8)
                ->setSymbol('$')
                ->setPosition('left')
                ->setFormat('1,0')
                ->setStatus(true)
                ->setIcon('$')
                ->setThousandSeparator(',')
                ->setDecimalSeparator('.')
                ->setBaseCurrency('CNY')
                ->save();
        }
        
        # 确保配置中的基准货币为CNY
        try {
            /** @var \Weline\Currency\Model\Config $config */
            $config = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Currency\Model\Config::class);
            $currentBaseCurrency = $config->getBaseCurrency();
            if ($currentBaseCurrency !== 'CNY') {
                $config->setBaseCurrency('CNY');
            }
        } catch (\Throwable $e) {
            // 如果Config不可用，忽略错误（可能在安装阶段）
        }
    }

    public function getCode(): string
    {
        return (string)($this->getData(self::fields_CODE) ?? '');
    }

    public function setCode(string $code): self
    {
        return $this->setData(self::fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)($this->getData(self::fields_NAME) ?? '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getRate(): float
    {
        return (float)($this->getData(self::fields_RATE) ?? 0.0);
    }

    public function setRate(float $rate): self
    {
        return $this->setData(self::fields_RATE, $rate);
    }

    public function getSymbol(): string
    {
        return (string)($this->getData(self::fields_SYMBOL) ?? '');
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

    public function getIcon(): ?string
    {
        return $this->getData(self::fields_ICON);
    }

    public function setIcon(?string $icon): self
    {
        return $this->setData(self::fields_ICON, $icon);
    }

    public function getThousandSeparator(): string
    {
        return $this->getData(self::fields_THOUSAND_SEPARATOR) ?? ',';
    }

    public function setThousandSeparator(string $separator): self
    {
        if (empty($separator)) {
            throw new \InvalidArgumentException(__('千分位分隔符不能为空'));
        }
        return $this->setData(self::fields_THOUSAND_SEPARATOR, $separator);
    }

    public function getDecimalSeparator(): string
    {
        return $this->getData(self::fields_DECIMAL_SEPARATOR) ?? '.';
    }

    public function setDecimalSeparator(string $separator): self
    {
        if (empty($separator)) {
            throw new \InvalidArgumentException(__('小数分隔符不能为空'));
        }
        return $this->setData(self::fields_DECIMAL_SEPARATOR, $separator);
    }

    public function getBaseCurrency(): string
    {
        return $this->getData(self::fields_BASE_CURRENCY) ?? 'CNY';
    }

    public function setBaseCurrency(string $baseCurrency): self
    {
        if (strlen($baseCurrency) !== 3 || !ctype_upper($baseCurrency)) {
            throw new \InvalidArgumentException(__('基准货币代码必须是3位大写字母'));
        }
        return $this->setData(self::fields_BASE_CURRENCY, $baseCurrency);
    }

    /**
     * 格式化货币金额
     * 
     * @param float $amount 金额
     * @return string 格式化后的金额字符串
     */
    public function formatAmount(float $amount): string
    {
        // 解析format格式：小数位数,整数位数
        $format = $this->getFormat();
        $formats = explode(',', $format);
        $decimals = isset($formats[0]) ? (int)$formats[0] : 2;
        $integerDigits = isset($formats[1]) ? (int)$formats[1] : 0;

        // 获取分隔符
        $thousandSeparator = $this->getThousandSeparator();
        $decimalSeparator = $this->getDecimalSeparator();

        // 格式化数字
        $formatted = number_format($amount, $decimals, $decimalSeparator, $thousandSeparator);

        // 添加货币符号
        $symbol = $this->getSymbol();
        $position = $this->getPosition();
        $icon = $this->getIcon() ?? $symbol;

        if ($position === 'right') {
            return $formatted . $icon;
        } else {
            return $icon . $formatted;
        }
    }

    /**
     * 验证货币数据
     * 
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function validate(): bool
    {
        // 验证货币代码
        $code = $this->getCode();
        if (empty($code) || strlen($code) !== 3 || !ctype_upper($code)) {
            throw new \InvalidArgumentException(__('货币代码必须是3位大写字母（ISO 4217标准）'));
        }

        // 验证货币名称
        if (empty($this->getName())) {
            throw new \InvalidArgumentException(__('货币名称不能为空'));
        }

        // 验证汇率
        $rate = $this->getRate();
        if ($rate <= 0 || $rate > 1000000) {
            throw new \InvalidArgumentException(__('汇率必须在0到1000000之间'));
        }

        // 验证符号位置
        $position = $this->getPosition();
        if (!in_array($position, ['left', 'right'])) {
            throw new \InvalidArgumentException(__('货币符号位置必须是left或right'));
        }

        // 验证分隔符
        if (empty($this->getThousandSeparator())) {
            throw new \InvalidArgumentException(__('千分位分隔符不能为空'));
        }
        if (empty($this->getDecimalSeparator())) {
            throw new \InvalidArgumentException(__('小数分隔符不能为空'));
        }

        // 验证基准货币代码
        $baseCurrency = $this->getBaseCurrency();
        if (!empty($baseCurrency) && (strlen($baseCurrency) !== 3 || !ctype_upper($baseCurrency))) {
            throw new \InvalidArgumentException(__('基准货币代码必须是3位大写字母'));
        }

        return true;
    }

    /**
     * 保存前验证
     * 
     * @return $this
     */
    public function beforeSave()
    {
        $this->validate();
        return parent::beforeSave();
    }

    /**
     * 保存后清除货币缓存
     * 当货币数据更新时，清除缓存的货币列表，确保下次请求时重新加载最新数据
     */
    public function save_after()
    {
        parent::save_after();
        // 清除货币缓存
        try {
            w_cache('currency')->clear();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
        }
    }
}