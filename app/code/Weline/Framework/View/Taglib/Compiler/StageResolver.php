<?php

declare(strict_types=1);

/**
 * Weline Framework
 *
 * @DESC         | 编译阶段解析器
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Compiler
 */

namespace Weline\Framework\View\Taglib\Compiler;

use Weline\Framework\View\Taglib\Ast\TagNode;
use Weline\Framework\View\Taglib\Registry\TagDefinition;
use Weline\Framework\View\Taglib\Registry\TagRegistry;
use Weline\Framework\View\Template;

/**
 * 编译阶段解析器
 *
 * 决定标签是在编译期处理还是运行期处理
 * 基于 TagDefinition 元数据决定阶段，无需硬编码标签列表
 */
final class StageResolver
{
    /**
     * 自定义标签阶段映射（最高优先级）
     * @var array<string, string>
     */
    private array $customStages = [];

    /**
     * 当前模板对象
     */
    private ?Template $template = null;

    /**
     * 标签定义缓存
     * @var array<string, TagDefinition>|null
     */
    private ?array $definitionsCache = null;

    public function __construct(
        private readonly TagRegistry $registry,
    ) {
    }

    /**
     * 设置当前模板
     */
    public function setTemplate(Template $template): void
    {
        $this->template = $template;
        $this->definitionsCache = null;
    }

    /**
     * 获取当前模板的所有标签定义
     */
    private function getDefinitions(): array
    {
        if ($this->definitionsCache !== null) {
            return $this->definitionsCache;
        }

        if ($this->template !== null) {
            $this->definitionsCache = $this->registry->getDefinitions($this->template);
        } else {
            // 如果没有模板，使用内置定义
            $this->definitionsCache = TagRegistry::resolvedBuiltinDefinitions();
        }

        return $this->definitionsCache;
    }

    /**
     * 设置自定义标签阶段
     */
    public function setTagStage(string $tagName, string $stage): void
    {
        $this->customStages[$tagName] = $stage;
    }

    /**
     * 解析标签的编译阶段
     *
     * 优先级：
     * 1. 自定义配置（setTagStage）
     * 2. TagDefinition 元数据
     * 3. 命名空间前缀匹配
     * 4. 动态属性判断
     * 5. 默认编译期
     */
    public function resolve(TagNode $node): string
    {
        $tagName = $node->name;

        // 1. 检查自定义配置（最高优先级）
        if (isset($this->customStages[$tagName])) {
            return $this->customStages[$tagName];
        }

        // 2. 从 TagDefinition 获取阶段
        $definitions = $this->getDefinitions();
        if (isset($definitions[$tagName])) {
            return $definitions[$tagName]->stage;
        }

        // 3. 检查命名空间前缀匹配（如 w:template 匹配 template 定义）
        if (str_contains($tagName, ':')) {
            $parts = explode(':', $tagName, 2);
            $namespace = $parts[0];
            $baseName = $parts[1] ?? '';
            
            // 先检查基础名称是否有定义（w:template -> template）
            if ($baseName !== '' && isset($definitions[$baseName])) {
                return $definitions[$baseName]->stage;
            }

            // 检查命名空间是否有定义
            if (isset($definitions[$namespace])) {
                return $definitions[$namespace]->stage;
            }

            // 命名空间标签默认编译期（与无命名空间标签一致）
            return TagNode::STAGE_COMPILE;
        }

        // 4. 根据动态属性判断
        if ($node->hasDynamicAttrs) {
            return TagNode::STAGE_RUNTIME;
        }

        // 5. 默认编译期
        return TagNode::STAGE_COMPILE;
    }

    /**
     * 批量解析并更新节点阶段
     *
     * @param array<TagNode> $nodes
     * @return array<TagNode>
     */
    public function resolveAll(array $nodes): array
    {
        return array_map(function (TagNode $node): TagNode {
            $stage = $this->resolve($node);
            return $node->withStage($stage);
        }, $nodes);
    }

    /**
     * 检查标签是否为编译期标签
     */
    public function isCompileTime(TagNode $node): bool
    {
        return $this->resolve($node) === TagNode::STAGE_COMPILE;
    }

    /**
     * 检查标签是否为运行期标签
     */
    public function isRuntime(TagNode $node): bool
    {
        return $this->resolve($node) === TagNode::STAGE_RUNTIME;
    }

    /**
     * 获取标签定义
     */
    public function getTagDefinition(string $tagName): ?TagDefinition
    {
        $definitions = $this->getDefinitions();
        return $definitions[$tagName] ?? null;
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->definitionsCache = null;
    }
}
