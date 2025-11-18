# WebsiteData 类使用文档

## 一、概述

`WebsiteData` 是一个静态数据类，用于提供当前请求命中的网站数据。该类在网站检测观察者（`DetectWebsite`）中被自动初始化，为其他模块提供便捷的网站数据访问接口。

### 类位置
```
Weline\Websites\Data\WebsiteData
```

### 主要功能
- 存储和管理当前请求命中的网站数据
- 提供网站基本信息的快速访问
- 提供关联货币和语言的查询接口
- 提供货币详细信息（包含format、symbol等）的访问
- 提供货币和语言的验证功能

### 使用场景
- 在控制器中获取当前网站信息
- 在服务类中获取网站配置
- 在模板中获取网站数据
- 验证货币和语言是否被当前网站允许
- 获取货币格式信息用于价格显示

## 二、基本使用

### 1. 引入类

```php
use Weline\Websites\Data\WebsiteData;
```

### 2. 获取网站基本信息

```php
// 获取网站ID
$websiteId = WebsiteData::getWebsiteId();

// 获取网站代码
$code = WebsiteData::getCode();

// 获取网站名称
$name = WebsiteData::getName();

// 获取网站URL
$url = WebsiteData::getUrl();

// 获取默认货币代码
$defaultCurrency = WebsiteData::getDefaultCurrency();

// 获取默认语言代码
$defaultLanguage = WebsiteData::getDefaultLanguage();

// 获取默认时区
$timezone = WebsiteData::getDefaultTimezone();
```

### 3. 获取网站实例

```php
// 获取完整的网站模型实例
$website = WebsiteData::getWebsite();

if ($website) {
    // 可以调用Website模型的所有方法
    $websiteId = $website->getWebsiteId();
    $name = $website->getName();
}
```

## 三、货币相关方法

### 1. 获取货币Format格式

```php
// 获取默认货币的format格式
$format = WebsiteData::getCurrencyFormat();
// 返回: "1,0" 或 null

// 获取指定货币的format格式
$format = WebsiteData::getCurrencyFormat('CNY');
// 返回: "1,0" 或 null
```

**Format格式说明**：
- Format格式为 "小数位数,整数位数"，例如 "1,0" 表示小数1位，整数不限制
- 用于格式化货币显示，如价格格式化

### 2. 获取货币符号

```php
// 获取默认货币的符号
$symbol = WebsiteData::getCurrencySymbol();
// 返回: "￥" 或 null

// 获取指定货币的符号
$symbol = WebsiteData::getCurrencySymbol('USD');
// 返回: "$" 或 null
```

### 3. 获取货币符号位置

```php
// 获取默认货币的符号位置
$position = WebsiteData::getCurrencyPosition();
// 返回: "left" 或 "right" 或 null

// 获取指定货币的符号位置
$position = WebsiteData::getCurrencyPosition('EUR');
// 返回: "left" 或 "right" 或 null
```

**符号位置说明**：
- `left`: 符号在金额左侧，如 "￥100"
- `right`: 符号在金额右侧，如 "100€"

### 4. 获取货币汇率

```php
// 获取默认货币的汇率
$rate = WebsiteData::getCurrencyRate();
// 返回: 8.0 (float) 或 null

// 获取指定货币的汇率
$rate = WebsiteData::getCurrencyRate('USD');
// 返回: 8.0 (float) 或 null
```

### 5. 获取货币详细信息

```php
// 获取默认货币的完整信息
$currency = WebsiteData::getCurrency();
// 返回: [
//     'code' => 'CNY',
//     'name' => '人民币',
//     'format' => '1,0',
//     'symbol' => '￥',
//     'position' => 'left',
//     'rate' => 1.0,
//     'status' => true
// ] 或 null

// 获取指定货币的完整信息
$currency = WebsiteData::getCurrency('USD');
```

### 6. 获取所有关联货币

```php
// 获取网站关联的所有货币列表
$currencies = WebsiteData::getCurrencies();
// 返回: [
//     [
//         'code' => 'CNY',
//         'name' => '人民币',
//         'format' => '1,0',
//         'symbol' => '￥',
//         'position' => 'left',
//         'rate' => 1.0,
//         'status' => true
//     ],
//     [
//         'code' => 'USD',
//         'name' => '美元',
//         'format' => '2,0',
//         'symbol' => '$',
//         'position' => 'left',
//         'rate' => 8.0,
//         'status' => true
//     ],
//     ...
// ]

// 遍历货币列表
foreach ($currencies as $currency) {
    echo $currency['code'];      // 货币代码
    echo $currency['name'];      // 货币名称
    echo $currency['format'];    // 格式
    echo $currency['symbol'];    // 符号
    echo $currency['position'];  // 位置
    echo $currency['rate'];      // 汇率
}
```

### 7. 获取关联货币代码列表

```php
// 获取网站关联的货币代码列表
$currencyCodes = WebsiteData::getCurrencyCodes();
// 返回: ['CNY', 'USD', 'EUR'] 或 []

// 如果网站没有限定关联货币，返回空数组
// 此时系统允许使用所有启用的货币
```

