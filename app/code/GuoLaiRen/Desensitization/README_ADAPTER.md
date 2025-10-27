# 数据脱敏AI适配器使用说明

## 概述

GuoLaiRen_Desensitization 模块已集成 AI 场景适配器，可通过 AI 模块的适配器系统自动识别和调用。

## 适配器信息

**适配器代码**: `desensitization`  
**适配器名称**: 数据脱敏适配器  
**版本**: 1.0.0  
**位置**: `app/code/GuoLaiRen/Desensitization/Ai/Adapter/DesensitizationAdapter.php`

## 功能特性

### 1. 多种脱敏策略

- **strict（严格模式）**：对敏感信息进行深度脱敏
- **relaxed（宽松模式）**：仅对明显的敏感信息脱敏
- **comprehensive（全面模式）**：识别并脱敏所有类型的敏感信息（默认）

### 2. 三种脱敏级别

- **high（高级）**：尽可能隐藏敏感信息
- **standard（标准）**：平衡隐私保护和可读性（默认）
- **low（低级）**：仅部分遮挡敏感信息

### 3. 智能识别

自动识别以下敏感信息：
- 邮箱地址
- 手机号码
- 身份证号码
- 银行卡号
- 真实姓名
- 具体地址

## 使用方法

### 方式1：在代码中直接使用

```php
<?php
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

// 获取AI服务
$aiService = ObjectManager::getInstance()->get(AiService::class);

// 使用脱敏适配器处理内容
$content = '用户信息：张三，邮箱zhangsan@example.com，电话13812345678';

$result = $aiService->generate(
    $content,
    null,                              // 使用默认模型
    'desensitization',                 // 使用脱敏适配器
    'zh_Hans_CN',                     // 语言代码
    [
        'strategy' => 'comprehensive', // 全面模式
        'level' => 'standard'          // 标准级别
    ]
);

echo $result;
// 输出：用户信息：张*，邮箱zh******n@example.com，电话138****5678
```

### 方式2：通过 DesensitizationService 使用

```php
<?php
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\Manager\ObjectManager;

$service = ObjectManager::getInstance()->get(DesensitizationService::class);

// 使用AI脱敏模式
$result = $service->desensitize(
    '联系我：me@company.com 或致电 13987654321',
    'ai',                              // 使用AI脱敏
    [
        'scenario_code' => 'desensitization',
        'strategy' => 'comprehensive',
        'level' => 'standard'
    ]
);
```

### 方式3：调用API

```bash
curl -X POST http://your-domain/desensitization/api/process \
  -H "Content-Type: application/json" \
  -d '{
    "content": "用户信息：张三，邮箱zhangsan@example.com，电话13812345678",
    "method": "ai",
    "options": {
      "strategy": "comprehensive",
      "level": "standard"
    }
  }'
```

## 配置参数说明

### strategy（脱敏策略）

| 值 | 说明 | 适用场景 |
|---|---|---|
| `strict` | 严格模式，深度脱敏 | 高隐私要求的数据 |
| `relaxed` | 宽松模式，适度脱敏 | 低敏感度数据 |
| `comprehensive` | 全面模式，识别所有敏感信息 | 通用场景（默认） |

### level（脱敏级别）

| 值 | 说明 | 可读性 | 隐私保护 |
|---|---|---|---|
| palliative | 低级，部分遮挡 | 高 | 低 |
| standard | 标准，平衡处理 | 中 | 中（默认） |
| high | 高级，深度隐藏 | 低 | 高 |

### keep_context（保持上下文）

| 值 | 说明 |
|---|---|
| `true` | 保持文本上下文和可读性（默认） |
| `false` | 优先隐私保护，可适当降低可读性 |

## 使用示例

### 示例1：标准脱敏

```php
$content = '用户信息：张三，邮箱zhangsan@example.com，电话13812345678，身份证号370123199001011234';

$result = $aiService->generate(
    $content,
    null,
    'desensitization'
);

// 输出：用户信息：张*，邮箱zh******n@example.com，电话138****5678，身份证号370123********1234
```

### 示例2：严格模式 + 高级脱敏

```php
$content = '联系我：me@company.com 或致电 13987654321';

$result = $aiService->generate(
    $content,
    null,
    'desensitization',
    'zh_Hans_CN',
    [
        'strategy' => 'strict',
        'level' => 'high'
    ]
);

// 输出：联系信息已脱敏处理
```

### 示例3：宽松模式 + 低级脱敏

```php
$content = '我的银行卡号是6222021234567890123';

$result = $aiService->generate(
    $content,
    null,
    'desensitization',
    'zh_Hans_CN',
    [
        'strategy' => 'relaxed',
        'level' => 'low',
        'keep_context' => true
    ]
);

// 输出：我的银行卡号已脱敏处理
```

## 自动扫描和注册

当运行 `php bin/w setup:upgrade` 时，适配器会自动被 Weline_Ai 模块扫描并注册到系统中。

手动扫描适配器：

```bash
php bin/w ai:adapter:scan
```

## 与脱敏服务集成

该适配器已与 `DesensitizationService` 集成，可以通过以下方式使用：

```php
// 配置文件中指定使用适配器
// app/code/GuoLaiRen/Desensitization/etc/env.php
'desensitization' => [
    'ai' => [
        'desensitization_adapter' => 'desensitization',  // 使用适配器
        'enabled' => true
    ]
]
```

## 注意事项

1. **性能考虑**：AI脱敏比正则脱敏慢，适合高质量要求场景
2. **成本考虑**：AI脱敏消耗token，注意控制使用量
3. **准确性**：AI可能误判，建议结合正则规则使用
4. **模型选择**：不同模型效果可能不同，建议测试后选择

## 故障排除

### 适配器未被发现

```bash
# 手动扫描适配器
php bin/w ai:adapter:scan

# 查看已注册的适配器
php bin/w ai:adapter:list
```

### 验证适配器

```php
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

$aiService = ObjectManager::getInstance()->get(AiService::class);
$adapters = $aiService->getAvailableAdapters();

// 检查 desensitization 适配器是否存在
if (isset($adapters['desensitization'])) {
    echo "适配器已注册";
}
```

## 技术支持

如有问题，请参考：
- AI模块文档：`app/code/Weline/Ai/README.md`
- 适配器扫描文档：`app/code/Weline/Ai/README_ADAPTER_SCAN.md`
- 脱敏模块文档：`app/code/GuoLaiRen/Desensitization/README.md`

