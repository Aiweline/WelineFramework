# Weline I18n 国际化翻译模块

## 模块概述

Weline I18n 是系统的国际化翻译模块，提供了完整的多语言支持功能。该模块支持语言包管理、自动翻译、本地化模型、国家地区管理等功能，为系统提供企业级的国际化解决方案。

## 跨模块公共契约

其他模块只能引用 `Weline\I18n\Api\*`。禁止直接引用 I18n 的 `Model`、`Service`、`Helper`，也不要通过
`Weline_I18n::query` 事件绕过 PHP 契约。当前稳定边界如下：

| 契约 | 用途 |
|---|---|
| `Api\Translation\DictionaryRepositoryInterface` | 按词和 locale 读取、批量读取、前缀枚举、写入与精确删除；返回不可变 `DictionaryEntry` |
| `Api\Translation\TranslationCollectorInterface` | 从模块目录收集翻译源串 |
| `Api\Translation\TranslationResolverInterface` | 按 locale 和首选模块语言包解析显示文案 |
| `Api\Localization\LocaleCatalogInterface` | 获取全部 locale 或已安装且启用的 locale/名称/国旗 |
| `Api\Localization\LocaleNameCatalogInterface` | 以不可变 `LocaleNameRecord` 读取 locale 展示名称行、首条匹配和存在性 |
| `Api\Localization\LocaleRepositoryInterface` | 以不可变 `LocaleRecord` 读取已安装启用语言，并规范化 locale 别名 |
| `Api\Localization\CountryRepositoryInterface` | 以不可变 `CountryRecord` 读取已安装国家或已安装启用国家 |
| `Api\Localization\LanguageCodeConverter` | ISO 639-1/639-2/BCP 47 语言代码的无状态转换 |
| `Api\Javascript\JavascriptModuleConfigProviderInterface` | 模块提交 `weline.modules.js` 数据源，不把主题实现反向注入 I18n |

Repository 只返回不可变 DTO，不暴露 I18n ORM Model、Query Builder、字段常量或分页对象。实现由
`etc/module.php` 的 `provides` 和 `framework:compile` 注册。调用方必须在自己的
`etc/module.php.requires` 与 Composer `require` 中声明 `Weline_I18n` / `weline/module-i18n`。
`CountryRepositoryInterface::installedActive($displayLocale)` 固定按国家码升序返回已安装且启用的
国家；`displayName` 使用指定 locale 的国家名，缺失时回退国家码。地区、配送等模块应直接消费
该 DTO，不得再次查询 `Countries` 或 `Countries\Locale\Name`。

JS 翻译配置采用单向 Provider：Theme 实现 I18n 的 `JavascriptModuleConfigProviderInterface`，I18n 只读取编译后的
Provider 注册表，不再引用 Theme Reader。这样依赖方向固定为 `Theme -> I18n -> Framework`。

## WLS 按请求模块加载词典

WLS 的翻译所有权统一在 `Weline\Framework\Phrase\Parser`，`Weline\I18n\Parser` 只保留兼容入口。持久 Worker 不读取全量 `generated/language/{locale}.php`，也不在 READY 阶段遍历所有启用模块。

运行时按以下两级常驻数据工作：

1. 请求涉及的模块 CSV：`Worker 模块 L1 -> phrase Shared Memory 模块快照 -> 本模块 i18n CSV`。只有 Worker L1 失效才访问共享内存，只有共享 miss 才解析文件。
2. 最终词查询：先查 Worker 的 `locale + 模块集合 + word` 常驻哈希；模块 CSV miss 后，再按 `Worker 全局单词 L1 -> Shared Memory 单词记录 -> md5(word + locale) 精确数据库查询` 回源。

因此 Worker 只会把自己实际遇到的词不断加入常驻内存，不会一次装载 21,055 条全 locale 数据。最终词哈希最多 32,768 项并有界裁剪；普通请求清理不影响它，翻译发布/cache epoch 才统一失效。新增词条仍建议保存正确 `source_module` 以便维护、导出和模块归属，但无归属的历史词条也能通过精确单词回源生效，不会迫使 WLS 加载整张词典。

