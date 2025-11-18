# Weline Framework API 注释文档规范设计方案

## 一、设计目标

在 `app\code\Weline\Framework\Api` 下建立统一的API注释文档规范系统，实现：

1. **统一规范**：定义标准的API注释格式，确保所有API接口注释一致
2. **自动验证**：在路由注册时自动验证API注释是否符合规范
3. **拦截机制**：不符合规范的接口会被拦截，不允许注册或访问
4. **文档生成**：基于规范化的注释自动生成API文档
5. **开发规范**：通过规范约束，引导开发者正确编写API接口

## 二、架构设计

### 2.1 目录结构

```
app/code/Weline/Framework/Api/
├── ApiDoc.php                    # API文档注释Attribute（类似Acl）
├── ApiDocInterface.php           # API文档接口定义
├── Validator/
│   ├── ApiDocValidator.php       # API注释验证器
│   └── ValidationResult.php      # 验证结果
├── Interceptor/
│   └── ApiDocInterceptor.php     # API注释拦截器（Observer）
├── Service/
│   ├── ApiDocParser.php          # API注释解析服务
│   └── ApiDocGenerator.php       # API文档生成服务
├── Exception/
│   └── ApiDocException.php       # API文档异常
└── doc/
    └── API注释规范.md            # 规范文档
```

### 2.2 核心组件

#### 2.2.1 ApiDoc Attribute

类似于 `Acl` Attribute，用于标记API接口的文档信息。

#### 2.2.2 ApiDocValidator

验证API注释是否符合规范，包括：
- 必填字段检查
- 格式验证
- 类型验证
- 完整性检查

#### 2.2.3 ApiDocInterceptor

在路由注册时拦截，验证API注释，不符合规范的接口不允许注册。

#### 2.2.4 ApiDocParser

解析API注释，提取文档信息。

#### 2.2.5 ApiDocGenerator

基于解析的注释信息生成API文档。

## 三、API注释规范

### 3.1 规范格式

API接口必须同时满足以下要求：

1. **Attribute标记**：使用 `#[\Weline\Framework\Api\ApiDoc]` Attribute
2. **PHPDoc注释**：方法必须有完整的PHPDoc注释
3. **参数注释**：所有参数必须有 `@param` 注释
4. **返回值注释**：必须有 `@return` 注释
5. **示例注释**：建议提供 `@example` 注释

### 3.2 ApiDoc Attribute 定义

```php
<?php

namespace Weline\Framework\Api;

use Weline\Framework\Attribute\RouterAttributeInterface;
use Weline\Framework\DataObject\DataObject;

/**
 * API文档注释Attribute
 * 
 * 用于标记API接口的文档信息，必须与方法注释配合使用
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class ApiDoc extends DataObject implements RouterAttributeInterface
{
    /**
     * @param string $summary API接口摘要（必填）
     * @param string $description API接口详细描述（可选）
     * @param string $version API版本（可选，默认v1）
     * @param bool $deprecated 是否已废弃（可选，默认false）
     * @param string $deprecatedReason 废弃原因（deprecated=true时必填）
     * @param array $tags 标签列表（可选）
     * @param string $category 分类（可选）
     */
    public function __construct(
        string $summary,
        string $description = '',
        string $version = 'v1',
        bool $deprecated = false,
        string $deprecatedReason = '',
        array $tags = [],
        string $category = ''
    ) {
        parent::__construct([
            'summary' => $summary,
            'description' => $description,
            'version' => $version,
            'deprecated' => $deprecated,
            'deprecated_reason' => $deprecatedReason,
            'tags' => $tags,
            'category' => $category,
        ]);
    }
    
    // Getter/Setter方法...
}
```

### 3.3 PHPDoc注释规范

```php
/**
 * API接口的PHPDoc注释规范
 * 
 * @param string $param1 参数1描述
 * @param int $param2 参数2描述
 * @param array $param3 参数3描述（可选）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 * @throws \Exception 异常情况说明
 * @example
 * 请求示例：
 * GET /api/v1/user/1
 * 
 * 响应示例：
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "id": 1,
 *     "name": "张三"
 *   }
 * }
 */
```

### 3.4 完整示例

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Api\ApiDoc;
use Weline\Framework\Controller\AbstractRestController;

