<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | PHP 代码生成器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Generator
 */

namespace Weline\Framework\View\Taglib\Generator;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TextNode,
    PhpPlaceholder,
    TagNode,
    AttrNode
};
use Weline\Framework\View\Taglib\Parser\PhpExtractor;

/**
 * PHP 代码生成器
 * 
 * 将 AST 转换为 PHP 代码
 */
final class CodeGenerator
{
    /**
     * 标签回调注册表
     * @var array<string, callable>
     */
    private array $tagCallbacks = [];

    /**
     * PHP 提取器（用于恢复占位符）
     */
    private ?PhpExtractor $phpExtractor = null;
    
    /**
     * 源码映射（调试模式下使用）
     */
    private ?\Weline\Framework\View\Taglib\Debug\SourceMap $sourceMap = null;

    /**
     * 重置内部状态，允许实例复用（避免每次编译创建新实例）
     */
    public function reset(): void
    {
        $this->tagCallbacks = [];
        $this->phpExtractor = null;
        $this->sourceMap = null;
        $this->currentLine = 1;
    }

    /**
     * 设置 PHP 提取器
     */
    public function setPhpExtractor(PhpExtractor $extractor): void
    {
        $this->phpExtractor = $extractor;
    }
    
    /**
     * 设置源码映射
     */
    public function setSourceMap(\Weline\Framework\View\Taglib\Debug\SourceMap $sourceMap): void
    {
        $this->sourceMap = $sourceMap;
    }
    
    /**
     * 获取源码映射
     */
    public function getSourceMap(): ?\Weline\Framework\View\Taglib\Debug\SourceMap
    {
        return $this->sourceMap;
    }

    /**
     * 注册标签代码生成回调
     */
    public function registerTag(string $name, callable $callback): void
    {
        $this->tagCallbacks[$name] = $callback;
    }

    /**
     * 批量注册标签回调
     */
    public function registerTags(array $callbacks): void
    {
        foreach ($callbacks as $name => $callback) {
            $this->tagCallbacks[$name] = $callback;
        }
    }

    /**
     * 当前生成的行号（用于 SourceMap）
     */
    private int $currentLine = 1;

    /**
     * 生成 PHP 代码
     */
    public function generate(ProgramNode $ast): string
    {
        $this->currentLine = 1;
        return $this->generateNodes($ast->children);
    }

    /**
     * 生成节点列表的代码
     * 
     * @param array<Node> $nodes
     */
    public function generateNodes(array $nodes): string
    {
        $output = '';

        foreach ($nodes as $node) {
            $output .= $this->generateNode($node);
        }

        return $output;
    }

    /**
     * 生成单个节点的代码
     */
    public function generateNode(Node $node): string
    {
        // 记录源码映射（如果启用）
        if ($this->sourceMap !== null) {
            $this->sourceMap->addMapping($this->currentLine, $node->line);
        }
        
        // 分支预测优化：按频率排序
        
        // 1. 文本节点（最常见）
        if ($node instanceof TextNode) {
            $code = $node->value;
            $this->currentLine += substr_count($code, "\n");
            return $code;
        }

        // 2. PHP 占位符
        if ($node instanceof PhpPlaceholder) {
            $code = $this->generatePlaceholder($node);
            $this->currentLine += substr_count($code, "\n");
            return $code;
        }

        // 3. 标签节点
        if ($node instanceof TagNode) {
            $code = $this->generateTagNode($node);
            $this->currentLine += substr_count($code, "\n");
            return $code;
        }

        return '';
    }

    /**
     * 生成占位符代码
     */
    private function generatePlaceholder(PhpPlaceholder $node): string
    {
        // 检查是否为双花括号变量（占位符以 __VAR_ 开头）
        if (str_starts_with($node->placeholder, '__VAR_')) {
            // 生成变量输出代码
            return $this->generateVariableCode($node->expression, $node->line);
        }
        
        // 使用提取器恢复原始代码
        if ($this->phpExtractor !== null) {
            $info = $this->phpExtractor->getPlaceholderInfo($node->placeholder);
            if ($info !== null) {
                return $info['code'];
            }
        }

        // 回退：直接返回占位符
        return $node->placeholder;
    }
    
