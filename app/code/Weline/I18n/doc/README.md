# Weline I18n 国际化翻译模块

## 模块概述

Weline I18n 是系统的国际化翻译模块，提供了完整的多语言支持功能。该模块支持语言包管理、自动翻译、本地化模型、国家地区管理等功能，为系统提供企业级的国际化解决方案。

## 主要功能

### 1. 语言包管理
- 多语言包支持
- 语言包安装/卸载
- 语言包更新
- 语言包缓存

### 2. 翻译功能
- 自动翻译检测
- 翻译词条管理
- 翻译缓存机制
- 翻译文件生成

### 3. 本地化模型
- 多语言数据模型
- 本地化字段支持
- 语言切换机制
- 数据本地化存储

### 4. 国家地区管理
- 国家信息管理
- 地区信息管理
- 国旗显示支持
- 时区管理

### 5. 语言切换
- 用户语言偏好
- Cookie 语言设置
- 动态语言切换
- 语言检测

## 使用方法

### 基本翻译使用
```php
use Weline\I18n\Model\I18n;

$i18n = new I18n();

// 获取当前语言
$currentLocale = Cookie::getLang();

// 获取支持的语言列表
$locales = $i18n->getLocals('zh_Hans_CN');

// 获取语言名称
$localeName = $i18n->getLocaleName('en_US', 'zh_Hans_CN');

// 检查语言是否存在
$exists = $i18n->localeExists('en_US');
```

### 语言包管理
```php
use Weline\I18n\Model\I18n;

$i18n = new I18n();

// 获取已安装的语言包
$installedLocales = $i18n->getLocalesWithFlags(42, 32, 'zh_Hans_CN', true);

// 获取所有支持的语言包
$allLocales = $i18n->getLocalesWithFlags(42, 32, 'zh_Hans_CN', false);

// 获取带国旗的语言包
$localesWithFlags = $i18n->getLocalesWithFlagsDisplaySelf('zh_Hans_CN', 42, 32, true);

// 获取国家信息
$countries = $i18n->getCountries('zh_Hans_CN');

// 获取国家国旗
$flag = $i18n->getCountryFlag('CN', 42, 32);
```

### 翻译词条管理
```php
use Weline\I18n\Model\I18n;

$i18n = new I18n();

// 获取所有翻译词条
$words = $i18n->getLocalsWords();

// 获取特定语言的翻译词条
$zhWords = $i18n->getLocalWords('zh_Hans_CN');
$enWords = $i18n->getLocalWords('en_US');

// 收集翻译词条
$collectedWords = $i18n->getCollectedWords();

// 转换为语言文件
$i18n->convertToLanguageFile();
```

### 本地化模型使用
```php
namespace Your\Module\Model;

use Weline\I18n\TraitLocalModel;
use Weline\I18n\LocalModelInterface;

class Product implements LocalModelInterface
{
    use TraitLocalModel;
    
    public const fields_ID = 'product_id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    
    public function setup(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('产品表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '产品ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '产品名称')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, '', '产品描述')
                ->create();
        }
    }
    
    /**
     * 创建多语言产品
     */
    public function createMultilingualProduct($data)
    {
        $locales = ['zh_Hans_CN', 'en_US', 'ja_JP'];
        
        foreach ($locales as $locale) {
            $product = new self();
            $product->setLocalCode($locale)
                ->setName($data['name_' . $locale])
                ->setDescription($data['description_' . $locale])
                ->save();
        }
    }
    
    /**
     * 获取多语言产品
     */
    public function getMultilingualProduct($productId)
    {
        $products = $this->clear()
            ->where(self::fields_ID, $productId)
            ->select()
            ->fetchArray();
            
        $result = [];
        foreach ($products as $product) {
            $result[$product['local_code']] = $product;
        }
        
        return $result;
    }
}
```

### 语言切换功能
```php
use Weline\Framework\Http\Cookie;
use Weline\I18n\Model\I18n;

class LanguageController
{
    public function switchLanguage()
    {
        $locale = $this->request->getParam('locale', 'zh_Hans_CN');
        
        // 验证语言是否支持
        $i18n = new I18n();
        if (!$i18n->localeExists($locale)) {
            $locale = 'zh_Hans_CN'; // 默认语言
        }
        
        // 设置语言 Cookie
        Cookie::setLang($locale);
        
        // 重定向到原页面
        $redirectUrl = $this->request->getParam('redirect', '/');
        $this->redirect($redirectUrl);
    }
    
    public function getCurrentLanguage()
    {
        $locale = Cookie::getLang();
        $i18n = new I18n();
        $localeName = $i18n->getLocaleName($locale);
        
        return [
            'code' => $locale,
            'name' => $localeName,
            'flag' => $i18n->getCountryFlagWithLocal($locale)
        ];
    }
}
```

### 翻译标签使用
```html
<!-- 在模板中使用翻译 -->
<h1>{__('欢迎使用系统')}</h1>
<p>{__('当前时间：%{1}', date('Y-m-d H:i:s'))}</p>

<!-- 条件翻译 -->
{if $locale == 'zh_Hans_CN'}
    <p>中文内容</p>
{elseif $locale == 'en_US'}
    <p>English Content</p>
{else}
    <p>Default Content</p>
{/if}

<!-- 多语言内容显示 -->
<div class="product-name">
    {$product->getName()}
</div>
<div class="product-description">
    {$product->getDescription()}
</div>
```

## 配置说明

### I18n 配置
在 `app/etc/i18n.php` 中配置国际化相关设置：