class User extends AbstractRestController
{
    /**
     * 获取用户信息
     * 
     * @param int $id 用户ID（必填）
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"id": 1, "name": "张三"}}
     * @throws \Exception 用户不存在时抛出异常
     * @example
     * 请求：GET /api/v1/user/1
     * 响应：{"code": 0, "msg": "success", "data": {"id": 1, "name": "张三"}}
     */
    #[\Weline\Framework\Api\ApiDoc(
        summary: '获取用户信息',
        description: '根据用户ID获取用户详细信息，包括基本信息、权限等',
        version: 'v1',
        tags: ['用户管理', '用户信息'],
        category: '用户'
    )]
    public function get(int $id): array
    {
        // 实现代码...
        return $this->success(['id' => $id, 'name' => '张三']);
    }
    
    /**
     * 创建用户
     * 
     * @param string $name 用户名（必填，3-50字符）
     * @param string $email 邮箱（必填，邮箱格式）
     * @param string $password 密码（必填，最少6字符）
     * @param array $roles 角色列表（可选）
     * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1}}
     * @throws \Exception 创建失败时抛出异常
     * @example
     * 请求：POST /api/v1/user
     * Body: {"name": "张三", "email": "zhangsan@example.com", "password": "123456"}
     * 响应：{"code": 0, "msg": "创建成功", "data": {"id": 1}}
     */
    #[\Weline\Framework\Api\ApiDoc(
        summary: '创建用户',
        description: '创建新用户，需要提供用户名、邮箱和密码',
        version: 'v1',
        tags: ['用户管理', '用户创建'],
        category: '用户'
    )]
    public function post(
        string $name,
        string $email,
        string $password,
        array $roles = []
    ): array {
        // 实现代码...
        return $this->success(['id' => 1]);
    }
}
```

## 四、验证规则

### 4.1 必填验证

1. **ApiDoc Attribute**：必须存在
2. **summary**：必须提供
3. **PHPDoc注释**：方法必须有PHPDoc注释
4. **@param注释**：所有方法参数必须有 `@param` 注释
5. **@return注释**：必须有 `@return` 注释

### 4.2 格式验证

1. **summary长度**：1-200字符
2. **description长度**：0-1000字符（可选）
3. **version格式**：必须符合语义化版本（如 v1, v1.0, v1.0.0）
4. **tags格式**：数组，每个标签1-50字符
5. **category格式**：1-100字符（可选）

### 4.3 完整性验证

1. **参数匹配**：方法参数与 `@param` 注释必须匹配
2. **类型匹配**：参数类型声明与 `@param` 类型必须匹配（如果提供了类型）
3. **返回值匹配**：方法返回类型与 `@return` 类型必须匹配（如果提供了类型）

### 4.4 废弃接口验证

如果 `deprecated = true`：
- `deprecatedReason` 必须提供
- 建议在PHPDoc中添加 `@deprecated` 标签

## 五、拦截机制

### 5.1 拦截时机

在 `Weline_Module::controller_attributes` 事件中拦截，在路由注册之前验证。

### 5.2 拦截流程

```
1. 路由注册时触发 Weline_Module::controller_attributes 事件
2. ApiDocInterceptor 监听该事件
3. 检查是否为API接口（type === 'api'）
4. 如果是API接口，调用 ApiDocValidator 验证
5. 验证失败：
   - 记录错误日志
   - 阻止路由注册（设置 is_enable = false）
   - 返回错误信息
6. 验证成功：
   - 允许路由注册
   - 保存API文档信息
```

### 5.3 拦截器实现

```php
<?php

namespace Weline\Framework\Api\Interceptor;

use Weline\Framework\Api\Validator\ApiDocValidator;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class ApiDocInterceptor implements ObserverInterface
{
    private ApiDocValidator $validator;
    
    public function __construct()
    {
        $this->validator = ObjectManager::getInstance(ApiDocValidator::class);
    }
    
    public function execute(Event &$event): void
    {
        /** @var DataObject $data */
        $data = $event->getData('data');
        
        // 只处理API接口
        if ($data->getData('type') !== 'api') {
            return;
        }
        
        $params = $data->getData('params');
        $class = $params['class'] ?? '';
        $method = $params['method'] ?? '';
        
        if (empty($class) || empty($method)) {
            return;
        }
        
        // 验证API注释
        $result = $this->validator->validate($class, $method);
        
        if (!$result->isValid()) {
            // 验证失败，阻止路由注册
            $params['is_enable'] = false;
            $data->setData('params', $params);
            
            // 记录错误日志
            error_log(sprintf(
                '[API Doc Validation Failed] Class: %s, Method: %s, Errors: %s',
                $class,
                $method,
                implode(', ', $result->getErrors())
            ));
            
            // 在开发环境抛出异常，生产环境只记录日志
            if (defined('DEBUG') && DEBUG) {
                throw new \Weline\Framework\Api\Exception\ApiDocException(
                    sprintf(
                        'API接口注释不符合规范: %s::%s() - %s',
                        $class,
                        $method,
                        implode(', ', $result->getErrors())
                    )
                );
            }
        } else {
            // 验证成功，保存API文档信息
            $apiDoc = $result->getApiDoc();
            $params['api_doc'] = $apiDoc->getData();
            $data->setData('params', $params);
        }
    }
}
```

## 六、验证器实现

### 6.1 验证器接口

```php
<?php