    /**
     * 生成双花括号变量的 PHP 代码
     */
    private function generateVariableCode(string $varPath, int $line): string
    {
        // 解析变量路径
        $parsedPath = $this->parseVariablePath($varPath);
        
        // 生成 echo 语句
        return '<?php echo ' . $parsedPath . '; ?>';
    }
    
    /**
     * 解析变量路径为 PHP 表达式
     * 
     * 支持格式：
     * - variable -> ($variable ?? $this->getData('variable'))  // 优先局部变量
     * - $variable -> $variable
     * - object.property -> $this->getData('object')['property']
     * - $_SERVER.KEY -> $_SERVER['KEY']
     * - expr1 | expr2 -> ($expr1 ?? $expr2)  // 管道符用于回退值
     */
    private function parseVariablePath(string $varPath): string
    {
        $varPath = trim($varPath);
        
        // 排除 || 逻辑运算符，仅匹配单个 | 作为管道/默认值分隔符
        if (preg_match('/(?<!\|)\|(?!\|)/', $varPath)) {
            $parts = array_map('trim', preg_split('/(?<!\|)\|(?!\|)/', $varPath, 2));
            $primary = $this->parseSingleVariablePath($parts[0]);
            $fallbackPart = $parts[1];
            
            // 处理 default:value 过滤器语法（如 default:"" 或 default:$var）
            if (str_starts_with($fallbackPart, 'default:')) {
                $defaultValue = trim(substr($fallbackPart, 8));
                if ($defaultValue === '') {
                    $defaultValue = "''";
                }
                // 引号字符串、数字、布尔/null 字面量直接使用
                if (preg_match('/^(["\']).*\1$/', $defaultValue)
                    || is_numeric($defaultValue)
                    || in_array(strtolower($defaultValue), ['true', 'false', 'null'], true)) {
                    return '(' . $primary . ' ?? ' . $defaultValue . ')';
                }
                // 变量引用
                if (str_starts_with($defaultValue, '$')) {
                    return '(' . $primary . ' ?? ' . $defaultValue . ')';
                }
                // 其他情况按变量路径解析
                $fallback = $this->parseSingleVariablePath($defaultValue);
                return '(' . $primary . ' ?? ' . $fallback . ')';
            }
            
            $fallback = $this->parseSingleVariablePath($fallbackPart);
            return '(' . $primary . ' ?? ' . $fallback . ')';
        }
        
        return $this->parseSingleVariablePath($varPath);
    }
    
    /**
     * 解析单个变量路径（不含管道符）
     */
    private function parseSingleVariablePath(string $varPath): string
    {
        $varPath = trim($varPath);
        
        // 如果是字符串字面量（以引号开头），直接返回
        if (str_starts_with($varPath, "'") || str_starts_with($varPath, '"')) {
            return $varPath;
        }
        
        // 如果是数字字面量，直接返回
        if (is_numeric($varPath)) {
            return $varPath;
        }
        
        // 如果是布尔字面量或 null，直接返回
        if (in_array(strtolower($varPath), ['true', 'false', 'null'], true)) {
            return $varPath;
        }
        
        // 如果已经是 PHP 变量格式（以 $ 开头）
        if (str_starts_with($varPath, '$')) {
            // 处理点号分隔的属性访问
            if (str_contains($varPath, '.')) {
                $parts = explode('.', $varPath);
                $result = array_shift($parts);
                // 对每个属性访问添加 null 安全检查
                foreach ($parts as $part) {
                    $result = '(' . $result . ' ?? [])[\'' . $part . '\']';
                }
                return $result;
            }
            return $varPath;
        }
        
        // 特殊处理 $_SERVER, $_GET, $_POST 等超全局变量
        if (preg_match('/^_([A-Z]+)\.(.+)$/', $varPath, $m)) {
            return '$_' . $m[1] . '[\'' . $m[2] . '\']';
        }
        
        // 处理点号分隔的路径（如 user.name）
        if (str_contains($varPath, '.')) {
            $parts = explode('.', $varPath);
            $first = array_shift($parts);
            // 优先检查局部变量，然后使用 getData
            // 添加空数组作为最终回退，避免对 null 进行数组访问
            $result = '($' . $first . ' ?? $this->getData(\'' . $first . '\') ?? [])';
            foreach ($parts as $part) {
                // 每一级属性访问都添加 null 安全检查
                $result = '(' . $result . '[\'' . $part . '\'] ?? null)';
            }
            return $result;
        }
        
        // 简单变量名：优先检查局部变量，然后使用 getData
        // 这样在 foreach 循环中的变量可以正确解析
        return '($' . $varPath . ' ?? $this->getData(\'' . $varPath . '\'))';
    }

