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
    TagNode,
    AttrNode,
    NodePool
};

/**
 * 常量折叠优化通道
 * 
 * 将编译期可确定的常量表达式求值，减少运行时计算
 * 
 * 优化示例：
 * - <lang>STATIC_KEY</lang> => 直接替换为翻译结果
 * - 连续的 TextNode 合并
 */
final class ConstantFoldingPass implements CompilePassInterface
{
    /**
     * 静态翻译缓存
     * @var array<string, string>
     */
    private array $translationCache = [];

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
                $result[] = $this->optimizeTagNode($node);
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
     */
    private function optimizeTagNode(TagNode $node): TagNode
    {
        // 递归优化子节点
        $optimizedChildren = $this->optimizeNodes($node->children);

        // 尝试常量折叠
        $foldedNode = $this->tryFoldConstants($node, $optimizedChildren);
        
        return $foldedNode ?? $node->withChildren($optimizedChildren);
    }

    /**
     * 尝试常量折叠
     * 
     * @return TagNode|null 折叠后的节点，无法折叠返回 null
     */
    private function tryFoldConstants(TagNode $node, array $children): ?TagNode
    {
        // 目前只处理 lang 标签的静态翻译
        if ($node->name !== 'lang') {
            return null;
        }

        // 检查是否所有子节点都是静态的
        if ($node->isDynamic) {
            return null;
        }

        // 提取静态内容
        $staticContent = $this->extractStaticContent($children);
        if ($staticContent === null) {
            return null;
        }

        // 可以在这里实现静态翻译查找
        // 目前返回 null 表示不优化
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
}