namespace Weline\Framework\Api\Validator;

interface ApiDocValidatorInterface
{
    /**
     * 验证API注释
     * 
     * @param string $className 类名
     * @param string $methodName 方法名
     * @return ValidationResult 验证结果
     */
    public function validate(string $className, string $methodName): ValidationResult;
}
```

### 6.2 验证器实现

```php
<?php

namespace Weline\Framework\Api\Validator;

use Weline\Framework\Api\ApiDoc;
use Weline\Framework\Reflection\ReflectionClass;
use Weline\Framework\Reflection\ReflectionMethod;

class ApiDocValidator implements ApiDocValidatorInterface
{
    public function validate(string $className, string $methodName): ValidationResult
    {
        $result = new ValidationResult();
        
        try {
            $reflection = new ReflectionClass($className);
            $method = $reflection->getMethod($methodName);
            
            // 1. 检查ApiDoc Attribute
            $apiDocAttributes = $method->getAttributes(ApiDoc::class);
            if (empty($apiDocAttributes)) {
                $result->addError('缺少ApiDoc Attribute');
                return $result;
            }
            
            /** @var ApiDoc $apiDoc */
            $apiDoc = $apiDocAttributes[0]->newInstance();
            
            // 2. 验证summary
            $summary = $apiDoc->getData('summary');
            if (empty($summary)) {
                $result->addError('summary不能为空');
            } elseif (strlen($summary) > 200) {
                $result->addError('summary长度不能超过200字符');
            }
            
            // 3. 验证description
            $description = $apiDoc->getData('description');
            if (!empty($description) && strlen($description) > 1000) {
                $result->addError('description长度不能超过1000字符');
            }
            
            // 4. 验证version
            $version = $apiDoc->getData('version');
            if (!preg_match('/^v\d+(\.\d+)?(\.\d+)?$/', $version)) {
                $result->addError('version格式不正确，应为v1、v1.0或v1.0.0格式');
            }
            
            // 5. 验证deprecated
            if ($apiDoc->getData('deprecated') && empty($apiDoc->getData('deprecated_reason'))) {
                $result->addError('deprecated为true时，deprecated_reason不能为空');
            }
            
            // 6. 验证PHPDoc注释
            $docComment = $method->getDocComment();
            if (empty($docComment)) {
                $result->addError('方法缺少PHPDoc注释');
            } else {
                // 解析PHPDoc
                $this->validatePhpDoc($method, $docComment, $result);
            }
            
            if ($result->isValid()) {
                $result->setApiDoc($apiDoc);
            }
            
        } catch (\ReflectionException $e) {
            $result->addError('反射异常: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 验证PHPDoc注释
     */
    private function validatePhpDoc(ReflectionMethod $method, string $docComment, ValidationResult $result): void
    {
        // 1. 检查@param注释
        $parameters = $method->getParameters();
        $paramComments = $this->extractParamComments($docComment);
        
        foreach ($parameters as $param) {
            $paramName = $param->getName();
            if (!isset($paramComments[$paramName])) {
                $result->addError("参数 {$paramName} 缺少@param注释");
            } else {
                // 验证类型匹配
                $paramType = $param->getType();
                if ($paramType && !$param->isOptional()) {
                    $commentType = $paramComments[$paramName]['type'] ?? '';
                    if (!empty($commentType) && !$this->isTypeMatch($paramType->getName(), $commentType)) {
                        $result->addError("参数 {$paramName} 的类型声明与@param注释不匹配");
                    }
                }
            }
        }
        
        // 2. 检查@return注释
        if (!preg_match('/@return\s+\S+/', $docComment)) {
            $result->addError('缺少@return注释');
        } else {
            // 验证返回类型匹配
            $returnType = $method->getReturnType();
            if ($returnType) {
                $returnComment = $this->extractReturnComment($docComment);
                if (!empty($returnComment) && !$this->isTypeMatch($returnType->getName(), $returnComment)) {
                    $result->addError('返回类型声明与@return注释不匹配');
                }
            }
        }
    }
    
    /**
     * 提取@param注释
     */
    private function extractParamComments(string $docComment): array
    {
        $params = [];
        if (preg_match_all('/@param\s+(\S+)\s+\$(\w+)\s+(.*)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[$match[2]] = [
                    'type' => $match[1],
                    'description' => $match[3] ?? ''
                ];
            }
        }
        return $params;
    }
    
    /**
     * 提取@return注释
     */
    private function extractReturnComment(string $docComment): string
    {
        if (preg_match('/@return\s+(\S+)/', $docComment, $matches)) {
            return $matches[1];
        }
        return '';
    }
    
    /**
     * 检查类型是否匹配
     */
    private function isTypeMatch(string $declaredType, string $commentType): bool
    {
        // 简单的类型匹配检查
        $typeMap = [
            'int' => ['int', 'integer'],
            'string' => ['string', 'str'],
            'array' => ['array'],
            'bool' => ['bool', 'boolean'],
            'float' => ['float', 'double'],
        ];
        
        $declaredType = strtolower($declaredType);
        $commentType = strtolower($commentType);
        
        if (isset($typeMap[$declaredType])) {
            return in_array($commentType, $typeMap[$declaredType]);
        }
        
        return $declaredType === $commentType;
    }
}
```

## 七、配置选项

### 7.1 配置文件

在 `app/etc/env.php` 中添加配置：

```php
'api_doc' => [
    'enabled' => true,                    // 是否启用API注释验证
    'strict_mode' => false,               // 严格模式（验证失败时阻止路由注册）
    'dev_mode' => true,                   // 开发模式（验证失败时抛出异常）
    'required_fields' => [                // 必填字段
        'summary',
        'phpdoc',
        'param_comments',
        'return_comment'
    ],
    'validation_rules' => [               // 验证规则
        'summary_max_length' => 200,
        'description_max_length' => 1000,
        'tag_max_length' => 50,
        'category_max_length' => 100
    ]
]
```

### 7.2 环境区分

- **开发环境**：严格验证，验证失败抛出异常
- **测试环境**：验证并记录日志，但不阻止路由注册
- **生产环境**：只记录日志，不阻止路由注册

## 八、使用示例

### 8.1 正确的API接口

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Api\ApiDoc;
use Weline\Framework\Controller\AbstractRestController;

class Product extends AbstractRestController
{
    /**
     * 获取商品列表
     * 
     * @param int $page 页码（可选，默认1）
     * @param int $pageSize 每页数量（可选，默认20）
     * @param string $keyword 搜索关键词（可选）
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"list": [], "total": 0}}
     */
    #[\Weline\Framework\Api\ApiDoc(
        summary: '获取商品列表',
        description: '分页获取商品列表，支持关键词搜索',
        version: 'v1',
        tags: ['商品', '列表'],
        category: '商品管理'
    )]
    public function getList(
        int $page = 1,
        int $pageSize = 20,
        string $keyword = ''
    ): array {
        // 实现代码...
        return $this->success(['list' => [], 'total' => 0]);
    }
}
```

### 8.2 错误的API接口（会被拦截）

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;

class Product extends AbstractRestController
{
    // ❌ 错误：缺少ApiDoc Attribute
    // ❌ 错误：缺少PHPDoc注释
    // ❌ 错误：缺少@param和@return注释
    public function getList(int $page = 1): array
    {
        return $this->success([]);
    }
}
```

## 九、与Weline\Api模块集成

### 9.1 文档生成集成

`Weline\Api` 模块的文档生成功能可以：
1. 读取已验证的API文档信息
2. 结合PHPDoc注释生成完整的API文档
3. 自动提取参数、返回值、示例等信息

### 9.2 验证结果展示

在API文档界面可以显示：
- 哪些接口通过了验证
- 哪些接口验证失败（开发环境）
- 验证错误详情

## 十、实施步骤

### 阶段一：基础框架（1-2天）
1. 创建 `ApiDoc` Attribute
2. 创建验证器基础结构
3. 创建拦截器基础结构

### 阶段二：验证规则（2-3天）
1. 实现必填验证
2. 实现格式验证
3. 实现完整性验证

### 阶段三：拦截机制（1-2天）
1. 实现事件监听
2. 实现路由拦截
3. 实现错误处理

### 阶段四：文档生成（2-3天）
1. 实现注释解析
2. 实现文档生成
3. 与Weline\Api模块集成

### 阶段五：测试和优化（1-2天）
1. 编写测试用例
2. 性能优化
3. 文档完善

## 十一、优势

1. **统一规范**：所有API接口遵循相同的注释规范
2. **自动验证**：开发时自动发现不符合规范的接口
3. **文档自动生成**：基于规范化的注释自动生成文档
4. **开发友好**：IDE可以自动提示和补全
5. **可扩展**：可以轻松添加新的验证规则

## 十二、注意事项

1. **向后兼容**：现有接口需要逐步迁移，可以设置过渡期
2. **性能影响**：验证过程应该快速，避免影响路由注册性能
3. **错误提示**：验证失败时应该提供清晰的错误信息
4. **配置灵活**：不同环境可以有不同的验证策略

---

**文档版本**: 1.0  
**创建日期**: 2025-01-XX  
**最后更新**: 2025-01-XX