### 8. 验证货币是否允许

```php
// 验证货币是否被当前网站允许
$isAllowed = WebsiteData::isCurrencyAllowed('CNY');
// 返回: true 或 false

// 验证逻辑：
// 1. 如果网站限定了关联货币，只允许这些货币
// 2. 如果网站没有限定关联货币，允许所有货币表中的启用货币
```

## 四、语言相关方法

### 1. 获取关联语言代码列表

```php
// 获取网站关联的语言代码列表
$languageCodes = WebsiteData::getLanguageCodes();
// 返回: ['zh_Hans_CN', 'en_US', 'ja_JP'] 或 []

// 如果网站没有限定关联语言，返回空数组
// 此时系统允许使用所有i18n支持的激活语言
```

### 2. 验证语言是否允许

```php
// 验证语言是否被当前网站允许
$isAllowed = WebsiteData::isLanguageAllowed('zh_Hans_CN');
// 返回: true 或 false

// 验证逻辑：
// 1. 如果网站限定了关联语言，只允许这些语言
// 2. 如果网站没有限定关联语言，允许所有i18n支持的激活语言
```

## 五、完整数据获取

### 获取完整网站数据

```php
// 获取当前网站的完整数据数组
$data = WebsiteData::getData();
// 返回: [
//     'website_id' => 1,
//     'code' => 'default',
//     'name' => '默认网站',
//     'url' => 'http://localhost',
//     'default_currency' => 'CNY',
//     'default_language' => 'zh_Hans_CN',
//     'default_timezone' => 'Asia/Shanghai',
//     'currency_codes' => ['CNY', 'USD'],
//     'language_codes' => ['zh_Hans_CN', 'en_US'],
//     'currencies' => [
//         [
//             'code' => 'CNY',
//             'name' => '人民币',
//             'format' => '1,0',
//             'symbol' => '￥',
//             'position' => 'left',
//             'rate' => 1.0,
//             'status' => true
//         ],
//         ...
//     ]
// ] 或 null
```

## 六、实际应用示例

### 示例1：在控制器中格式化价格

```php
use Weline\Websites\Data\WebsiteData;

class ProductController
{
    public function showPrice()
    {
        $price = 99.99;
        $currencyCode = WebsiteData::getDefaultCurrency();
        
        // 获取货币信息
        $currency = WebsiteData::getCurrency($currencyCode);
        
        if ($currency) {
            $symbol = $currency['symbol'];
            $position = $currency['position'];
            $format = $currency['format'];
            
            // 格式化价格
            $formattedPrice = $this->formatPrice($price, $symbol, $position, $format);
            // 结果: "￥99.9" 或 "$99.99"
        }
    }
    
    private function formatPrice($price, $symbol, $position, $format)
    {
        // 解析format
        list($decimals, $integer) = explode(',', $format);
        
        // 格式化数字
        $formatted = number_format($price, (int)$decimals);
        
        // 添加符号
        if ($position === 'left') {
            return $symbol . $formatted;
        } else {
            return $formatted . $symbol;
        }
    }
}
```

### 示例2：验证用户选择的货币

```php
use Weline\Websites\Data\WebsiteData;

class CurrencyController
{
    public function switchCurrency($currencyCode)
    {
        // 验证货币是否被当前网站允许
        if (!WebsiteData::isCurrencyAllowed($currencyCode)) {
            return [
                'success' => false,
                'message' => '该货币不被当前网站支持'
            ];
        }
        
        // 保存用户选择的货币
        Cookie::set('WELINE_USER_CURRENCY', $currencyCode);
        
        return [
            'success' => true,
            'message' => '货币切换成功'
        ];
    }
}
```

### 示例3：在模板中显示货币列表

```php
<?php
use Weline\Websites\Data\WebsiteData;

$currencies = WebsiteData::getCurrencies();
$currentCurrency = WebsiteData::getDefaultCurrency();
?>

<select name="currency">
    <?php foreach ($currencies as $currency): ?>
        <option value="<?= $currency['code'] ?>" 
                <?= $currency['code'] === $currentCurrency ? 'selected' : '' ?>>
            <?= $currency['symbol'] ?> <?= $currency['name'] ?>
        </option>
    <?php endforeach; ?>
</select>
```

### 示例4：获取网站配置信息

```php
use Weline\Websites\Data\WebsiteData;

class ConfigService
{
    public function getWebsiteConfig()
    {
        $websiteId = WebsiteData::getWebsiteId();
        
        if (!$websiteId) {
            return null;
        }
        
        return [
            'website_id' => $websiteId,
            'code' => WebsiteData::getCode(),
            'name' => WebsiteData::getName(),
            'url' => WebsiteData::getUrl(),
            'timezone' => WebsiteData::getDefaultTimezone(),
            'default_currency' => WebsiteData::getDefaultCurrency(),
            'default_language' => WebsiteData::getDefaultLanguage(),
            'available_currencies' => WebsiteData::getCurrencyCodes(),
            'available_languages' => WebsiteData::getLanguageCodes(),
        ];
    }
}
```

