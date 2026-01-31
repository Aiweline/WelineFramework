<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 节点编译器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler
 */

namespace Weline\Framework\View\Taglib\Compiler;

use Weline\Framework\View\Taglib\Ast\{
    Node,
    ProgramNode,
    TextNode,
    PhpPlaceholder,
    TagNode,
    AttrNode
};
use Weline\Framework\View\Taglib\Registry\TagRegistry;

/**
 * 节点编译器
 *
 * 将 AST 节点编译为可执行代码
 */
final class NodeCompiler
{
    /**
     * 标签回调注册表
     * @var array<string, callable>
     */
    private array $tagCallbacks = [];

    /**
     * 阶段解析器
     */
    private readonly StageResolver $stageResolver;

    public function __construct(?StageResolver $stageResolver = null)
    {
        // 如果没有提供 StageResolver，创建默认实例
        if ($stageResolver === null) {
            $registry = new TagRegistry();
            $this->stageResolver = new StageResolver($registry);
        } else {
            $this->stageResolver = $stageResolver;
        }
    }

    /**
     * 注册标签回调
     */
    public function registerTag(string $name, callable $callback): void
    {
        $this->tagCallbacks[$name] = $callback;
    }

    /**
     * 批量注册标签回调
     * 
     * @param array<string, callable> $callbacks
     */
    public function registerTags(array $callbacks): void
    {
        foreach ($callbacks as $name => $callback) {
            $this->tagCallbacks[$name] = $callback;
        }
    }

    /**
     * 编译程序节点
     */
    public function compileProgram(ProgramNode $ast): ProgramNode
    {
        $compiledChildren = $this->compileNodes($ast->children);
        return $ast->withChildren($compiledChildren);
    }

    /**
     * 编译节点列表
     * 
     * @param array<Node> $nodes
     * @return array<Node>
     */
    public function compileNodes(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            $compiled = $this->compileNode($node);
            if ($compiled !== null) {
                $result[] = $compiled;
            }
        }

        return $result;
    }

    /**
     * 编译单个节点
     * 
     * @return Node|null 编译后的节点，null 表示移除
     */
    public function compileNode(Node $node): ?Node
    {
        // 文本节点直接返回
        if ($node instanceof TextNode) {
            return $node;
        }

        // PHP 占位符直接返回
        if ($node instanceof PhpPlaceholder) {
            return $node;
        }

        // 标签节点需要编译
        if ($node instanceof TagNode) {
            return $this->compileTagNode($node);
        }

        return $node;
    }

    /**
     * 编译标签节点
     */
    private function compileTagNode(TagNode $node): ?Node
    {
        // 确定编译阶段
        $stage = $this->stageResolver->resolve($node);
        $node = $node->withStage($stage);

        // 递归编译子节点
        $compiledChildren = $this->compileNodes($node->children);
        $node = $node->withChildren($compiledChildren);

        // 编译期标签立即执行回调
        if ($stage === TagNode::STAGE_COMPILE) {
            return $this->executeCompileTimeTag($node);
        }

        // 运行期标签保持原样
        return $node;
    }

    /**
     * 执行编译期标签
     */
    private function executeCompileTimeTag(TagNode $node): ?Node
    {
        $callback = $this->getTagCallbackWithAlias($node->name);

        if ($callback === null) {
            // 无回调，返回原节点
            return $node;
        }

        // 准备回调参数
        $params = $this->prepareCallbackParams($node);

        // 执行回调
        $result = $callback($params);

        // 处理回调结果
        if ($result === null || $result === '') {
            return null;
        }

        if (is_string($result)) {
            // 字符串结果转换为文本节点
            return new TextNode($node->line, $result);
        }

        if ($result instanceof Node) {
            return $result;
        }

        return $node;
    }

    /**
     * 准备标签回调参数
     */
    private function prepareCallbackParams(TagNode $node): array
    {
        $params = [
            'tagName' => $node->name,
            'line' => $node->line,
            'selfClosing' => $node->selfClosing,
            'rawContent' => $node->rawContent,
            'children' => $node->children,
        ];

        // 添加属性
        foreach ($node->attributes as $attr) {
            $params[$attr->name] = $attr->staticValue ?? $attr->rawValue;
        }

        return $params;
    }

    /**
     * 获取标签回调
     */
    public function getTagCallback(string $name): ?callable
    {
        return $this->tagCallbacks[$name] ?? null;
    }

    /**
     * 获取标签回调（支持别名查找）
     *
     * 如果标签没有直接注册的回调，会检查是否是别名，
     * 并使用原始标签的回调。
     */
    public function getTagCallbackWithAlias(string $name): ?callable
    {
        // 首先尝试直接查找
        if (isset($this->tagCallbacks[$name])) {
            return $this->tagCallbacks[$name];
        }

        // 检查是否是别名，获取原始标签名
        $definition = $this->stageResolver->getTagDefinition($name);
        if ($definition !== null && $definition->aliasOf !== null) {
            // 使用原始标签的回调
            return $this->tagCallbacks[$definition->aliasOf] ?? null;
        }

        return null;
    }

    /**
     * 检查标签是否已注册
     */
    public function hasTag(string $name): bool
    {
        return isset($this->tagCallbacks[$name]);
    }

    /**
     * 检查标签是否已注册（支持别名）
     */
    public function hasTagWithAlias(string $name): bool
    {
        if (isset($this->tagCallbacks[$name])) {
            return true;
        }

        $definition = $this->stageResolver->getTagDefinition($name);
        if ($definition !== null && $definition->aliasOf !== null) {
            return isset($this->tagCallbacks[$definition->aliasOf]);
        }

        return false;
    }
}