```php
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;

$resolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
$dictionary = $resolver->resolve(DictionaryRepositoryInterface::class);
$entry = $dictionary?->getEntry('@meta::theme.frontend.layouts.default.name', 'en_US');
$translation = $entry?->translation;
```

下文直接使用 I18n Model 的示例只适用于 `Weline_I18n` 模块内部维护；跨模块代码必须使用上述 Api。

## Dependency Inventory

- Framework 与 Symfony Intl 是 I18n 核心必需依赖。
- Acl、Admin、Backend、CacheManager、Queue 和 SystemConfig 均是可选集成：分别提供后台权限/页面、缓存 TTL、异步翻译与 AI 翻译配置。
- 后台权限属性只使用 Acl 公开的 `Api\Authorization\AccessMode` 标量常量；locale 热缓存 TTL 通过 CacheManager 的 `Api\RuntimeCachePolicy` 标量 facade 读取，I18n 不感知其内部 Service、默认合并或配置缓存实现。
- I18n 核心不得依赖 TranslationService；依赖方应通过 `Weline\I18n\Api\*` 读取本地化契约，保持 `TranslationService -> I18n -> Framework` 单向。

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
- 国家搜索会自动跨状态匹配；未安装国家可在搜索结果中直接安装

### 4.1 后台异步操作

I18n 后台的国家、地区、区域管理、词典、翻译和 AI 队列等写操作统一通过
`Weline.Api.resource('i18n_admin').action()` 进入 bin-query。页面只在服务端返回成功且完成数据库写入后更新当前行状态，不刷新列表、不改变当前筛选和滚动位置。

```javascript
Weline.Api.resource('i18n_admin').action({
    action: 'country-install',
    payload: {code: 'IN'}
});
```

`i18n_admin.action` 会复用现有后台生命周期服务，并在 bin-query 请求中显式启动当前后台 Session；这样多 worker 读取的仍是同一数据库状态。CSV 导入会先读取文件文本再通过 bin-query 提交，CSV/语言包下载保留原生下载响应以支持大文件。

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

跨模块扩展必须使用 `Weline\I18n\Api\Localization` 下的公开契约。旧的
`Weline\I18n\LocalModel`、`LocalModelInterface` 与 `TraitLocalModel` 名称保留为兼容别名，
新代码不要继续引用旧命名空间。

```php
namespace Your\Module\Model;

use Weline\I18n\Api\Localization\LocalModelInterface;
use Weline\I18n\Api\Localization\TraitLocalModel;

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

## ✨ 增强占位符功能（2025年新增）

### PHP 翻译函数占位符

框架的 `__()` 函数支持多种占位符格式，方便灵活地进行参数替换。

#### 1. 通用占位符 `%{}`

```php
// 单个字符串参数
echo __('Hello %{}', 'World');
// 输出：Hello World

// 单个数字参数
echo __('You have %{} messages', 5);
// 输出：You have 5 messages

// 用户欢迎消息
echo __('Welcome %{}!', 'John');
// 输出：Welcome John!
```

#### 2. 数字占位符 `%{1}`, `%{2}`, ...

```php
// 单个参数（%{1} 自动转换为 %{}）
echo __('Hello %{1}', 'World');
// 输出：Hello World

// 多个参数
echo __('User %{1} has %{2} messages', ['John', 5]);
// 输出：User John has 5 messages

// 日期时间格式化
echo __('Today is %{1}/%{2}/%{3}', [2025, 10, 26]);
// 输出：Today is 2025/10/26

// 订单信息
echo __('订单 %{1} 已发货，预计 %{2} 天内到达', ['ORD20250001', 3]);
// 输出：订单 ORD20250001 已发货，预计 3 天内到达
```

#### 3. 命名占位符 `%{name}`, `%{count}`, ...（推荐）

```php
// 基本用法
echo __('User %{name} has %{count} messages', [
    'name' => 'John',
    'count' => 5
]);
// 输出：User John has 5 messages