    /**
     * 生成标签节点代码
     */
    private function generateTagNode(TagNode $node): string
    {
        // 编译期标签使用回调生成代码
        if ($node->stage === TagNode::STAGE_COMPILE) {
            return $this->generateCompileTimeTag($node);
        }

        // 运行期标签生成运行时调用
        return $this->generateRuntimeTag($node);
    }

    /**
     * 生成编译期标签代码
     */
    private function generateCompileTimeTag(TagNode $node): string
    {
        // 优先检查内置标签
        $builtinResult = $this->generateBuiltinTag($node);
        if ($builtinResult !== null) {
            return $builtinResult;
        }

        $callback = $this->tagCallbacks[$node->name] ?? null;

        if ($callback !== null) {
            $params = $this->buildTagParams($node);
            $result = $callback($params);
            
            if (is_string($result)) {
                return $result;
            }
        }

        // 无回调，生成默认输出
        return $this->generateDefaultTag($node);
    }

    /**
     * 生成内置标签代码
     * 
     * @return string|null 返回 null 表示非内置标签
     */
    private function generateBuiltinTag(TagNode $node): ?string
    {
        // 检查是否是 @tag{...} 格式（内联格式）
        // 这种格式包含 '=>' 语法（如 @if{$a=>'value'}），应该由 Taglib 回调处理
        if ($node->selfClosing && $node->rawContent !== '' && str_contains($node->rawContent, '=>')) {
            return null;  // 让 Taglib 回调函数处理
        }
        
        return match ($node->name) {
            'if' => $this->generateIfTag($node),
            'elseif' => $this->generateElseifTag($node),
            'else' => $this->generateElseTag($node),
            'foreach' => $this->generateForeachTag($node),
            'for' => $this->generateForTag($node),
            'while' => $this->generateWhileTag($node),
            'switch' => $this->generateSwitchTag($node),
            'case' => $this->generateCaseTag($node),
            'default' => $this->generateDefaultCaseTag($node),
            // 模板内联标签
            'template', 'w:template' => $this->generateTemplateTag($node),
            'include', 'w:include' => $this->generateTemplateTag($node),
            default => null,
        };
    }
    
