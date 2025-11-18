# 脱敏模块快速开始

## 三种工作模式快速示例

### 1. 脱敏模式 - 保护隐私信息

```php
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\Manager\ObjectManager;

$service = ObjectManager::getInstance()->get(DesensitizationService::class);

// 脱敏邮箱和手机号
$content = '联系邮箱：test@example.com，电话：13812345678';
$result = $service->desensitize($content);

echo $result;
// 输出：联系邮箱：test***@example.***，电话：138****5678
```

### 2. 检测模式 - 发现敏感信息

```php
// 检测内容中是否包含敏感信息
$result = $service->detectSensitive($content, [
    'return_positions' => true
]);

/*
返回：
[
    'has_sensitive' => true,
    'sensitive_types' => ['email', 'phone'],
    'positions' => [
        ['type' => 'email', 'match' => 'test@example.com', 'start' => 5, 'end' => 22],
        ['type' => 'phone', 'match' => '13812345678', 'start' => 25, 'end' => 36]
    ]
]
*/
```

### 3. 重写模式 - AI润色美化

```php
// 脱敏并使用AI重写润色
$content = '用户张三的邮箱是zhangsan@example.com';
$result = $service->desensitizeAndRewrite($content, [
    'rewrite_style' => 'natural' // 自然流畅风格
]);

// AI会自动脱敏并重写成更自然的表达
```

## API调用

### 脱敏
```bash
POST /desensitization/api/process
{
    "content": "test@example.com"
}
```

### 检测
```bash
POST /desensitization/api/detect
{
    "content": "联系邮箱test@example.com"
}
```

### 重写
```bash
POST /desensitization/api/rewrite
{
    "content": "用户邮箱test@example.com",
    "rewrite_style": "natural"
}
```

## 配置重写风格

- `natural` - 自然流畅（默认）
- `formal` - 正式专业
- `casual` - 轻松随意
- `professional` - 专业严谨
- `concise` - 简洁精炼

## 内置脱敏规则

- ✅ 邮箱
- ✅ 手机号
- ✅ 身份证号
- ✅ 银行卡号
- ✅ 信用卡号

## 更多信息

查看完整文档：[README.md](README.md)

