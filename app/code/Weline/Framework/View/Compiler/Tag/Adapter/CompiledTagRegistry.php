<?php

declare(strict_types=1);

/**
 * 从编译索引构建描述符注册表。
 *
 * 索引里只有 `class` 与元数据；真正的形态/属性仍以**标签实现自己的静态方法**为准，
 * 所以这里逐个调用 `ModuleTagAdapter`，而不是复制索引里的 `tag/attr` 字段。
 * 这样即使索引与实现短暂不一致，Registry 也只会反映实现（单一定义源）。
 *
 * 容错：单个类加载失败（模块被禁用、类被删）只跳过，不阻断整表——
 * 否则一个坏模块会让所有模板无法编译。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Adapter;

use Weline\Framework\View\Compiler\Tag\Registry;

final class CompiledTagRegistry
{
    public function __construct(
        private readonly string $compiledFile,
        private readonly ModuleTagAdapter $adapter = new ModuleTagAdapter(),
    ) {
    }

    /**
     * @return array<string, class-string> 标签名 → 实现类
     */
    public function taglibClasses(): array
    {
        if (!\is_file($this->compiledFile)) {
            return [];
        }

        try {
            /** @var mixed $registry */
            $registry = require $this->compiledFile;
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($registry) || !\is_array($registry['tags'] ?? null)) {
            return [];
        }

        $classes = [];
        foreach ($registry['tags'] as $name => $definition) {
            $class = \is_array($definition) ? ($definition['class'] ?? null) : null;
            if (\is_string($class) && $class !== '' && \class_exists($class)) {
                $classes[(string)$name] = $class;
            }
        }

        return $classes;
    }

    public function build(): Registry
    {
        $registry = new Registry();
        foreach ($this->taglibClasses() as $class) {
            try {
                $registry->register($this->adapter->adapt($class));
            } catch (\Throwable) {
                continue;
            }
        }

        return $registry;
    }
}