    /**
     * 生成 template/include 标签代码 - 编译时内联模板内容
     */
    private function generateTemplateTag(TagNode $node): string
    {
        // 获取模板路径
        $templatePath = '';
        
        // 优先从 name 属性获取
        foreach ($node->attributes as $attr) {
            if ($attr->name === 'name') {
                $templatePath = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }
        
        // 从子内容获取
        if ($templatePath === '' && !empty($node->children)) {
            $templatePath = $this->buildStringFromChildren($node->children);
        }
        
        // 从 rawContent 获取
        if ($templatePath === '' && $node->rawContent !== '') {
            $templatePath = trim($node->rawContent);
        }
        
        $templatePath = trim($templatePath);
        
        if ($templatePath === '') {
            return "<!-- 警告：template 标签缺少模板路径 -->";
        }
        
        // 生成内联代码：使用 fetchTagSource 获取编译后的模板文件路径，然后 include 执行
        // 这与原始回调逻辑一致，但改用 include 来执行 PHP 代码
        $escapedPath = var_export($templatePath, true);
        return "<?php include \$this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_TEMPLATE, {$escapedPath}); ?>";
    }
    
    /**
     * 从子节点构建字符串（用于获取模板路径）
     */
    private function buildStringFromChildren(array $children): string
    {
        $result = '';
        foreach ($children as $child) {
            if ($child instanceof \Weline\Framework\View\TextNode) {
                $result .= $child->value;
            }
        }
        return trim($result);
    }

    /**
     * 生成 if 标签代码
     */
    private function generateIfTag(TagNode $node): string
    {
        $condition = '';

        // 从 condition 属性获取
        foreach ($node->attributes as $attr) {
            if ($attr->name === 'condition') {
                $condition = $attr->staticValue ?? $attr->rawValue;
                break;
            }
            // 从 value 属性获取（@if($condition) 格式）
            if ($attr->name === 'value') {
                $condition = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }

        // 从 rawContent 获取
        if ($condition === '' && $node->rawContent !== '') {
            $condition = trim($node->rawContent);
        }

        if ($condition === '') {
            return '<?php if(true): ?>';
        }

        // 解析变量表达式（如 meta.showHeader => ($meta['showHeader'] ?? null)）
        $condition = $this->parseVarExpression($condition);

        $children = $this->generateNodes($node->children);

        if ($node->selfClosing) {
            return "<?php if({$condition}): ?>";
        }

        return "<?php if({$condition}): ?>{$children}<?php endif; ?>";
    }

    /**
     * 解析变量表达式
     *
     * 将点分表达式转换为 PHP 数组访问语法
     * 例如：meta.showHeader => ($meta['showHeader'] ?? null)
     *       $user.name => $user['name']
     *
     * @param string $expr 表达式
     * @return string 解析后的 PHP 表达式
     */
    private function parseVarExpression(string $expr): string
    {
        // 如果已经包含 PHP 变量语法，不处理
        if (str_contains($expr, '<?php') || str_contains($expr, '__PHP_')) {
            return $expr;
        }

        // 保留的 PHP 关键字和常量，不应该添加 $ 前缀
        $reserved = ['true', 'false', 'null', 'and', 'or', 'xor', 'not', 'empty', 'isset', 'array', 'new', 'instanceof'];

        // 首先处理带点号的表达式（如 meta.showHeader => ($meta['showHeader'] ?? null)）
        // 使用 null 合并运算符确保安全访问，避免 undefined array key 警告
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_.]*)\b/';
        $expr = preg_replace_callback($pattern, function ($matches) {
            $var = $matches[1];
            $path = $matches[2];

            // 分割路径
            $parts = explode('.', $path);
            $result = '$' . $var;

            foreach ($parts as $part) {
                $result .= "['{$part}']";
            }

            // 添加 null 合并运算符，确保安全访问
            return '(' . $result . ' ?? null)';
        }, $expr);

        // 然后处理简单变量名（不带 $ 的标识符）
        // 匹配：非 $ 开头的独立标识符，但排除在引号内的标识符（数组键）
        // 使用负向回顾断言排除紧跟在 [' 或 [" 后面的标识符
        $expr = preg_replace_callback(
            '/(?<![\'"\[])\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?![\'"\]])/',
            function ($matches) use ($reserved) {
                $var = $matches[1];
                
                // 跳过保留关键字
                if (in_array(strtolower($var), $reserved, true)) {
                    return $var;
                }
                
                // 跳过纯数字
                if (is_numeric($var)) {
                    return $var;
                }
                
                // 添加 $ 前缀
                return '$' . $var;
            },
            $expr
        );

        // 修复可能产生的 $$ 问题
        $expr = preg_replace('/\$\$+/', '$', $expr);

        return $expr;
    }