## 七、注意事项

### 1. 网站未检测到的情况

如果当前请求没有匹配到任何网站，`WebsiteData` 的所有方法都会返回 `null` 或空数组。建议在使用前进行检查：

```php
$websiteId = WebsiteData::getWebsiteId();
if ($websiteId) {
    // 网站已检测到，可以安全使用
} else {
    // 网站未检测到，使用默认值或提示错误
}
```

### 2. 数据缓存

`WebsiteData` 类内部使用了静态变量缓存数据，在同一个请求中多次调用相同方法不会重复查询数据库，提高了性能。

### 3. 数据重置

在测试或特殊场景下，可以调用 `reset()` 方法重置所有数据：

```php
WebsiteData::reset();
```

### 4. 货币和语言的验证规则

- **关联货币为空**：允许所有货币表中的启用货币
- **关联货币不为空**：只允许关联的货币
- **关联语言为空**：允许所有i18n支持的激活语言
- **关联语言不为空**：只允许关联的语言

### 5. 货币Format格式

Format格式为 "小数位数,整数位数"：
- `"1,0"`: 小数1位，整数不限制，如 "99.9"
- `"2,0"`: 小数2位，整数不限制，如 "99.99"
- `"0,0"`: 无小数，整数不限制，如 "100"

## 八、API参考

### 网站基本信息方法

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `getWebsite()` | `Website\|null` | 获取网站模型实例 |
| `getWebsiteId()` | `int\|null` | 获取网站ID |
| `getCode()` | `string\|null` | 获取网站代码 |
| `getName()` | `string\|null` | 获取网站名称 |
| `getUrl()` | `string\|null` | 获取网站URL |
| `getDefaultCurrency()` | `string\|null` | 获取默认货币代码 |
| `getDefaultLanguage()` | `string\|null` | 获取默认语言代码 |
| `getDefaultTimezone()` | `string\|null` | 获取默认时区 |

### 货币相关方法

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `getCurrencyFormat(?string $currencyCode = null)` | `string\|null` | 获取货币format格式 |
| `getCurrency(?string $currencyCode = null)` | `array\|null` | 获取货币详细信息 |
| `getCurrencySymbol(?string $currencyCode = null)` | `string\|null` | 获取货币符号 |
| `getCurrencyPosition(?string $currencyCode = null)` | `string\|null` | 获取货币符号位置 |
| `getCurrencyRate(?string $currencyCode = null)` | `float\|null` | 获取货币汇率 |
| `getCurrencies()` | `array` | 获取所有关联货币 |
| `getCurrencyCodes()` | `array` | 获取关联货币代码列表 |
| `isCurrencyAllowed(string $currencyCode)` | `bool` | 验证货币是否允许 |

### 语言相关方法

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `getLanguageCodes()` | `array` | 获取关联语言代码列表 |
| `isLanguageAllowed(string $languageCode)` | `bool` | 验证语言是否允许 |

### 数据方法

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `getData()` | `array\|null` | 获取完整网站数据 |
| `reset()` | `void` | 重置所有数据 |
| `setWebsite(Website $website)` | `void` | 设置当前网站（内部使用） |

## 九、常见问题

### Q1: 为什么获取不到网站数据？

**A**: 可能的原因：
1. 当前请求的URL没有匹配到任何网站
2. 网站检测观察者还没有执行
3. 在网站检测之前就调用了 `WebsiteData` 的方法

**解决方案**：确保在网站检测之后使用，或添加空值检查。

### Q2: 如何判断网站是否已检测到？

**A**: 使用以下方法：

```php
$websiteId = WebsiteData::getWebsiteId();
if ($websiteId) {
    // 网站已检测到
} else {
    // 网站未检测到
}
```

### Q3: 货币Format格式如何使用？

**A**: Format格式用于格式化价格显示：

```php
$format = WebsiteData::getCurrencyFormat('CNY'); // "1,0"
list($decimals, $integer) = explode(',', $format);
$formatted = number_format($price, (int)$decimals);
```

### Q4: 如何获取所有可用的货币？

**A**: 使用 `getCurrencies()` 方法：

```php
$currencies = WebsiteData::getCurrencies();
foreach ($currencies as $currency) {
    // 处理每个货币
}
```

### Q5: 验证货币失败怎么办？

**A**: 如果 `isCurrencyAllowed()` 返回 `false`，说明该货币不被当前网站允许。应该：
1. 提示用户该货币不可用
2. 切换到默认货币或其他可用货币
3. 检查网站配置是否正确

## 十、更新日志

### v1.0.0 (2025-11-14)
- 初始版本
- 实现网站基本信息获取
- 实现货币和语言验证
- 实现货币详细信息获取（包含format、symbol等）
- 添加便捷的货币format获取方法

## 十一、相关文档

- [Websites模块需求文档](../需求文档.md)
- [Websites模块FIXME文档](../FIXME.md)
- [Currency模块文档](../../Currency/doc/)
- [I18n模块文档](../../I18n/doc/)

