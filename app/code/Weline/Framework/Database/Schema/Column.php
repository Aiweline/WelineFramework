<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * 列定义值对象，用于 install/upgrade 中的流式构建
 * @since 1.0.0
 */
final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int|string|null $length = null,
        public readonly bool $nullable = true,
        public readonly bool $primaryKey = false,
        public readonly bool $autoIncrement = false,
        public readonly mixed $default = null,
        public readonly string $comment = '',
        public readonly ?string $after = null,
        public readonly bool $unique = false,
    ) {
    }

    public static function integer(string $name, int $length = 11): self
    {
        return new self($name, 'INTEGER', $length, false, false, false, null, '', null, false);
    }

    public static function bigInteger(string $name): self
    {
        return new self($name, 'BIGINT', 20, false, false, false, null, '', null, false);
    }

    public static function varchar(string $name, int $length = 255): self
    {
        return new self($name, 'VARCHAR', $length, true, false, false, null, '', null, false);
    }

    public static function text(string $name): self
    {
        return new self($name, 'TEXT', null, true, false, false, null, '', null, false);
    }

    public static function datetime(string $name): self
    {
        return new self($name, 'DATETIME', null, true, false, false, null, '', null, false);
    }

    public function primaryKey(): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->length,
            $this->nullable,
            true,
            $this->autoIncrement,
            $this->default,
            $this->comment,
            $this->after,
            $this->unique,
        );
    }

    public function autoIncrement(): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->length,
            $this->nullable,
            $this->primaryKey,
            true,
            $this->default,
            $this->comment,
            $this->after,
            $this->unique,
        );
    }

    public function notNull(): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->length,
            false,
            $this->primaryKey,
            $this->autoIncrement,
            $this->default,
            $this->comment,
            $this->after,
            $this->unique,
        );
    }

    public function comment(string $comment): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->length,
            $this->nullable,
            $this->primaryKey,
            $this->autoIncrement,
            $this->default,
            $comment,
            $this->after,
            $this->unique,
        );
    }

    public function unique(): self
    {
        return new self(
            $this->name,
            $this->type,
            $this->length,
            $this->nullable,
            $this->primaryKey,
            $this->autoIncrement,
            $this->default,
            $this->comment,
            $this->after,
            true,
        );
    }
}
