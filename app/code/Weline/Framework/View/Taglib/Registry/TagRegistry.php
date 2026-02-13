<?php

declare(strict_types=1);

/**
 * Weline Framework
 *
 * @DESC         | 标签注册表
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Registry
 */

namespace Weline\Framework\View\Taglib\Registry;

use WeakMap;
use Weline\Framework\View\Template;
use Weline\Framework\View\Taglib\Resolver\DependencyResolver;

/**
 * 标签注册表
 *
 * 管理标签配置，使用 WeakMap 缓存模板级标签
 * 支持 TagDefinition 元数据、别名展开和优先级排序
 */
final class TagRegistry
{
    /**
     * 模板到标签配置的缓存
     */
    private WeakMap $templateCache;

    /**
     * 模板到 TagDefinition 的缓存
     */
    private WeakMap $definitionCache;

    /**
     * 全局标签定义
     * @var array<string, TagDefinition>
     */
    private array $globalDefinitions = [];

    /**
     * 内置标签定义（静态缓存）
     * @var array<TagDefinition>|null
     */
    private static ?array $builtinDefinitions = null;

    /**
     * 展开后的标签定义缓存（包含别名）
     * @var array<string, TagDefinition>|null
     */
    private static ?array $resolvedBuiltinDefinitions = null;

    /**
     * 依赖解析器
     */
    private ?DependencyResolver $dependencyResolver = null;

    public function __construct()
    {
        $this->templateCache = new WeakMap();
        $this->definitionCache = new WeakMap();
    }

    /**
     * 获取依赖解析器
     */
    private function getDependencyResolver(): DependencyResolver
    {
        return $this->dependencyResolver ??= new DependencyResolver();
    }

    /**
     * 获取内置标签定义列表
     *
     * @return array<TagDefinition>
     */
    public static function builtinDefinitions(): array
    {
        if (self::$builtinDefinitions !== null) {
            return self::$builtinDefinitions;
        }

        self::$builtinDefinitions = [
            // ==================== 控制流标签 (优先级 100) ====================
            new TagDefinition(
                name: 'if',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
            ),
            new TagDefinition(
                name: 'else',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
                dependencies: ['if'],
            ),
            new TagDefinition(
                name: 'elseif',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
                dependencies: ['if'],
            ),
            new TagDefinition(
                name: 'foreach',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
            ),
            new TagDefinition(
                name: 'for',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
            ),
            new TagDefinition(
                name: 'switch',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
            ),
            new TagDefinition(
                name: 'case',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
                dependencies: ['switch'],
            ),
            new TagDefinition(
                name: 'default',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
                dependencies: ['switch'],
            ),
            new TagDefinition(
                name: 'while',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTROL_FLOW,
            ),

            // ==================== 布局标签 (优先级 200) ====================
            new TagDefinition(
                name: 'template',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
                aliases: ['include'],
            ),
            new TagDefinition(
                name: 'extends',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
            ),
            new TagDefinition(
                name: 'section',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
            ),
            new TagDefinition(
                name: 'yield',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
            ),
            new TagDefinition(
                name: 'push',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
            ),
            new TagDefinition(
                name: 'stack',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_LAYOUT,
            ),

            // ==================== 组件标签 (优先级 300) ====================
            new TagDefinition(
                name: 'block',
                stage: TagDefinition::STAGE_COMPILE,  // 使用 Taglib::getTags() 中定义的回调
                priority: TagDefinition::PRIORITY_COMPONENT,
                aliases: ['w:block'],
            ),
            new TagDefinition(
                name: 'slot',
                stage: TagDefinition::STAGE_RUNTIME,
                priority: TagDefinition::PRIORITY_COMPONENT,
            ),
            new TagDefinition(
                name: 'hook',
                stage: TagDefinition::STAGE_COMPILE,  // 使用 Taglib::getTags() 中定义的回调
                priority: TagDefinition::PRIORITY_COMPONENT,
                aliases: ['w:hook'],
            ),

            // ==================== 资源标签 (优先级 400) ====================
            new TagDefinition(
                name: 'css',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_ASSET,
                aliases: ['w:css', 'weline:css'],
            ),
            new TagDefinition(
                name: 'js',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_ASSET,
                aliases: ['w:js', 'weline:js'],
            ),
            new TagDefinition(
                name: 'theme:css',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_ASSET,
            ),
            new TagDefinition(
                name: 'theme:js',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_ASSET,
            ),
            new TagDefinition(
                name: 'static',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_ASSET,
            ),

            // ==================== 内容标签 (优先级 500) ====================
            // lang/trans：编译期直接输出译文；仅 @lang 内联形式且含动态参数时由回调返回 PHP 代码
            new TagDefinition(
                name: 'lang',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_CONTENT,
                aliases: ['trans'],
            ),

            // ==================== 运行期/动态 URL 标签 (优先级 600) ====================
            new TagDefinition(
                name: 'url',
                stage: TagDefinition::STAGE_COMPILE,  // 在编译期生成 PHP 代码
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'frontend-url',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'backend-url',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'api',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'frontend-api',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'backend-api',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'csrf',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),
            new TagDefinition(
                name: 'method',
                stage: TagDefinition::STAGE_COMPILE,
                priority: TagDefinition::PRIORITY_RUNTIME,
            ),

            // ==================== 命名空间通配 ====================
            // w 命名空间（支持未明确定义的 w:xxx 标签）
            new TagDefinition(
                name: 'w',
                stage: TagDefinition::STAGE_RUNTIME,
                priority: TagDefinition::PRIORITY_MODULE,
            ),
        ];

        return self::$builtinDefinitions;
    }