// 中文示例
echo __('%{name}，你好！我 %{age} 岁了', [
    'name' => '杨大大',
    'age' => 23
]);
// 输出：杨大大，你好！我 23 岁了

// 复杂场景
echo __('订单 %{order_id} 已发货，预计 %{days} 天内到达，收货人：%{receiver}', [
    'order_id' => 'ORD202501001',
    'days' => 3,
    'receiver' => '张三'
]);
// 输出：订单 ORD202501001 已发货，预计 3 天内到达，收货人：张三

// 用户信息展示
echo __('欢迎 %{username}，账户余额为 %{balance} 元，最后登录：%{last_login}', [
    'username' => 'admin',
    'balance' => 1250.50,
    'last_login' => '2024-01-15 14:30:25'
]);
// 输出：欢迎 admin，账户余额为 1250.5 元，最后登录：2024-01-15 14:30:25
```

#### 4. 混合使用（数字索引 + 命名参数）

```php
echo __('用户 %{1} 的 %{type} 操作 %{status}', [
    'admin',            // %{1}
    'type' => '登录',    // %{type}
    'status' => '成功'   // %{status}
]);
// 输出：用户 admin 的 登录 操作 成功
```

### JavaScript 翻译函数占位符

**✨ 新特性**：JavaScript 的 `__()` 函数现在完全支持 PHP 的所有占位符格式！

#### 1. 通用占位符 `%{}`

```javascript
// 字符串参数
console.log(__('Hello %{}', 'World'));
// 输出：Hello World

// 数字参数
console.log(__('You have %{} messages', 5));
// 输出：You have 5 messages

// DOM 操作
document.getElementById('welcome').innerText = __('Welcome %{}!', userName);
```

#### 2. 数字占位符 `%{1}`, `%{2}`, ...

```javascript
// 单个参数（自动转换）
console.log(__('Hello %{1}', 'World'));
// 输出：Hello World

// 数组参数
console.log(__('User %{1} has %{2} messages', ['John', 5]));
// 输出：User John has 5 messages

console.log(__('%{1} + %{2} = %{3}', [1, 2, 3]));
// 输出：1 + 2 = 3
```

#### 3. 命名占位符 `%{name}`, `%{count}`, ...（推荐）

```javascript
// 对象参数
console.log(__('User %{name} has %{count} messages', {
    name: 'John',
    count: 5
}));
// 输出：User John has 5 messages

// 中文示例
console.log(__('%{name}，你好！我 %{age} 岁了', {
    name: '杨大大',
    age: 23
}));
// 输出：杨大大，你好！我 23 岁了

// 在事件处理中使用
button.addEventListener('click', function() {
    alert(__('Are you sure to delete %{count} items?', {
        count: selectedItems.length
    }));
});

// AJAX 回调中使用
$.ajax({
    url: '/api/users',
    success: function(data) {
        showMessage(__('Successfully loaded %{count} users', {
            count: data.length
        }));
    }
});
```

### 模板中的 lang 标签占位符

**✨ 新特性**：`<lang>` 标签现在支持 `args` 属性来传递占位符参数！

#### 1. 无参数翻译

```html
<!-- 简单文本翻译 -->
<h1><lang>Welcome</lang></h1>
<p><lang>User Management</lang></p>
```

#### 2. 字符串参数

```html
<!-- 单个字符串参数 -->
<p><lang args="'John'">Welcome %{}!</lang></p>
<!-- 输出：Welcome John! -->

<p><lang args="'World'">Hello %{1}</lang></p>
<!-- 输出：Hello World -->
```

#### 3. 数组参数

```html
<!-- 数字索引参数 -->
<p><lang args="['John', 5]">User %{1} has %{2} messages</lang></p>
<!-- 输出：User John has 5 messages -->

<p><lang args="[1, 2, 3]">%{1} + %{2} = %{3}</lang></p>
<!-- 输出：1 + 2 = 3 -->
```

#### 4. 命名参数（推荐）

```html
<!-- 命名参数 -->
<p><lang args="['name' => 'John', 'count' => 5]">
    User %{name} has %{count} messages
