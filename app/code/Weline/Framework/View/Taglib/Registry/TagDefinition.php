<?php

declare(strict_types=1);

/**
 * Weline Framework - Taglib Tag Definition
 *
 * @DESC          | 标签定义类，定义标签的元数据结构
 * @Author        | Weline Framework
 * @Package       | Weline\Framework\View\Taglib\Registry
 */

namespace Weline\Framework\View\Taglib\Registry;

/**
 * 标签定义类
 *
 * 用于描述标签的完整元数据，支持别名、优先级和依赖关系
 * 实现"一处定义，处处生效"的设计目标
 */
final class TagDefinition
{
    /**
     * 编译期阶段常量
     */
    public const STAGE_COMPILE = 'compile';

    /**
     * 运行期阶段常量
     */
    public const STAGE_RUNTIME = 'runtime';

    /**
     * 优先级常量 - 控制流标签（if, foreach, for, switch）
     */
    public const PRIORITY_CONTROL_FLOW = 100;

    /**
     * 优先级常量 - 布局标签（template, include, extends, section）
     */
    public const PRIORITY_LAYOUT = 200;

    /**
     * 优先级常量 - 组件标签（block, component, slot）
     */
    public const PRIORITY_COMPONENT = 300;

    /**
     * 优先级常量 - 资源标签（css, js, static, theme:css, theme:js）
     */
    public const PRIORITY_ASSET = 400;

    /**
     * 优先级常量 - 内容标签（lang, trans）
     */
    public const PRIORITY_CONTENT = 500;

    /**
     * 优先级常量 - 运行期标签（url, csrf, method）
     */
    public const PRIORITY_RUNTIME = 600;

    /**
     * 优先级常量 - 模块扩展标签
     */
    public const PRIORITY_MODULE = 700;

    /**
     * 优先级常量 - 默认
     */
    public const PRIORITY_DEFAULT = 500;

    /**
     * @param string $name 标签名称
     * @param string $stage 处理阶段（compile 或 runtime）
     * @param int $priority 优先级（数字越小越先处理）
     * @param array $aliases 别名列表
     * @param array $dependencies 依赖的其他标签名
     * @param callable|null $callback 编译回调函数
     * @param string|null $aliasOf 如果是别名，指向原始标签名
     */
    public function __construct(
        public readonly string $name,
        public readonly string $stage = self::STAGE_COMPILE,
        public readonly int $priority = self::PRIORITY_DEFAULT,
        public readonly array $aliases = [],
        public readonly array $dependencies = [],
        public readonly mixed $callback = null,
        public readonly ?string $aliasOf = null,
    ) {
    }

    /**
     * 从别名创建新的定义
     *
     * 继承原始标签的所有配置，但使用新的名称
     *
     * @param string $aliasName 别名
     * @return self 新的标签定义
     */
    public function withAlias(string $aliasName): self
    {
        return new self(
            name: $aliasName,
            stage: $this->stage,
            priority: $this->priority,
            aliases: [],
            dependencies: $this->dependencies,
            callback: $this->callback,
            aliasOf: $this->name,
        );
    }

    /**
     * 设置回调函数
     *
     * @param callable $callback 回调函数
     * @return self 新的标签定义
     */
    public function withCallback(callable $callback): self
    {
        return new self(
            name: $this->name,
            stage: $this->stage,
            priority: $this->priority,
            aliases: $this->aliases,
            dependencies: $this->dependencies,
            callback: $callback,
            aliasOf: $this->aliasOf,
        );
    }

    /**
     * 是否为编译期标签
     */
    public function isCompileTime(): bool
    {
        return $this->stage === self::STAGE_COMPILE;
    }

    /**
     * 是否为运行期标签
     */
    public function isRuntime(): bool
    {
        return $this->stage === self::STAGE_RUNTIME;
    }

    /**
     * 是否为别名
     */
    public function isAlias(): bool
    {
        return $this->aliasOf !== null;
    }

    /**
     * 是否有回调
     */
    public function hasCallback(): bool
    {
        return $this->callback !== null;
    }

    /**
     * 是否有依赖
     */
    public function hasDependencies(): bool
    {
        return !empty($this->dependencies);
    }

    /**
     * 获取原始标签名（如果是别名则返回原始标签名，否则返回自身名称）
     */
    public function getOriginalName(): string
    {
        return $this->aliasOf ?? $this->name;
    }

    /**
     * 转换为数组（用于调试和序列化）
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'stage' => $this->stage,
            'priority' => $this->priority,
            'aliases' => $this->aliases,
            'dependencies' => $this->dependencies,
            'hasCallback' => $this->hasCallback(),
            'aliasOf' => $this->aliasOf,
        ];
    }
}