    /**
     * 获取解析后的内置标签定义（包含展开的别名）
     *
     * @return array<string, TagDefinition>
     */
    public static function resolvedBuiltinDefinitions(): array
    {
        if (self::$resolvedBuiltinDefinitions !== null) {
            return self::$resolvedBuiltinDefinitions;
        }

        self::$resolvedBuiltinDefinitions = self::expandAliases(self::builtinDefinitions());
        return self::$resolvedBuiltinDefinitions;
    }

    /**
     * 展开别名
     *
     * @param array<TagDefinition> $definitions
     * @return array<string, TagDefinition>
     */
    private static function expandAliases(array $definitions): array
    {
        $result = [];

        foreach ($definitions as $def) {
            // 添加原始定义
            $result[$def->name] = $def;

            // 展开别名
            foreach ($def->aliases as $alias) {
                $result[$alias] = $def->withAlias($alias);
            }
        }

        return $result;
    }

    /**
     * 获取模板可用标签定义（带缓存）
     *
     * @param Template $template
     * @return array<string, TagDefinition>
     */
    public function getDefinitions(Template $template): array
    {
        if (isset($this->definitionCache[$template])) {
            return $this->definitionCache[$template];
        }

        $definitions = $this->loadDefinitions($template);
        $this->definitionCache[$template] = $definitions;
        return $definitions;
    }

    /**
     * 加载模板的标签定义
     */
    private function loadDefinitions(Template $template): array
    {
        // 合并内置定义和全局定义
        $definitions = self::resolvedBuiltinDefinitions();

        foreach ($this->globalDefinitions as $name => $def) {
            $definitions[$name] = $def;
            // 展开别名
            foreach ($def->aliases as $alias) {
                $definitions[$alias] = $def->withAlias($alias);
            }
        }

        // 按依赖和优先级排序
        try {
            $definitions = $this->getDependencyResolver()->resolve($definitions);
        } catch (\Throwable $e) {
            // 如果依赖解析失败，返回未排序的定义
            // 在开发环境下可能需要记录日志
        }

        return $definitions;
    }

    /**
     * 获取模板可用标签（兼容旧接口，返回数组格式）
     *
     * @param Template $template
     * @return array<string, array>
     */
    public function getTags(Template $template): array
    {
        if (isset($this->templateCache[$template])) {
            return $this->templateCache[$template];
        }

        $definitions = $this->getDefinitions($template);
        $tags = [];

        foreach ($definitions as $name => $def) {
            $tags[$name] = [
                'callback' => $def->callback,
                'stage' => $def->stage,
                'priority' => $def->priority,
                'aliasOf' => $def->aliasOf,
            ];
        }

        $this->templateCache[$template] = $tags;
        return $tags;
    }

    /**
     * 获取标签名列表
     *
     * @param Template $template
     * @return array<string>
     */
    public function getTagNames(Template $template): array
    {
        return array_keys($this->getDefinitions($template));
    }

    /**
     * 获取标签定义
     *
     * @param Template $template
     * @param string $tagName
     * @return TagDefinition|null
     */
    public function getDefinition(Template $template, string $tagName): ?TagDefinition
    {
        $definitions = $this->getDefinitions($template);
        return $definitions[$tagName] ?? null;
    }

    /**
     * 注册全局标签定义
     *
     * @param TagDefinition $definition
     */
    public function registerDefinition(TagDefinition $definition): void
    {
        $this->globalDefinitions[$definition->name] = $definition;
        $this->clearCache();
    }

    /**
     * 注册全局标签（兼容旧接口）
     */
    public function registerTag(string $name, array $config): void
    {
        $definition = new TagDefinition(
            name: $name,
            stage: $config['stage'] ?? TagDefinition::STAGE_COMPILE,
            priority: $config['priority'] ?? TagDefinition::PRIORITY_DEFAULT,
            aliases: $config['aliases'] ?? [],
            dependencies: $config['dependencies'] ?? [],
            callback: $config['callback'] ?? null,
        );
        $this->registerDefinition($definition);
    }

    /**
     * 批量注册全局标签
     */
    public function registerTags(array $tags): void
    {
        foreach ($tags as $name => $config) {
            $this->registerTag($name, $config);
        }
    }

    /**
     * 获取内置标签（兼容旧接口）
     *
     * @return array<string, array>
     */
    public static function builtinTags(): array
    {
        $result = [];
        foreach (self::resolvedBuiltinDefinitions() as $name => $def) {
            $result[$name] = [
                'callback' => $def->callback,
                'stage' => $def->stage,
            ];
        }
        return $result;
    }

    /**
     * 获取标签配置（兼容旧接口）
     */
    public function getTagConfig(Template $template, string $tagName): ?array
    {
        $def = $this->getDefinition($template, $tagName);
        if ($def === null) {
            return null;
        }

        return [
            'callback' => $def->callback,
            'stage' => $def->stage,
            'priority' => $def->priority,
            'aliasOf' => $def->aliasOf,
        ];
    }

    /**
     * 检查标签是否已注册
     */
    public function hasTag(Template $template, string $tagName): bool
    {
        return $this->getDefinition($template, $tagName) !== null;
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->templateCache = new WeakMap();
        $this->definitionCache = new WeakMap();
    }

    /**
     * 清除所有缓存（包括静态缓存）
     */
    public static function clearAllCache(): void
    {
        self::$builtinDefinitions = null;
        self::$resolvedBuiltinDefinitions = null;
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        return [
            'builtinCount' => count(self::builtinDefinitions()),
            'resolvedBuiltinCount' => count(self::resolvedBuiltinDefinitions()),
            'globalCount' => count($this->globalDefinitions),
            'cacheCount' => count($this->templateCache),
            'definitionCacheCount' => count($this->definitionCache),
        ];
    }
}
