# 脱敏模块使用指南

## 快速开始

### 1. 安装模块

```bash
php bin/w setup:upgrade
```

### 2. 验证安装

```bash
php bin/w module:list | grep "Desensitization"
```

### 3. 访问后台

访问后台管理界面，在左侧菜单中找到"数据脱敏"模块。

## 基本使用

### 代码调用示例

```php
<?php
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\Manager\ObjectManager;

// 获取服务实例
$service = ObjectManager::getInstance()->get(DesensitizationService::class);

// 示例1：简单脱敏
$result = $service->desensitize('我的邮箱是 example@domain.com');
echo $result; // 输出：我的邮箱是 example***@domain.***

// 示例2：指定邮箱脱敏
$result = $service->desensitize(
    '联系邮箱：test@example.com',
    'regex',
    ['rule_type' => 'email']
);
echo $result; // 输出：联系邮箱：test***@example.***

// 示例3：手机号脱敏
$result = $service->desensitize(
    '联系电话：13812345678',
    'regex',
    ['rule_type' => 'phone']
);
echo $result; // 输出：联系电话：138****5678

// 示例4：使用AI智能脱敏（需要AI模块支持）
$content = '用户信息：张三，邮箱zhangsan@example.com，电话13812345678';
$result = $service->desensitize(
    $content,
    'ai',
    ['model_code' => 'gpt-3.5-turbo'] // 可选
);
echo $result;

// 示例5：批量脱敏
$contents = [
    'email1@example.com',
    'email2@example.com',
    '13812345678',
    '13987654321'
];
$results = $service->desensitizeBatch($contents);
foreach ($results as $index => $result) {
    echo "原内容：{$contents[$index]}\n";
    echo "脱敏后：{$result}\n\n";
}
```

### API调用示例

```bash
# 单条内容脱敏
curl -X POST http://your-domain/desensitization/api/process \
  -H "Content-Type: application/json" \
  -d '{
    "content": "联系邮箱：test@example.com",
    "method": "regex",
    "options": {
      "rule_type": "email"
    }
  }'

# 批量脱敏
curl -X POST http://your-domain/desensitization/api/batch \
  -H "Content-Type: application/json" \
  -d '{
    "contents": ["test@example.com", "13812345678"],
    "method": "regex"
  }'

# 获取规则列表
curl http://your-domain/desensitization/api/rules

# 获取可用方法
curl http://your-domain/desensitization/api/methods
```

## 管理脱敏规则

### 1. 添加规则

访问 `/desensitization/backend/rule/add`

填写规则信息：
- **规则名称**：规则的标识名称
- **规则类型**：email, phone, id_card, bank_card, custom 等
- **匹配模式**：正则表达式，如 `/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/`
- **替换内容**：替换后的内容，可以使用正则表达式捕获组，如 `$1***@$2.***`
- **描述**：规则的说明
- **优先级**：数字越大优先级越高
- **是否激活**：是否启用此规则

### 2. 测试规则

在规则管理页面，点击"测试"按钮，输入测试内容即可看到脱敏效果。

### 3. 脱敏测试

访问 `/desensitization/backend/test/index` 进行在线脱敏测试。

可以输入内容，选择脱敏方法，查看脱敏效果和执行时间。

## 内置规则说明

### 邮箱规则（email）

- 模式：`/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/`
- 替换：`$1***@$2.***`
- 示例：`example@domain.com` → `example***@domain.***`

### 手机号规则（phone）

- 模式：`/(\d{3})\d{4}(\d{4})/`
- 替换：`$1****$2`
- 示例：`13812345678` → `138****5678`

### 身份证号规则（id_card）

- 模式：`/(\d{6})\d{8}(\d{4})/`
- 替换：`$1********$2`
- 示例：`370123199001011234` → `370123********1234`

### 银行卡号规则（bank_card）

- 模式：`/(\d{4})\d{12}(\d{4})/`
- 替换：`$1************$2`
- 示例：`6222123456789012345` → `6222************2345`

## 高级用法

### 自定义替换函数

```php
// 创建自定义规则，使用回调函数
$rule = ObjectManager::getInstance()->get(DesensitizationRule::class);
$rule->setData([
    'name' => '自定义姓名脱敏',
    'type' => 'name',
    'pattern' => '/[\x{4e00}-\x{9fa5}]{2,4}/u',
    'replacement' => '***', // 回调函数需要在运行时处理
    'description' => '姓名脱敏',
    'is_active' => 1,
    'priority' => 10
]);
$rule->save();
```

### 使用自定义选项

```php
$result = $service->desensitize(
    '联系邮箱：test@example.com',
    'custom',
    [
        'rules' => [
            [
                'pattern' => '/([a-zA-Z0-9._-]+)@/',
                'replacement' => '$1***@'
            ]
        ]
    ]
);
```

## 注意事项

1. **正则表达式安全**：确保正则表达式不会导致正则表达式攻击
2. **AI成本**：使用AI脱敏会消耗AI API调用次数，注意成本控制
3. **性能考虑**：大批量数据处理建议使用批量接口
4. **日志管理**：生产环境建议定期清理日志
5. **备份数据**：脱敏前建议备份原始数据

## 故障排查

### 规则不生效

1. 检查规则是否正确激活
2. 检查正则表达式是否正确
3. 查看错误日志

### AI脱敏失败

1. 确认AI模块已安装
2. 检查AI配置是否正确
3. 确认API密钥有效
4. 查看错误日志

### 性能问题

1. 减少规则数量
2. 优化正则表达式
3. 使用缓存
4. 考虑使用批量处理

## 技术支持

如有问题，请查看：
- README.md - 完整文档
- AI模块文档 - `app/code/Weline/Ai/doc/`
- 后台管理界面 - 在线测试和使用

