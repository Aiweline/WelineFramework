# I18n AI翻译功能说明

## 概述

I18n模块集成了AI翻译功能，支持批量翻译词典，实现自动化的多语言翻译。主要特性包括：

- **批量翻译**：每次翻译1000个词，而不是逐个翻译
- **增量翻译**：只翻译未翻译的词，避免重复翻译和浪费API配额
- **定时翻译**：通过定时任务自动化翻译流程
- **CSV导入**：支持从CSV文件导入已有翻译，减少AI翻译成本
- **异常处理**：完善的异常处理和系统消息通知

## 功能架构

### 事件驱动架构

```
触发模块 (I18n Cron/Console)
    ↓
触发事件: Weline_Ai::translate
    ↓
事件观察者: I18n\Observer\AiTranslationObserver
    ↓
调用服务: Ai\Service\TranslationService
    ↓
批量翻译（一次性翻译所有词）
    ↓
返回翻译结果
    ↓
保存到词典: i18n_locale_dictionary 表
```

### 核心组件

1. **事件系统**
   - 事件名称：`Weline_Ai::translate`
   - 提供模块：`Weline_Ai`
   - 监听模块：`Weline_I18n`
   - 观察者：`Weline\I18n\Observer\AiTranslationObserver`

2. **翻译服务**
   - `Weline\I18n\Service\AiTranslationService` - I18n翻译服务
   - `Weline\Ai\Service\TranslationService` - AI翻译服务

3. **定时任务**
   - 任务类：`Weline\I18n\Cron\AiTranslation`
   - 执行频率：每小时一次
   - 任务名称：`i18n_ai_translation`

4. **控制台命令**
   - `i18n:ai:translate` - 手动触发翻译
   - `i18n:ai:import-csv` - 导入CSV翻译文件

## 使用方法

### 1. 定时自动翻译

系统会每小时自动执行翻译任务，无需手动干预。

#### 配置定时任务

首先确保定时任务已安装：

```bash
# 安装系统定时任务
php bin/w cron:install
```

#### 定时任务特性

- **执行频率**：每小时执行一次（`0 * * * *`）
- **批量大小**：每次翻译1000个词
- **增量翻译**：只翻译未翻译的词
- **多语言支持**：自动为所有启用的语言进行翻译
- **系统通知**：翻译完成或失败时发送系统消息

### 2. 手动批量翻译

使用控制台命令手动触发翻译：

```bash
# 翻译为英文（默认）
php bin/w i18n:ai:translate

# 翻译为日文
php bin/w i18n:ai:translate --locale=ja_JP

# 翻译为韩文，指定源语言
php bin/w i18n:ai:translate --locale=ko_KR --source=zh_Hans_CN

# 每次翻译500个词
php bin/w i18n:ai:translate --locale=en_US --limit=500
```

#### 命令参数

| 参数 | 简写 | 说明 | 默认值 |
|------|------|------|--------|
| --locale | -l | 目标语言代码（如 en_US, ja_JP） | en_US |
| --source | -s | 源语言代码（如 zh_Hans_CN） | zh_Hans_CN |
| --limit | - | 每批翻译数量 | 1000 |

### 3. CSV翻译导入

在使用AI翻译之前，建议先导入已有的CSV翻译文件，这样可以：

- 减少AI翻译的成本
- 保留已有的人工翻译
- 加快翻译速度

#### 导入单个CSV文件

```bash
# 导入指定的CSV文件
php bin/w i18n:ai:import-csv --file=path/to/en_US.csv --locale=en_US
```

#### 导入所有模块的CSV文件

```bash
# 自动扫描并导入所有模块的英文翻译
php bin/w i18n:ai:import-csv --all --locale=en_US

# 导入所有模块的日文翻译
php bin/w i18n:ai:import-csv --all --locale=ja_JP
```

#### CSV文件格式

CSV文件格式为两列：原词、翻译

```csv
首页,Home
用户中心,User Center
购物车,Shopping Cart
订单管理,Order Management
```

### 4. 编程方式调用

#### 通过事件调用AI翻译

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 准备待翻译的词
$words = ['首页', '用户中心', '购物车'];

// 创建事件数据
$eventData = [
    'words' => $words,                    // 待翻译词列表
    'target_locale' => 'en_US',          // 目标语言
    'source_locale' => 'zh_Hans_CN',     // 源语言
    'strategy' => 'light',                // 翻译策略
    'translations' => [],                 // 输出：翻译结果
    'errors' => [],                      // 输出：错误信息
    'success' => false                   // 输出：是否成功
];

// 触发翻译事件
$eventsManager->dispatch('Weline_Ai::translate', $eventData);

// 获取翻译结果
if ($eventData['success']) {
    $translations = $eventData['translations'];
    // $translations = ['首页' => 'Home', '用户中心' => 'User Center', ...]
    
    foreach ($translations as $word => $translation) {
        echo "{$word} => {$translation}\n";
    }
} else {
    // 处理错误
    $errors = $eventData['errors'];
    foreach ($errors as $error) {
        echo "错误: {$error}\n";
    }
}
```

#### 直接使用翻译服务

```php
use Weline\I18n\Service\AiTranslationService;
use Weline\Framework\Manager\ObjectManager;

// 获取翻译服务
$translationService = ObjectManager::getInstance(AiTranslationService::class);

// 批量翻译词典
$result = $translationService->batchTranslateDictionary(
    'en_US',        // 目标语言
    'zh_Hans_CN',   // 源语言
    1000            // 批量大小
);

