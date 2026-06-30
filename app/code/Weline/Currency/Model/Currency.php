<?php

namespace Weline\Currency\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '货币表')]
#[Index(name: 'idx_currency_code', columns: ['code'], type: 'UNIQUE')]
class Currency extends Model
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'currency_id';
    #[Col(type: 'varchar', length: 3, nullable: false, comment: '货币代码')]
    public const schema_fields_CODE = 'code';
    #[Col(type: 'varchar', length: 20, nullable: false, comment: '货币名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'decimal', length: '10,4', nullable: false, comment: '汇率')]
    public const schema_fields_RATE = 'rate';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币符号')]
    public const schema_fields_SYMBOL = 'symbol';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币符号位置')]
    public const schema_fields_POSITION = 'position';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币格式')]
    public const schema_fields_FORMAT = 'format';
    #[Col(type: 'varchar', length: 10, nullable: false, comment: '货币状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '货币图标')]
    public const schema_fields_ICON = 'icon';
    #[Col(type: 'varchar', length: 10, nullable: false, default: ',', comment: '千分位分隔符')]
    public const schema_fields_THOUSAND_SEPARATOR = 'thousand_separator';
    #[Col(type: 'varchar', length: 10, nullable: false, default: '.', comment: '小数分隔符')]
    public const schema_fields_DECIMAL_SEPARATOR = 'decimal_separator';
    #[Col(type: 'varchar', length: 3, nullable: false, default: 'CNY', comment: '基准货币代码')]
    public const schema_fields_BASE_CURRENCY = 'base_currency';

    public function getCode(): string
    {
        return (string)($this->getData(self::schema_fields_CODE) ?? '');
    }

    public function setCode(string $code): self
    {
        return $this->setData(self::schema_fields_CODE, $code);
    }

    public function getName(): string
    {
        return (string)($this->getData(self::schema_fields_NAME) ?? '');
    }

    public function setName(string $name): self
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }

    public function getRate(): float
    {
        return (float)($this->getData(self::schema_fields_RATE) ?? 0.0);
    }

    public function setRate(float $rate): self
    {
        return $this->setData(self::schema_fields_RATE, $rate);
    }

    public function getSymbol(): string
    {
        return (string)($this->getData(self::schema_fields_SYMBOL) ?? '');
    }

    public function setSymbol(string $symbol): self
    {
        return $this->setData(self::schema_fields_SYMBOL, $symbol);
    }

    public function getPosition(): string
    {
        return $this->getData(self::schema_fields_POSITION) ?? 'left';
    }

    public function setPosition(string $position): self
    {
        return $this->setData(self::schema_fields_POSITION, $position);
    }

    public function getFormat(): string
    {
        return $this->getData(self::schema_fields_FORMAT) ?? '1,0';
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
        return $this->setData(self::schema_fields_FORMAT, $format);
    }

    public function getStatus(): bool
    {
        return (bool)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(bool $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getCurrency(): string
    {
        return $this->getCode();
    }

    public function getIcon(): ?string
    {
        return $this->getData(self::schema_fields_ICON);
    }

    public function setIcon(?string $icon): self
    {
        return $this->setData(self::schema_fields_ICON, $icon);
    }

    public function getThousandSeparator(): string
    {
        return $this->getData(self::schema_fields_THOUSAND_SEPARATOR) ?? ',';
    }

    public function setThousandSeparator(string $separator): self
    {
        if (empty($separator)) {
            throw new \InvalidArgumentException(__('千分位分隔符不能为空'));
        }
        return $this->setData(self::schema_fields_THOUSAND_SEPARATOR, $separator);
    }

    public function getDecimalSeparator(): string
    {
        return $this->getData(self::schema_fields_DECIMAL_SEPARATOR) ?? '.';
    }

    public function setDecimalSeparator(string $separator): self
    {
        if (empty($separator)) {
            throw new \InvalidArgumentException(__('小数分隔符不能为空'));
        }
        return $this->setData(self::schema_fields_DECIMAL_SEPARATOR, $separator);
    }

    public function getBaseCurrency(): string
    {
        return $this->getData(self::schema_fields_BASE_CURRENCY) ?? 'CNY';
    }

    public function setBaseCurrency(string $baseCurrency): self
    {
        if (strlen($baseCurrency) !== 3 || !ctype_upper($baseCurrency)) {
            throw new \InvalidArgumentException(__('基准货币代码必须是3位大写字母'));
        }
        return $this->setData(self::schema_fields_BASE_CURRENCY, $baseCurrency);
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
            \Weline\Framework\Http\Url::bumpWebsiteParserSitesVersion();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
        }
    }
}
