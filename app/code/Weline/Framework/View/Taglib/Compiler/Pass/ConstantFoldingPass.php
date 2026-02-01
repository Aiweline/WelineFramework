<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 常量折叠优化通道
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler\Pass
 */

namespace Weline\Framework\View\Taglib\Compiler\Pass;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TextNode,
    PhpPlaceholder,
    TagNode,
    AttrNode,
    NodePool
};

/**
 * 常量折叠优化通道
 * 
 * 将编译期可确定的常量表达式求值，减少运行时计算
 * 
 * 优化规则：
 * 1. 合并连续的 TextNode，减少内存分配
 * 2. 静态 <lang>KEY</lang> 标签折叠为翻译结果（需要提供翻译回调）
 * 3. 静态属性值合并
 * 
 * 优先级：10（常量折叠/常量传播阶段）
 */
final class ConstantFoldingPass implements CompilePassInterface
{
    /**
     * 静态翻译回调
     * @var callable|null
     */
    private $translationCallback = null;

    /**
     * 静态翻译缓存
     * @var array<string, string>
     */
    private array $translationCache = [];

    /**
     * 设置翻译回调
     * 
     * @param callable $callback function(string $key): ?string
     */
    public function setTranslationCallback(callable $callback): void
    {
        $this->translationCallback = $callback;
    }

    /**
     * @inheritDoc
     */
    public function process(ProgramNode $ast): ProgramNode
    {
        $optimizedChildren = $this->optimizeNodes($ast->children);
        return $ast->withChildren($optimizedChildren);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'constant-folding';
    }

    /**
     * @inheritDoc
     * 
     * 优先级 10：常量折叠阶段
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * 优化节点列表
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    private function optimizeNodes(array $nodes): array
    {
        $result = [];
        $textBuffer = '';
        $textLine = 1;

        foreach ($nodes as $node) {
            // 合并连续的 TextNode
            if ($node instanceof TextNode) {
                if ($textBuffer === '') {
                    $textLine = $node->line;
                }
                $textBuffer .= $node->value;
                continue;
            }

            // 刷新文本缓冲区
            if ($textBuffer !== '') {
                $result[] = NodePool::textNode($textLine, $textBuffer);
                $textBuffer = '';
            }

            // 处理标签节点
            if ($node instanceof TagNode) {
                $optimized = $this->optimizeTagNode($node);
                
                // 如果优化结果是 TextNode，可以继续合并
                if ($optimized instanceof TextNode) {
                    if ($textBuffer === '') {
                        $textLine = $optimized->line;
                    }
                    $textBuffer .= $optimized->value;
                    continue;
                }
                
                $result[] = $optimized;
                continue;
            }

            // 其他节点直接保留
            $result[] = $node;
        }

        // 刷新剩余文本
        if ($textBuffer !== '') {
            $result[] = NodePool::textNode($textLine, $textBuffer);
        }

        return $result;
    }

    /**
     * 优化标签节点
     * 
     * @return Node 优化后的节点（可能是 TagNode 或 TextNode）
     */
    private function optimizeTagNode(TagNode $node): Node
    {
        // 递归优化子节点
        $optimizedChildren = $this->optimizeNodes($node->children);

        // 尝试常量折叠（可能返回 TextNode）
        $foldedNode = $this->tryFoldConstants($node, $optimizedChildren);
        
        if ($foldedNode !== null) {
            return $foldedNode;
        }
        
        // 返回优化后的 TagNode
        if ($optimizedChildren !== $node->children) {
            return $node->withChildren($optimizedChildren);
        }
        
        return $node;
    }

    /**
     * 尝试常量折叠
     * 
     * @return Node|null 折叠后的节点，无法折叠返回 null
     */
    private function tryFoldConstants(TagNode $node, array $children): ?Node
    {
        // 处理 lang/trans 标签的静态翻译
        if ($node->name === 'lang' || $node->name === 'trans') {
            return $this->tryFoldLangTag($node, $children);
        }

        return null;
    }

    /**
     * 尝试折叠 lang 标签
     * 
     * @return Node|null 折叠后的节点
     */
    private function tryFoldLangTag(TagNode $node, array $children): ?Node
    {
        // 检查是否所有子节点都是静态的
        if ($node->isDynamic) {
            return null;
        }

        // 提取静态内容作为翻译键
        $translationKey = $this->extractStaticContent($children);
        if ($translationKey === null || trim($translationKey) === '') {
            return null;
        }

        $translationKey = trim($translationKey);

        // 检查缓存
        if (isset($this->translationCache[$translationKey])) {
            return NodePool::textNode($node->line, $this->translationCache[$translationKey]);
        }

        // 使用翻译回调获取翻译结果
        if ($this->translationCallback !== null) {
            $translated = ($this->translationCallback)($translationKey);
            if ($translated !== null) {
                $this->translationCache[$translationKey] = $translated;
                return NodePool::textNode($node->line, $translated);
            }
        }

        // 无法折叠
        return null;
    }

    /**
     * 提取静态内容
     */
    private function extractStaticContent(array $nodes): ?string
    {
        $content = '';
        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $content .= $node->value;
            } else {
                return null;
            }
        }
        return $content;
    }

    /**
     * 清除翻译缓存
     */
    public function clearCache(): void
    {
        $this->translationCache = [];
    }
}