// 查看结果
if ($result['success']) {
    echo "成功翻译 {$result['translated']} 个词\n";
    echo "跳过 {$result['skipped']} 个词\n";
    echo "失败 {$result['failed']} 个词\n";
    echo "耗时 {$result['duration']} 秒\n";
} else {
    echo "翻译失败: {$result['message']}\n";
}

// 导入CSV文件
$result = $translationService->importFromCsv('/path/to/en_US.csv', 'en_US');

// 导入所有模块的CSV文件
$result = $translationService->importModuleCsvFiles('en_US');
```

## 工作流程

### 完整翻译流程

1. **CSV导入（可选，但推荐）**
   ```bash
   # 先导入已有的翻译
   php bin/w i18n:ai:import-csv --all --locale=en_US
   ```

2. **AI批量翻译**
   ```bash
   # 翻译未翻译的词
   php bin/w i18n:ai:translate --locale=en_US
   ```

3. **定时自动翻译**
   - 系统会每小时自动翻译新增的词
   - 无需手动干预

### 增量翻译机制

系统会自动检查词典中哪些词已经翻译，只翻译未翻译的词：

1. 从 `i18n_dictionary` 表获取所有词
2. 检查 `i18n_locale_dictionary` 表中是否已存在翻译
3. 只对未翻译的词进行翻译
4. 保存翻译结果到 `i18n_locale_dictionary` 表

### 批量翻译优化

为了提高效率和减少API调用成本，系统采用真正的批量翻译：

- **不是**循环调用翻译API（一次翻译一个词）
- **而是**一次性将所有词发送给AI（一次翻译1000个词）
- 大大减少了API调用次数和成本
- 提高了翻译速度

## 异常处理

### 系统消息通知

翻译过程中的重要事件会自动发送系统消息：

1. **翻译完成**
   - 标题：AI批量翻译完成
   - 内容：包含翻译数量、失败数量、耗时等统计信息

2. **翻译失败**
   - 标题：AI翻译失败
   - 内容：包含错误信息和详细的诊断信息

3. **翻译异常**
   - 标题：AI翻译异常
   - 内容：包含异常信息和异常位置

4. **CSV导入完成/失败**
   - 类似翻译的通知机制

### 错误处理

所有异常都会被捕获并记录：

- 翻译失败时，返回错误信息
- 发送系统消息通知管理员
- 记录详细的错误日志
- 不会影响其他词的翻译

## 性能优化

### 批量翻译优化

- **批量大小**：每次1000个词，平衡速度和成本
- **真正批量**：一次API调用翻译所有词，而不是循环调用
- **并发控制**：避免过多并发请求导致API限流

### 缓存机制

- AI翻译服务内置缓存机制
- 相同内容不会重复翻译
- 缓存时间：24小时

### 增量翻译

- 只翻译未翻译的词
- 避免重复翻译已有内容
- 大大减少API调用成本

## 数据库表结构

### i18n_dictionary 表

存储所有需要翻译的词：

| 字段 | 类型 | 说明 |
|------|------|------|
| word | TEXT | 词汇内容 |
| is_backend | INTEGER | 是否后端词汇 |
| module | VARCHAR | 所属模块 |

### i18n_locale_dictionary 表

存储翻译结果：

| 字段 | 类型 | 说明 |
|------|------|------|
| md5 | VARCHAR | MD5指纹（word+locale_code） |
| word | TEXT | 原词 |
| locale_code | VARCHAR | 语言代码 |
| translate | TEXT | 翻译结果 |

## 最佳实践

### 1. CSV优先策略

在使用AI翻译之前，先导入已有的CSV翻译：

```bash
# 1. 导入已有翻译
php bin/w i18n:ai:import-csv --all --locale=en_US

# 2. AI翻译未翻译的词
php bin/w i18n:ai:translate --locale=en_US
```

### 2. 批量大小控制

- 默认1000个词/批，适合大多数情况
- 如果API超时，可以减小批量大小
- 如果API配额充足，可以增大批量大小

### 3. 成本控制

- 使用CSV导入减少AI翻译成本
- 使用增量翻译避免重复翻译
- 使用轻量翻译策略（`light`）降低成本
- 定期检查API使用量

### 4. 质量保证

- 重要内容可以使用高保真策略（`high_fidelity`）
- 定期人工审核翻译质量
- 及时修正错误的翻译

### 5. 定时任务监控

- 定期检查系统消息中心
- 关注翻译失败的通知
- 及时处理异常情况

## 常见问题

### Q: 为什么翻译数量是0？

A: 可能的原因：
1. 所有词都已经翻译过（增量翻译机制）
2. 词典中没有待翻译的词
3. 检查系统消息了解详情

### Q: 翻译失败怎么办？

A: 检查以下几点：
1. 是否配置了AI翻译模型
2. AI API密钥是否正确
3. API配额是否充足
4. 网络是否正常
5. 查看系统消息中的详细错误信息

### Q: 如何提高翻译速度？

A: 建议：
1. 增大批量大小（--limit参数）
2. 先导入CSV文件，减少AI翻译量
3. 使用轻量翻译策略（light）
4. 确保网络速度良好

### Q: 如何降低翻译成本？

A: 建议：
1. 优先导入已有的CSV翻译
2. 使用增量翻译（默认开启）
3. 使用轻量翻译策略
4. 合理设置批量大小

## 相关文档

- [AI翻译调用事件文档](../../../Ai/doc/event/AI翻译调用.md)
- [I18n事件文档](./event/AI翻译调用.md)
- [定时任务开发指南](../../../Cron/doc/README.md)
- [I18n模块开发文档](./README.md)

## 更新记录

- 2025-12-06: 创建AI翻译功能，包含批量翻译、增量翻译、CSV导入等功能