```php
'i18n' => [
    'default_locale' => 'zh_Hans_CN',
    'supported_locales' => [
        'zh_Hans_CN' => '简体中文',
        'en_US' => 'English',
        'ja_JP' => '日本語'
    ],
    'auto_detect' => true,
    'cache' => true,
    'cache_time' => 3600,
    'fallback_locale' => 'zh_Hans_CN'
]
```

### 语言包配置
```php
'language_packs' => [
    'zh_Hans_CN' => [
        'name' => '简体中文',
        'flag' => 'CN',
        'direction' => 'ltr',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s'
    ],
    'en_US' => [
        'name' => 'English',
        'flag' => 'US',
        'direction' => 'ltr',
        'date_format' => 'm/d/Y',
        'time_format' => 'h:i:s A'
    ]
]
```

## 依赖关系

- Weline_Framework
- Symfony/Intl
- Rinvex/Country

## 版本信息

- 当前版本：1.0.1
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 语言包结构

### 语言包目录结构
```
app/i18n/
├── Weline/
│   ├── zh_Hans_CN/
│   │   ├── translation.csv
│   │   └── translation.php
│   ├── en_US/
│   │   ├── translation.csv
│   │   └── translation.php
│   └── ja_JP/
│       ├── translation.csv
│       └── translation.php
└── Your_Module/
    ├── zh_Hans_CN/
    │   └── translation.csv
    └── en_US/
        └── translation.csv
```

### 翻译文件格式
```csv
# translation.csv
"key","zh_Hans_CN","en_US","ja_JP"
"welcome","欢迎","Welcome","ようこそ"
"hello","你好","Hello","こんにちは"
"goodbye","再见","Goodbye","さようなら"
```

```php
// translation.php
return [
    'welcome' => '欢迎',
    'hello' => '你好',
    'goodbye' => '再见',
    'user' => [
        'name' => '姓名',
        'email' => '邮箱',
        'password' => '密码'
    ]
];
```

## 本地化模型详解

### 使用 TraitLocalModel
```php
use Weline\I18n\TraitLocalModel;

class YourModel extends Model
{
    use TraitLocalModel;
    
    // 模型字段定义
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 自动创建多语言表结构
        $this->install($setup, $context);
    }
}
```

### 多语言数据操作
```php
// 创建多语言数据
$model = new YourModel();
$model->setLocalCode('zh_Hans_CN')
    ->setName('中文名称')
    ->setDescription('中文描述')
    ->save();

$model = new YourModel();
$model->setLocalCode('en_US')
    ->setName('English Name')
    ->setDescription('English Description')
    ->save();

// 查询多语言数据
$model = new YourModel();
$model->load(1); // 根据当前语言加载数据

// 获取所有语言版本
$allVersions = $model->clear()
    ->where(self::fields_ID, 1)
    ->select()
    ->fetchArray();
```

## 翻译工具

### 翻译收集
```php
use Weline\I18n\Observer\ParserWordsRegister;

// 自动收集翻译词条
$parser = new ParserWordsRegister();
$words = $parser->collectWords();

// 手动添加翻译词条
$i18n = new I18n();
$i18n->addTranslation('custom_key', [
    'zh_Hans_CN' => '自定义键',
    'en_US' => 'Custom Key'
]);
```

### 翻译导出
```php
// 导出翻译文件
$i18n = new I18n();
$i18n->exportTranslations('zh_Hans_CN', 'path/to/export/');

// 导入翻译文件
$i18n->importTranslations('zh_Hans_CN', 'path/to/import/');
```

## 高级功能

### 动态翻译
```php
// 运行时翻译
$translatedText = __($key, $params, $locale);

// 条件翻译
$text = $locale == 'zh_Hans_CN' ? '中文' : 'English';

// 复数翻译
$count = 5;
$text = $count == 1 ? __('1 item') : __('%{1} items', $count);
```

### 格式化翻译
```php
// 日期格式化
$date = new DateTime();
$formattedDate = $date->format($this->getDateFormat($locale));

// 数字格式化
$number = 1234.56;
$formattedNumber = number_format($number, 2, $this->getDecimalSeparator($locale), $this->getThousandsSeparator($locale));

// 货币格式化
$amount = 99.99;
$formattedAmount = $this->formatCurrency($amount, $locale);
```

## 性能优化

### 1. 缓存策略
- 启用翻译缓存
- 合理设置缓存时间
- 及时清理过期缓存

### 2. 语言包优化
- 按需加载语言包
- 压缩翻译文件
- 使用 CDN 加速

### 3. 数据库优化
- 为本地化字段创建索引
- 优化多语言查询
- 使用读写分离

## 最佳实践

### 1. 翻译管理
- 统一翻译键命名规范
- 使用翻译上下文
- 定期更新翻译文件

### 2. 本地化设计
- 考虑文本长度差异
- 支持不同书写方向
- 适配不同日期格式

### 3. 用户体验
- 记住用户语言偏好
- 提供语言切换入口
- 支持自动语言检测

### 4. 维护性
- 版本控制翻译文件
- 建立翻译审核流程
- 定期清理无用翻译

## 常见问题

### Q: 如何添加新的语言包？
A: 在 `app/i18n/` 目录下创建新的语言目录，添加翻译文件。

### Q: 如何处理翻译缺失？
A: 使用默认语言作为回退，或实现翻译缺失检测和提醒。

### Q: 如何优化翻译性能？
A: 启用缓存、按需加载、使用 CDN 等方式提升性能。

### Q: 如何处理复数形式？
A: 使用条件判断或专门的复数翻译函数处理不同语言的复数规则。

### Q: 如何支持 RTL 语言？
A: 在语言包配置中设置 `direction` 为 `rtl`，并在前端适配 RTL 样式。 