</lang></p>
<!-- 输出：User John has 5 messages -->

<h2><lang args="['title' => '用户管理', 'total' => 100]">
    %{title} (共 %{total} 个)
</lang></h2>
<!-- 输出：用户管理 (共 100 个) -->
```

#### 5. 使用模板变量

```html
<!-- 使用单个变量 -->
<p><lang args="$username">Welcome %{}!</lang></p>

<!-- 使用数组变量 -->
<p><lang args="[$user->getName(), $user->getMessageCount()]">
    User %{1} has %{2} messages
</lang></p>

<!-- 使用命名参数变量（推荐） -->
<p><lang args="['name' => $user->getName(), 'count' => $messageCount]">
    User %{name} has %{count} messages
</lang></p>
```

#### 6. 自动变量识别（智能特性）⭐

**✨ 智能特性**：无需 `args` 参数，框架自动识别占位符并映射到同名 PHP 变量！

```html
<!-- 自动使用 PHP 变量 -->
<?php $min = 8; $max = 20; ?>
<lang>Password length must be between %{min} and %{max} characters</lang>
<!-- 输出：Password length must be between 8 and 20 characters -->

<?php $username = 'John'; ?>
<p><lang>Welcome %{username}!</lang></p>
<!-- 输出：Welcome John! -->

<?php $name = 'Alice'; $count = 5; ?>
<p><lang>User %{name} has %{count} messages</lang></p>
<!-- 输出：User Alice has 5 messages -->
```

**智能规则**：
- ✅ 有 `args` 参数：严格使用 `args` 提供的参数
- ✅ 无 `args` 参数：自动使用同名 PHP 变量
- ✅ 部分占位符不在 `args` 中：`args` 中的优先，其他自动使用变量

```html
<!-- 示例：混合使用 -->
<?php $min = 8; $max = 20; ?>
<lang args="['min' => 6]">Length: %{min}-%{max}</lang>
<!-- min 使用 args 中的 6，max 自动使用变量 $max 的 20 -->
<!-- 输出：Length: 6-20 -->
```

### 占位符格式对照表

| 占位符格式 | 参数类型 | PHP 示例 | JavaScript 示例 | Lang 标签示例 |
|----------|---------|---------|----------------|--------------|
| `%{}` | 字符串/数字 | `__('Hello %{}', 'World')` | `__('Hello %{}', 'World')` | `<lang args="'World'">Hello %{}</lang>` |
| `%{1}` | 字符串/数字 | `__('Hello %{1}', 'World')` | `__('Hello %{1}', 'World')` | `<lang args="'World'">Hello %{1}</lang>` |
| `%{1}`, `%{2}` | 数组 | `__('User %{1} has %{2}', ['John', 5])` | `__('User %{1} has %{2}', ['John', 5])` | `<lang args="['John', 5]">User %{1} has %{2}</lang>` |
| `%{name}`, `%{count}` | 关联数组/对象 | `__('User %{name}', ['name' => 'John'])` | `__('User %{name}', {name: 'John'})` | `<lang args="['name' => 'John']">User %{name}</lang>` |

### 最佳实践

#### 1. 选择合适的占位符格式

```php
// ✅ 推荐：使用命名占位符（语义清晰）
__('User %{name} has %{count} messages', ['name' => $name, 'count' => $count])

// ✅ 可以：使用数字占位符（参数较多时）
__('Date: %{1}-%{2}-%{3}', [$year, $month, $day])

// ✅ 可以：单个参数使用通用占位符
__('Welcome %{}!', $username)

// ❌ 不推荐：数字占位符太多时难以维护
__('%{1} %{2} %{3} %{4} %{5}', [$a, $b, $c, $d, $e])
```

#### 2. 保持翻译文本的完整性

```php
// ✅ 好的做法：完整的句子
__('User %{name} has %{count} new messages')

