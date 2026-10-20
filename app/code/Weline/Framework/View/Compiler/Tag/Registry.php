<?php

declare(strict_types=1);

/**
 * 标签注册表。
 *
 * 三条纪律：
 * 1. **同名只允许一个描述符**（含别名冲突）：重复注册直接抛错，防止不同时暴露出
 *    「两个实现都生效、行为看注册顺序」这种最难查的问题（现状 D1 的根因之一）。
 * 2. 查询大小写不敏感，但**保留原始拼写**用于报错。
 * 3. P1 阶段允许「未注册」：Parser 命中的元素标签若还没有描述符，由 Lower 决定
 *    走旧路径还是原样保留，Registry 不替它做决定。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

final class Registry
{
    /** @var array<string, TagDescriptor> 小写名 → 描述符 */
    private array $byName = [];

    /**
     * @param iterable<TagDescriptor> $descriptors
     */
    public function __construct(iterable $descriptors = [])
    {
        foreach ($descriptors as $descriptor) {
            $this->register($descriptor);
        }
    }

    /**
     * @throws \InvalidArgumentException 名字或别名冲突时
     */
    public function register(TagDescriptor $descriptor): void
    {
        foreach ($descriptor->allNames() as $name) {
            $key = \strtolower($name);
            if (isset($this->byName[$key])) {
                throw new \InvalidArgumentException(\sprintf(
                    '标签名冲突：%s 已由 %s 注册，%s 又想占用它',
                    $name,
                    $this->byName[$key]->name,
                    $descriptor->name,
                ));
            }
            $this->byName[$key] = $descriptor;
        }
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function get(string $name): ?TagDescriptor
    {
        $key = \strtolower($name);
        if (isset($this->byName[$key])) {
            return $this->byName[$key];
        }
        // 语料里同一个内置标签常写成两种形态（<hook> 与 <w:hook>），
        // 旧编译器两种都认；注册表按「名字或 w: 前缀名」等价查找。
        if (\str_starts_with($key, 'w:')) {
            return $this->byName[\substr($key, 2)] ?? null;
        }

        return null;
    }

    /**
     * @return list<TagDescriptor>
     */
    public function all(): array
    {
        $unique = [];
        foreach ($this->byName as $descriptor) {
            $unique[\spl_object_id($descriptor)] = $descriptor;
        }

        return \array_values($unique);
    }

    public function count(): int
    {
        return \count($this->all());
    }
}
