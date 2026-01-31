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
     * 设置 PHP 提取器
     */
    public function setPhpExtractor(PhpExtractor $extractor): void
    {
        $this->phpExtractor = $extractor;
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
     * 生成 PHP 代码
     */
    public function generate(ProgramNode $ast): string
    {
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
        // 分支预测优化：按频率排序
        
        // 1. 文本节点（最常见）
        if ($node instanceof TextNode) {
            return $node->value;
        }

        // 2. PHP 占位符
        if ($node instanceof PhpPlaceholder) {
            return $this->generatePlaceholder($node);
        }

        // 3. 标签节点
        if ($node instanceof TagNode) {
            return $this->generateTagNode($node);
        }

        return '';
    }

    /**
     * 生成占位符代码
     */
    private function generatePlaceholder(PhpPlaceholder $node): string
    {
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
        return match ($node->name) {
            'if' => $this->generateIfTag($node),
            'elseif' => $this->generateElseifTag($node),
            'else' => $this->generateElseTag($node),
            'foreach' => $this->generateForeachTag($node),
            'for' => $this->generateForTag($node),
            default => null,
        };
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

        // 解析变量表达式（如 meta.showHeader => $meta['showHeader']）
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
     * 例如：meta.showHeader => $meta['showHeader']
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

        // 处理表达式中的每个标识符
        // 匹配：非 $ 开头的标识符后跟 .标识符 的模式
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_.]*)\b/';

        return preg_replace_callback($pattern, function ($matches) {
            $var = $matches[1];
            $path = $matches[2];

            // 分割路径
            $parts = explode('.', $path);
            $result = '$' . $var;

            foreach ($parts as $part) {
                $result .= "['{$part}']";
            }

            return $result;
        }, $expr);
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
     */
    private function generateForeachTag(TagNode $node): string
    {
        $expr = '';
        
        // 从 name 属性获取
        foreach ($node->attributes as $attr) {
            if ($attr->name === 'name') {
                $name = $attr->staticValue ?? $attr->rawValue;
                $key = null;
                $item = null;
                
                // 获取 key 和 item
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
     * 生成运行期标签代码
     */
    private function generateRuntimeTag(TagNode $node): string
    {
        // 生成运行时调用代码
        $tagName = var_export($node->name, true);
        $attrs = ExprBuilder::buildAttrArray($node->attributes);
        $children = $this->generateNodes($node->children);
        
        // 将子内容包装为 heredoc
        $childrenVar = '$__children_' . crc32($tagName . $node->line);
        
        $code = ExprBuilder::wrapPhp("ob_start();");
        $code .= $children;
        $code .= ExprBuilder::wrapPhp("{$childrenVar} = ob_get_clean();");
        $code .= ExprBuilder::wrapEcho(
            "\$this->taglib->renderRuntimeTag({$tagName}, {$attrs}, {$childrenVar})"
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