// ❌ 不好的做法：拆分句子
__('User') . ' ' . $name . ' ' . __('has') . ' ' . $count . ' ' . __('messages')
```

#### 3. 在 JavaScript 中使用对象参数

```javascript
// ✅ 推荐：使用对象（可读性强）
__('User %{name} has %{count} messages', {
    name: userName,
    count: messageCount
})

// ✅ 可以：使用数组（参数位置固定）
__('User %{1} has %{2} messages', [userName, messageCount])

// ❌ 避免：字符串拼接
'User ' + userName + ' has ' + messageCount + ' messages'
```

### 完整示例

#### Controller (PHP)

```php
<?php
namespace Weline\Example\Controller;

class Index
{
    public function execute()
    {
        $username = 'John';
        $messageCount = 5;
        
        // PHP 翻译
        $welcomeMsg = __('Welcome %{}!', $username);
        $userMsg = __('User %{name} has %{count} messages', [
            'name' => $username,
            'count' => $messageCount
        ]);
        
        return [
            'username' => $username,
            'message_count' => $messageCount
        ];
    }
}
```

#### Template (PHTML)

```php
<?php /** @var \Weline\Framework\View\Template $this */ ?>

<!-- 使用 PHP -->
<h1><?= __('User Management') ?></h1>
<p><?= __('Welcome %{}!', $username) ?></p>
<p><?= __('User %{name} has %{count} messages', [
    'name' => $username,
    'count' => $message_count
]) ?></p>

<!-- 使用 lang 标签（新增功能） -->
<h2><lang>User Management</lang></h2>
<p><lang args="$username">Welcome %{}!</lang></p>
<p><lang args="['name' => $username, 'count' => $message_count]">
    User %{name} has %{count} messages
</lang></p>

<!-- JavaScript 中使用（增强功能） -->
<script>
    // 通用占位符
    console.log(__('Welcome %{}!', '<?= $username ?>'));
    
    // 数组参数
    console.log(__('User %{1} has %{2} messages', 
        ['<?= $username ?>', <?= $message_count ?>]
    ));
    
    // 对象参数（推荐）
    console.log(__('User %{name} has %{count} messages', {
        name: '<?= $username ?>',
        count: <?= $message_count ?>
    }));
    
    // 动态使用
    function showUserInfo(name, count) {
        return __('User %{name} has %{count} messages', {
            name: name,
            count: count
        });
    }
</script>
```

### 注意事项

1. **占位符编号从 1 开始**：数组索引从 0 开始，但占位符 `%{1}` 对应数组的第一个元素（索引 0）

2. **空值处理**：如果参数为 `null` 或 `undefined`，将替换为空字符串

3. **特殊字符**：占位符文本中的特殊字符会被正确转义

4. **兼容性**：所有旧代码都能正常工作，新功能向后兼容

5. **性能考虑**：命名参数比数字参数性能略低，但在大多数场景下可以忽略

### 相关文档

- 📖 [完整占位符使用指南](../../Framework/doc/i18n-placeholder-usage.md)
- 🧪 [测试示例页面](../../Framework/doc/i18n-test-example.phtml)
- 📝 [变更日志](../../Framework/doc/CHANGELOG-i18n-enhancement.md)

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
use Weline\I18n\Api\Localization\TraitLocalModel;

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
A: 详细步骤请参考 [翻译包创建指南](翻译包创建指南.md)。简要步骤：在 `app/i18n/{厂商名}/{语言代码}/` 目录下创建 `register.php` 文件注册翻译包。

### Q: 如何处理翻译缺失？
A: 使用默认语言作为回退，或实现翻译缺失检测和提醒。

### Q: 如何优化翻译性能？
A: 启用缓存、按需加载、使用 CDN 等方式提升性能。

### Q: 如何处理复数形式？
A: 使用条件判断或专门的复数翻译函数处理不同语言的复数规则。

### Q: 如何支持 RTL 语言？
A: 在语言包配置中设置 `direction` 为 `rtl`，并在前端适配 RTL 样式。
