<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Query;

/**
 * 列表查询结果的默认容器。
 *
 * 设计目标（与 fetchArray() 的区别）：
 *   - 每行是**关联数组**，不再逐行 `ObjectManager::make()` 构造模型对象。
 *     实测 2085 行时旧 fetch() 需 65.32ms / +327.61MB，fetchArray() 仅 4.23ms / +1.35MB。
 *   - 仍保留 `getItems()`，使既有 `->fetch()->getItems()` 调用点（全仓 94 处）零改动可用。
 *     `getItems()` 返回的是**纯数组列表**；调用方若需要模型对象，用 `fetchModel()`。
 *
 * 为什么不是直接返回 array：
 *   全仓存在「`$x = ...->fetch(); if (is_array($x)) { ... }`」这类写法（实测 52 处）。
 *   若 `fetch()` 直接变纯数组，这些分支会静默翻转。
 *   RowCollection 不是数组（`is_array()` 仍为 false），因此**不改变这些分支的现行走向**；
 *   语义切换留给调用方显式迁移。
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class RowCollection implements \IteratorAggregate, \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    private string $modelClass;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param class-string $modelClass 仅用于 `fetchModel()`/`firstModel()` 按需水合，不参与缓存
     */
    public function __construct(array $rows, string $modelClass = '')
    {
        $this->rows = \array_values($rows);
        $this->modelClass = $modelClass;
    }

    /**
     * 兼容既有 `->fetch()->getItems()`：返回纯数组行列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->rows;
    }

    /**
     * 兼容既有 `->fetch()->toArray()`。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    /**
     * 第一行（纯数组）；空集合返回空数组。
     *
     * @return array<string, mixed>
     */
    public function first(): array
    {
        return $this->rows[0] ?? [];
    }

    /**
     * 按需把某一行水合为模型对象，替代 `->fetch()` 的对象语义。
     */
    public function firstModel(): ?object
    {
        if ($this->rows === [] || $this->modelClass === '' || !\class_exists($this->modelClass)) {
            return null;
        }

        return new $this->modelClass($this->rows[0]);
    }

    /**
     * 显式对象化：`$model->fetchModel()` 的容器侧等价写法。
     *
     * `fetch()` 自 2026-10-30 起对多行查询返回本容器（每行是关联数组），需要逐行
     * 模型对象（`instanceof Model`、调用模型方法、写回）的调用方必须显式改用它，
     * 而不是继续对 `getItems()` 做 `instanceof` 过滤——那种写法在数组行下会静默
     * 过滤掉全部结果（实测导致 Websites 站点匹配失败、全站 404）。
     *
     * @return array<int, object>
     */
    public function fetchModel(): array
    {
        return $this->toModels();
    }

    /**
     * 按需把所有行水合为模型对象列表；等价于显式 `fetchModel()` 的逐行版本。
     *
     * @return array<int, object>
     */
    public function toModels(): array
    {
        if ($this->modelClass === '' || !\class_exists($this->modelClass)) {
            return [];
        }
        $class = $this->modelClass;
        $out = [];
        foreach ($this->rows as $row) {
            $out[] = new $class($row);
        }
        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /** 取某列的唯一值列表，常用于 `SELECT code` 这类投影查询的结果消费。 */
    public function pluck(string $column): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if (\array_key_exists($column, $row)) {
                $out[] = $row[$column];
            }
        }
        return $out;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->rows[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->rows[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->rows[] = $value;
            return;
        }
        $this->rows[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->rows[$offset]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->rows;
    }
}
