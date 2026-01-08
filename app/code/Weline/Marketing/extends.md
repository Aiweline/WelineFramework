# Weline_Marketing 模块扩展文档

## 概述

Weline_Marketing 模块提供了营销规则引擎，允许其他模块通过实现扩展点来扩展营销功能。主要包括条件判断和动作执行两个核心扩展点。

## 快速开始

### 1. 创建扩展目录

在您的模块中创建以下目录结构：

```
app/code/YourModule/
└── extends/
    └── module/
        └── Weline_Marketing/
            ├── Condition/
            │   └── YourCondition.php
            └── Action/
                └── YourAction.php
```

### 2. 实现条件扩展

创建条件类，实现 `ConditionInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Extends\Weline_Marketing\Condition;

use Weline\Marketing\Interface\Rule\ConditionInterface;
use Weline\Marketing\Model\Rule\Condition\Result;

class YourCondition implements ConditionInterface
{
    public function getCode(): string
    {
        return 'your_condition';
    }

    public function getName(): string
    {
        return '您的条件';
    }

    public function getDescription(): string
    {
        return '条件描述';
    }

    public function validate(array $params, $context = null): Result
    {
        // 实现条件判断逻辑
        $value = $params['value'] ?? null;
        
        if ($this->meetsCondition($value, $context)) {
            return Result::passed();
        }
        
        return Result::failed('条件不满足');
    }

    public function getParamTemplate(): array
    {
        return [
            'value' => [
                'type' => 'string',
                'label' => '值',
                'required' => true,
                'description' => '条件值'
            ]
        ];
    }

    private function meetsCondition($value, $context): bool
    {
        // 实现具体的判断逻辑
        return true;
    }
}
```

### 3. 实现动作扩展

创建动作类，实现 `ActionInterface` 接口：

```php
<?php
declare(strict_types=1);

namespace YourModule\Extends\Weline_Marketing\Action;

use Weline\Marketing\Interface\Rule\ActionInterface;
use Weline\Marketing\Model\Rule\Action\Result;

class YourAction implements ActionInterface
{
    public function getCode(): string
    {
        return 'your_action';
    }

    public function getName(): string
    {
        return '您的动作';
    }

    public function getDescription(): string
    {
        return '动作描述';
    }

    public function execute(array $params, $context = null): Result
    {
        // 实现动作执行逻辑
        try {
            $this->performAction($params, $context);
            return Result::success('动作执行成功');
        } catch (\Exception $e) {
            return Result::failed($e->getMessage());
        }
    }

    public function getParamTemplate(): array
    {
        return [
            'amount' => [
                'type' => 'float',
                'label' => '金额',
                'required' => true,
                'description' => '动作参数'
            ]
        ];
    }

    private function performAction(array $params, $context): void
    {
        // 实现具体的动作逻辑
    }
}
```

## 详细说明

### Condition 扩展点

**路径**: `extends/module/Weline_Marketing/Condition`

**接口**: `Weline\Marketing\Interface\Rule\ConditionInterface`

**用途**: 扩展营销规则的条件判断功能。可以创建各种自定义条件，如购物车金额、商品数量、用户等级等。

**要求**:
- 必须实现 `ConditionInterface` 接口
- 必须实现所有接口方法
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回条件代码（唯一标识）
- **getName()**: 返回条件显示名称
- **getDescription()**: 返回条件描述
- **validate()**: 执行条件验证，返回 `Result` 对象
- **getParamTemplate()**: 返回参数模板定义

### Action 扩展点

**路径**: `extends/module/Weline_Marketing/Action`

**接口**: `Weline\Marketing\Interface\Rule\ActionInterface`

**用途**: 扩展营销规则的动作执行功能。可以创建各种自定义动作，如折扣、免运费、赠品等。

**要求**:
- 必须实现 `ActionInterface` 接口
- 必须实现所有接口方法
- 允许多个实现

#### 接口方法说明

- **getCode()**: 返回动作代码（唯一标识）
- **getName()**: 返回动作显示名称
- **getDescription()**: 返回动作描述
- **execute()**: 执行动作，返回 `Result` 对象
- **getParamTemplate()**: 返回参数模板定义

## 参数类型

支持以下参数类型：

- **string**: 字符串
- **int**: 整数
- **float**: 浮点数
- **bool**: 布尔值
- **select**: 下拉选择
- **array**: 数组

### 参数模板示例

```php
public function getParamTemplate(): array
{
    return [
        'value' => [
            'type' => 'string',
            'label' => '值',
            'required' => true,
            'default' => '',
            'description' => '参数说明',
            'options' => [  // 仅 select 类型需要
                'option1' => '选项1',
                'option2' => '选项2'
            ]
        ]
    ];
}
```

## 结果对象

### Condition Result

```php
// 条件满足
Result::passed();

// 条件不满足
Result::failed('原因说明');

// 条件跳过（不影响规则评估）
Result::skipped();
```

### Action Result

```php
// 动作执行成功
Result::success('成功消息', ['data' => '附加数据']);

// 动作执行失败
Result::failed('失败原因');

// 动作需要等待
Result::pending('等待原因');
```

## 使用场景

### 1. 自定义购物车条件

创建基于购物车属性的条件判断：

```php
class CartTotalCondition implements ConditionInterface
{
    public function validate(array $params, $context = null): Result
    {
        $cart = $context['cart'] ?? null;
        $minTotal = $params['min_total'] ?? 0;
        
        if ($cart && $cart->getTotal() >= $minTotal) {
            return Result::passed();
        }
        
        return Result::failed("购物车金额不足 {$minTotal} 元");
    }
}
```

### 2. 自定义折扣动作

创建折扣执行动作：

```php
class CustomDiscountAction implements ActionInterface
{
    public function execute(array $params, $context = null): Result
    {
        $discountType = $params['type'] ?? 'percentage';
        $discountValue = $params['value'] ?? 0;
        
        // 应用折扣
        $this->applyDiscount($discountType, $discountValue, $context);
        
        return Result::success("已应用折扣");
    }
}
```

### 3. 用户行为条件

创建基于用户行为的条件：

```php
class UserVipLevelCondition implements ConditionInterface
{
    public function validate(array $params, $context = null): Result
    {
        $user = $context['user'] ?? null;
        $requiredLevel = $params['level'] ?? 'gold';
        
        if ($user && $user->getVipLevel() === $requiredLevel) {
            return Result::passed();
        }
        
        return Result::failed("用户等级不符合要求");
    }
}
```

## 最佳实践

1. **唯一标识**: 使用清晰、唯一的代码标识符
2. **错误处理**: 完善异常处理和错误消息
3. **参数验证**: 在 validate/execute 方法中验证参数有效性
4. **文档注释**: 为所有方法添加详细的文档注释
5. **性能优化**: 避免在条件判断中执行耗时操作
6. **可测试性**: 编写单元测试确保扩展点正常工作

## 常见问题

### Q: 条件可以访问哪些上下文信息？

A: 上下文信息由调用方传入，通常包含订单、购物车、用户等信息。具体取决于调用场景。

### Q: 动作可以修改订单状态吗？

A: 可以，但需要谨慎处理，确保不会破坏业务逻辑的完整性。

### Q: 如何调试扩展点？

A: 可以在 validate/execute 方法中添加日志记录，或者使用调试工具查看执行过程。

### Q: 多个条件/动作可以组合使用吗？

A: 可以，营销规则引擎支持条件组合（AND/OR）和多个动作的执行。

## 相关文档

详细开发文档请参考：`doc/扩展开发文档.md`
