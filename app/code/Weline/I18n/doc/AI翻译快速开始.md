# AI翻译功能 - 快速开始

## 快速开始（5分钟上手）

### 步骤1：导入已有的CSV翻译（推荐）

这一步可以大大减少AI翻译成本，因为已有的翻译会被导入词典，AI就不会再翻译这些词了。

```bash
# 导入所有模块的英文翻译
php bin/w i18n:ai:import-csv --all --locale=en_US

# 导入所有模块的日文翻译
php bin/w i18n:ai:import-csv --all --locale=ja_JP
```

### 步骤2：手动执行一次AI翻译（测试）

```bash
# 翻译为英文（默认每次1000个词）
php bin/w i18n:ai:translate --locale=en_US

# 翻译为日文
php bin/w i18n:ai:translate --locale=ja_JP

# 翻译少量词进行测试（例如100个）
php bin/w i18n:ai:translate --locale=en_US --limit=100
```

### 步骤3：启用定时自动翻译

```bash
# 安装系统定时任务（如果还没安装）
php bin/w cron:install
```

安装后，系统会每小时自动执行翻译任务，无需手动干预。

### 步骤4：查看翻译结果

#### 方式1：查看系统消息

1. 登录后台管理系统
2. 查看系统消息中心
3. 会看到翻译完成的通知，包含详细统计信息

#### 方式2：查看数据库

```sql
-- 查看某个语言的翻译数量
SELECT locale_code, COUNT(*) as count 
FROM i18n_locale_dictionary 
GROUP BY locale_code;

-- 查看最近添加的翻译
SELECT word, locale_code, translate 
FROM i18n_locale_dictionary 
ORDER BY md5 DESC 
LIMIT 10;
```

## 工作流程示意图

```
开始
  ↓
[CSV导入] → 导入已有翻译到词典
  ↓
[手动翻译] → 测试AI翻译功能
  ↓
[查看结果] → 检查翻译质量和系统消息
  ↓
[定时任务] → 每小时自动翻译新词
  ↓
持续运行
```

## 预期效果

### CSV导入后

```
处理文件数: 5
导入数量: 1234
跳过数量: 0
失败数量: 0
耗时: 0.5秒
```

### AI翻译后

```
目标语言: en_US
翻译数量: 856
失败数量: 0
总词数: 1000
耗时: 15.3秒
```

### 定时任务运行中

系统会每小时检查是否有新词需要翻译：

- **有新词**：自动翻译并发送完成通知
- **无新词**：返回"所有词典都已完成翻译"

## 验证功能是否正常

### 1. 检查事件是否注册

```bash
# 查看事件配置文件
cat app/code/Weline/I18n/etc/event.xml
```

应该能看到 `Weline_Ai::translate` 事件的观察者配置。

### 2. 检查定时任务是否注册

```bash
# 查看系统中的定时任务
php bin/w cron:task:list
```

应该能看到 `i18n_ai_translation` 任务。

### 3. 手动测试翻译

```bash
# 翻译10个词进行快速测试
php bin/w i18n:ai:translate --locale=en_US --limit=10
```

### 4. 查看系统消息

登录后台，检查系统消息中心是否收到翻译相关的通知。

## 常见使用场景

### 场景1：新模块开发完成，需要翻译

```bash
# 1. 收集翻译词（框架自动）
php bin/w setup:upgrade

# 2. 翻译为多种语言
php bin/w i18n:ai:translate --locale=en_US
php bin/w i18n:ai:translate --locale=ja_JP
php bin/w i18n:ai:translate --locale=ko_KR
```

### 场景2：已有CSV翻译文件，需要导入

```bash
# 导入单个文件
php bin/w i18n:ai:import-csv --file=app/code/MyModule/i18n/en_US.csv --locale=en_US

# 导入所有模块的文件
php bin/w i18n:ai:import-csv --all --locale=en_US
```

### 场景3：定期维护翻译

```bash
# 每周执行一次，确保所有新词都被翻译
php bin/w i18n:ai:translate --locale=en_US
php bin/w i18n:ai:translate --locale=ja_JP
```

## 成本预估

假设使用 GPT-3.5 模型进行翻译：

- **1000个词** ≈ 0.5美元
- **10000个词** ≈ 5美元
- **100000个词** ≈ 50美元

通过CSV导入已有翻译，可以大大降低成本。

## 下一步

- 阅读 [AI翻译功能说明](./AI翻译功能说明.md) 了解详细功能
- 阅读 [AI翻译调用事件文档](../../../Ai/doc/event/AI翻译调用.md) 了解事件接口
- 查看 [系统消息中心] 监控翻译状态
- 定期检查翻译质量，优化翻译策略

## 故障排除

### 问题：翻译失败

**可能原因**：
- AI模型未配置
- API密钥错误
- 网络问题
- API配额不足

**解决方法**：
1. 检查 `Weline_Ai` 模块配置
2. 验证API密钥是否正确
3. 检查网络连接
4. 查看系统消息中的详细错误

### 问题：翻译数量为0

**可能原因**：
- 所有词都已翻译（增量翻译机制）
- 词典中没有待翻译的词

**解决方法**：
1. 查看系统消息确认原因
2. 检查数据库中的翻译记录
3. 确认是否有新词需要翻译

### 问题：定时任务不执行

**可能原因**：
- 定时任务未安装
- 系统计划任务服务未启动

**解决方法**：
```bash
# 重新安装定时任务
php bin/w cron:install

# 手动执行测试
php bin/w cron:task:run
```

## 联系支持

如果遇到问题：

1. 查看系统消息中心的错误详情
2. 检查日志文件
3. 参考完整文档
4. 联系技术支持