    /**
     * 生成 elseif 标签代码
     */
    private function generateElseifTag(TagNode $node): string
    {
        $condition = '';

        foreach ($node->attributes as $attr) {
            if ($attr->name === 'condition' || $attr->name === 'value') {
                $condition = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }

        if ($condition === '' && $node->rawContent !== '') {
            $condition = trim($node->rawContent);
        }

        // 解析变量表达式
        $condition = $this->parseVarExpression($condition);

        return "<?php elseif({$condition}): ?>";
    }

    /**
     * 生成 else 标签代码
     */
    private function generateElseTag(TagNode $node): string
    {
        return '<?php else: ?>';
    }

    /**
     * 生成 foreach 标签代码
     * 
     * 支持多种语法：
     * 1. <foreach items="$items" as="$item">...</foreach>
     * 2. <foreach name="items" item="item" key="key">...</foreach>
     * 3. @foreach($items as $item) 内联格式
     */
    private function generateForeachTag(TagNode $node): string
    {
        $expr = '';
        
        // 语法 1: items/as 属性（文档推荐语法）
        $items = null;
        $as = null;
        $key = null;
        
        foreach ($node->attributes as $attr) {
            match ($attr->name) {
                'items' => $items = $attr->staticValue ?? $attr->rawValue,
                'as' => $as = $attr->staticValue ?? $attr->rawValue,
                'key' => $key = $attr->staticValue ?? $attr->rawValue,
                default => null,
            };
        }
        
        if ($items !== null && $as !== null) {
            // 确保变量以 $ 开头
            $itemsVar = str_starts_with($items, '$') ? $items : "\${$items}";
            $asVar = str_starts_with($as, '$') ? $as : "\${$as}";
            
            if ($key !== null) {
                $keyVar = str_starts_with($key, '$') ? $key : "\${$key}";
                $expr = "{$itemsVar} as {$keyVar} => {$asVar}";
            } else {
                $expr = "{$itemsVar} as {$asVar}";
            }
        }
        
        // 语法 2: name/item/key 属性（旧语法）
        if ($expr === '') {
            foreach ($node->attributes as $attr) {
                if ($attr->name === 'name') {
                    $name = $attr->staticValue ?? $attr->rawValue;
                    $item = null;
                    $key = null;
                    
                    foreach ($node->attributes as $a) {
                        if ($a->name === 'key') $key = $a->staticValue ?? $a->rawValue;
                        if ($a->name === 'item') $item = $a->staticValue ?? $a->rawValue;
                    }
                    
                    $item = $item ?: 'item';
                    $expr = $key ? "\${$name} as \${$key} => \${$item}" : "\${$name} as \${$item}";
                    break;
                }
                // 从 value 属性获取（@foreach($items as $item) 格式）
                if ($attr->name === 'value') {
                    $expr = $attr->staticValue ?? $attr->rawValue;
                    break;
                }
            }
        }

        // 从 rawContent 获取
        if ($expr === '' && $node->rawContent !== '') {
            $expr = trim($node->rawContent);
        }

        if ($expr === '') {
            return '<?php foreach([] as $item): ?>';
        }

        $children = $this->generateNodes($node->children);
        
        if ($node->selfClosing) {
            return "<?php foreach({$expr}): ?>";
        }

        return "<?php foreach({$expr}): ?>{$children}<?php endforeach; ?>";
    }

    /**
     * 生成 for 标签代码
     */
    private function generateForTag(TagNode $node): string
    {
        $start = '0';
        $end = '0';
        $step = '1';
        $item = 'i';
        
        foreach ($node->attributes as $attr) {
            match ($attr->name) {
                'start' => $start = $attr->staticValue ?? $attr->rawValue,
                'end' => $end = $attr->staticValue ?? $attr->rawValue,
                'step' => $step = $attr->staticValue ?? $attr->rawValue,
                'item', 'name' => $item = $attr->staticValue ?? $attr->rawValue,
                'value' => null, // @for(expr) 格式
                default => null,
            };
        }

        // 从 rawContent 获取（可能是完整表达式）
        if ($node->rawContent !== '' && str_contains($node->rawContent, ';')) {
            $expr = trim($node->rawContent);
            $children = $this->generateNodes($node->children);
            
            if ($node->selfClosing) {
                return "<?php for({$expr}): ?>";
            }
            return "<?php for({$expr}): ?>{$children}<?php endfor; ?>";
        }

        $children = $this->generateNodes($node->children);
        
        if ($node->selfClosing) {
            return "<?php for(\${$item}={$start}; \${$item}<={$end}; \${$item}+={$step}): ?>";
        }

        return "<?php for(\${$item}={$start}; \${$item}<={$end}; \${$item}+={$step}): ?>{$children}<?php endfor; ?>";
    }

    /**
     * 生成 while 标签代码
     */
    private function generateWhileTag(TagNode $node): string
    {
        $condition = '';

        foreach ($node->attributes as $attr) {
            if ($attr->name === 'condition' || $attr->name === 'value') {
                $condition = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }

        if ($condition === '' && $node->rawContent !== '') {
            $condition = trim($node->rawContent);
        }

        if ($condition === '') {
            $condition = 'false';
        }

        $condition = $this->parseVarExpression($condition);
        $children = $this->generateNodes($node->children);

        if ($node->selfClosing) {
            return "<?php while({$condition}): ?>";
        }

        return "<?php while({$condition}): ?>{$children}<?php endwhile; ?>";
    }

    /**
     * 生成 switch 标签代码
     */
    private function generateSwitchTag(TagNode $node): string
    {
        $expr = '';

        foreach ($node->attributes as $attr) {
            if ($attr->name === 'value' || $attr->name === 'expression') {
                $expr = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }

        if ($expr === '' && $node->rawContent !== '') {
            $expr = trim($node->rawContent);
        }

        if ($expr === '') {
            return '<?php switch(null): ?>';
        }

        $expr = $this->parseVarExpression($expr);
        $children = $this->generateNodes($node->children);

        if ($node->selfClosing) {
            return "<?php switch({$expr}): ?>";
        }

        return "<?php switch({$expr}): ?>{$children}<?php endswitch; ?>";
    }

    /**
     * 生成 case 标签代码
     */
    private function generateCaseTag(TagNode $node): string
    {
        $value = '';

        foreach ($node->attributes as $attr) {
            if ($attr->name === 'value') {
                $value = $attr->staticValue ?? $attr->rawValue;
                break;
            }
        }

        if ($value === '' && $node->rawContent !== '') {
            $value = trim($node->rawContent);
        }

        $children = $this->generateNodes($node->children);

        if ($node->selfClosing) {
            return "<?php case {$value}: ?>";
        }

        return "<?php case {$value}: ?>{$children}<?php break; ?>";
    }

    /**
     * 生成 default case 标签代码
     */
    private function generateDefaultCaseTag(TagNode $node): string
    {
        $children = $this->generateNodes($node->children);

        if ($node->selfClosing) {
            return "<?php default: ?>";
        }

        return "<?php default: ?>{$children}<?php break; ?>";
    }

    /**
     * 生成运行期标签代码
     * 
     * 支持内联优化：
     * - 自闭合标签（无子内容）：直接调用渲染器，不需要 ob_start
     * - 标记为 __INLINE_OPTIMIZED__ 的标签：跳过缓冲区
     * - 标记为 __STATIC_CHILDREN__ 的标签：使用预合并的静态子内容
     */
    private function generateRuntimeTag(TagNode $node): string
    {
        $tagName = var_export($node->name, true);
        $attrs = ExprBuilder::buildAttrArray($node->attributes);
        
        // 确定 tagKey：自闭合标签使用 'tag-self-close'，否则使用 'tag-start'
        $tagKey = $node->selfClosing ? "'tag-self-close'" : "'tag-start'";
        
        // 构建原始属性字符串
        $rawAttributes = var_export($node->rawAttributes ?? '', true);
        
        // 检查是否为内联优化的自闭合标签
        if ($node->selfClosing || str_starts_with($node->rawContent, '__INLINE_OPTIMIZED__')) {
            // 无子内容，直接调用渲染器
            return ExprBuilder::wrapEcho(
                "\$this->getTaglib()->renderRuntimeTag(\$this, {$tagName}, {$tagKey}, {$attrs}, '', '', {$rawAttributes}, '')"
            );
        }
        
        // 检查是否有预合并的静态子内容
        if (str_starts_with($node->rawContent, '__STATIC_CHILDREN__')) {
            $staticContent = var_export(substr($node->rawContent, 19), true);
            return ExprBuilder::wrapEcho(
                "\$this->getTaglib()->renderRuntimeTag(\$this, {$tagName}, {$tagKey}, {$attrs}, {$staticContent}, '', {$rawAttributes}, '')"
            );
        }
        
        // 常规情况：使用 ob_start 捕获子内容
        $children = $this->generateNodes($node->children);
        $childrenVar = '$__children_' . crc32($tagName . $node->line);
        
        $code = ExprBuilder::wrapPhp("ob_start();");
        $code .= $children;
        $code .= ExprBuilder::wrapPhp("{$childrenVar} = ob_get_clean();");
        $code .= ExprBuilder::wrapEcho(
            "\$this->getTaglib()->renderRuntimeTag(\$this, {$tagName}, {$tagKey}, {$attrs}, {$childrenVar}, '', {$rawAttributes}, '')"
        );

        return $code;
    }

    /**
     * 生成默认标签输出
     */
    private function generateDefaultTag(TagNode $node): string
    {
        // 输出子内容
        return $this->generateNodes($node->children);
    }

    /**
     * 构建标签参数
     */
    private function buildTagParams(TagNode $node): array
    {
        $params = [
            'tagName' => $node->name,
            'line' => $node->line,
            'selfClosing' => $node->selfClosing,
            'rawContent' => $node->rawContent,
            'children' => $node->children,
            'childrenCode' => fn() => $this->generateNodes($node->children),
        ];

        // 添加属性
        foreach ($node->attributes as $attr) {
            if ($attr->isStatic) {
                $params[$attr->name] = $attr->staticValue;
            } else {
                $params[$attr->name] = $attr;
            }
        }

        return $params;
    }
}
