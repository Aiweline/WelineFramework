# 组件 Meta 信息使用示例

## 概述

本文档展示如何使用 `ComponentMetaParser` 类来解析和使用组件 Meta 信息。

## 基本使用

### 1. 解析组件 Meta 信息

```php
use Weline\Theme\Helper\ComponentMetaParser;

// 解析组件文件
$filePath = 'app/code/Weline/Theme/view/theme/frontend/components/pagination.phtml';
$meta = ComponentMetaParser::parse($filePath);

// 输出结果
print_r($meta);
/*
Array
(
    [component] => Pagination
    [description] => 分页组件，用于数据分页导航
    [params] => Array
        (
            [0] => Array
                (
                    [type] => int
                    [name] => currentPage
                    [description] => 当前页码
                    [required] => 1
                    [default] => (int)($this->getRequest()->getParam('page') ?: 1)
                )
            [1] => Array
                (
                    [type] => int
                    [name] => totalPages
                    [description] => 总页数
                    [required] => 1
                    [default] => 1
                )
            ...
        )
)
*/
```

### 2. 生成参数默认值代码

```php
use Weline\Theme\Helper\ComponentMetaParser;

$meta = ComponentMetaParser::parse($filePath);

// 为单个参数生成代码
$param = $meta['params'][0]; // currentPage
$code = ComponentMetaParser::generateDefaultValueCode($param);
echo $code;
// 输出: $currentPage = (int)($this->getData('currentPage') ?? (int)($this->getRequest()->getParam('page') ?: 1));

// 为所有参数生成代码
$codes = ComponentMetaParser::generateAllDefaultValueCodes($meta['params']);
foreach ($codes as $name => $code) {
    echo $code . "\n";
}
```

### 3. 验证参数类型

```php
use Weline\Theme\Helper\ComponentMetaParser;

$param = [
    'type' => 'int',
    'name' => 'currentPage',
    'default' => '1'
];

$value = 5;
$isValid = ComponentMetaParser::validateType($value, $param['type']);
// true

$value = '5';
$isValid = ComponentMetaParser::validateType($value, $param['type']);
// false
```

### 4. 生成文档

```php
use Weline\Theme\Helper\ComponentMetaParser;

$meta = ComponentMetaParser::parse($filePath);
$doc = ComponentMetaParser::formatAsDocumentation($meta);
echo $doc;

/*
## Pagination

分页组件，用于数据分页导航

### 参数列表

| 参数名 | 类型 | 必填 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `currentPage` | `int` | 是 | `(int)($this->getRequest()->getParam('page') ?: 1)` | 当前页码 |
| `totalPages` | `int` | 是 | `1` | 总页数 |
...
*/
```

## 实际应用场景

### 场景 1：自动生成组件配置表单

```php
use Weline\Theme\Helper\ComponentMetaParser;

class ComponentConfigForm
{
    public function generateForm(string $componentPath): string
    {
        $meta = ComponentMetaParser::parse($componentPath);
        $html = '<form>';
        
        foreach ($meta['params'] as $param) {
            $html .= $this->generateField($param);
        }
        
        $html .= '</form>';
        return $html;
    }
    
    private function generateField(array $param): string
    {
        $name = $param['name'];
        $type = $param['type'];
        $required = $param['required'] ? 'required' : '';
        $default = $param['default'] ?? '';
        $description = $param['description'];
        
        $html = '<div class="form-group">';
        $html .= "<label>{$description}";
        if ($param['required']) {
            $html .= ' <span class="required">*</span>';
        }
        $html .= '</label>';
        
        switch ($type) {
            case 'bool':
                $html .= "<input type='checkbox' name='{$name}' value='1' {$required}>";
                break;
            case 'int':
            case 'float':
                $html .= "<input type='number' name='{$name}' value='{$default}' {$required}>";
                break;
            case 'array':
                $html .= "<textarea name='{$name}' {$required}></textarea>";
                break;
            default:
                $html .= "<input type='text' name='{$name}' value='{$default}' {$required}>";
        }
        
        if ($default) {
            $html .= "<small class='form-text text-muted'>默认值: {$default}</small>";
        }
        
        $html .= '</div>';
        return $html;
    }
}
```

### 场景 2：组件代码生成工具

```php
use Weline\Theme\Helper\ComponentMetaParser;

class ComponentCodeGenerator
{
    public function generateComponentCode(string $componentPath): string
    {
        $meta = ComponentMetaParser::parse($componentPath);
        
        $code = "<?php\n";
        $code .= "/**\n";
        $code .= " * 组件：{$meta['component']}\n";
        $code .= " * \n";
        $code .= " * {$meta['description']}\n";
        $code .= " */\n\n";
        
        // 生成参数获取代码
        $codes = ComponentMetaParser::generateAllDefaultValueCodes($meta['params']);
        foreach ($codes as $name => $paramCode) {
            $code .= $paramCode . "\n";
        }
        
        $code .= "\n// ... 组件代码 ...\n";
        $code .= "?>\n";
        
        return $code;
    }
}
```

### 场景 3：组件文档自动生成

```php
use Weline\Theme\Helper\ComponentMetaParser;

class ComponentDocumentationGenerator
{
    public function generateAllComponentDocs(string $componentsDir): void
    {
        $files = glob($componentsDir . '/*.phtml');
        
        foreach ($files as $file) {
            $meta = ComponentMetaParser::parse($file);
            $doc = ComponentMetaParser::formatAsDocumentation($meta);
            
            $docPath = dirname($file) . '/docs/' . basename($file, '.phtml') . '.md';
            file_put_contents($docPath, $doc);
        }
    }
}
```

### 场景 4：组件参数验证

```php
use Weline\Theme\Helper\ComponentMetaParser;

class ComponentValidator
{
    public function validateComponentData(string $componentPath, array $data): array
    {
        $meta = ComponentMetaParser::parse($componentPath);
        $errors = [];
        
        foreach ($meta['params'] as $param) {
            $name = $param['name'];
            $type = $param['type'];
            $required = $param['required'];
            
            // 检查必填参数
            if ($required && !isset($data[$name])) {
                $errors[] = "参数 {$name} 是必填的";
                continue;
            }
            
            // 检查类型
            if (isset($data[$name]) && !ComponentMetaParser::validateType($data[$name], $type)) {
                $errors[] = "参数 {$name} 的类型应该是 {$type}";
            }
        }
        
        return $errors;
    }
}
```

## 在模板中使用

### 示例：动态加载组件并应用默认值

```php
<?php
use Weline\Theme\Helper\ComponentMetaParser;

// 解析组件 Meta 信息
$componentPath = 'Weline_Theme::frontend/components/pagination.phtml';
$fullPath = $this->getTemplateFile($componentPath);
$meta = ComponentMetaParser::parse($fullPath);

// 根据 Meta 信息设置默认值
foreach ($meta['params'] as $param) {
    $name = $param['name'];
    if (!$this->hasData($name) && isset($param['default'])) {
        // 如果是 PHP 表达式，需要执行
        if (strpos($param['default'], '$') !== false) {
            // 注意：这里需要安全地执行 PHP 代码
            // 实际应用中应该使用更安全的方式
            eval("\$defaultValue = {$param['default']};");
            $this->assign($name, $defaultValue);
        } else {
            // 字面量默认值
            $this->assign($name, $param['default']);
        }
    }
}

// 加载组件
echo $this->fetch($componentPath);
?>
```

## 注意事项

1. **安全性**：当处理包含 PHP 表达式的默认值时，需要确保代码执行的安全性
2. **性能**：解析 Meta 信息会有一定的性能开销，建议缓存解析结果
3. **兼容性**：确保 PHP 表达式在组件执行上下文中可用
4. **类型转换**：根据实际需要，可能需要额外的类型转换逻辑

## 扩展建议

1. **缓存机制**：添加解析结果的缓存，避免重复解析
2. **表达式执行器**：创建安全的 PHP 表达式执行器
3. **IDE 支持**：生成 IDE 自动补全的元数据文件
4. **测试工具**：创建组件参数测试工具

