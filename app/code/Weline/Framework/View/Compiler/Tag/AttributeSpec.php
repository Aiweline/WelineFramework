<?php

declare(strict_types=1);

/**
 * 属性声明（Resolve 阶段用它校验并给出可读错误）。
 *
 * 现在只做「类型标签 + 是否必填」，不引入表达式求值：求值仍在 Lower/Emit 侧，
 * 因为属性里允许写 PHP 表达式是既有语义（C5：行为不变）。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

final class AttributeSpec
{
    public const LITERAL = 'literal';
    public const EXPR = 'expr';
    public const BOOL = 'bool';
    public const ENUM = 'enum';
    public const ANY = 'any';

    /**
     * @param list<string> $enum
     */
    private function __construct(
        public readonly string $type,
        public readonly bool $required = false,
        public readonly array $enum = [],
        public readonly string $hint = '',
    ) {
    }

    public static function literal(bool $required = false, string $hint = ''): self
    {
        return new self(self::LITERAL, $required, [], $hint);
    }

    public static function expr(bool $required = false, string $hint = ''): self
    {
        return new self(self::EXPR, $required, [], $hint);
    }

    public static function bool(bool $required = false, string $hint = ''): self
    {
        return new self(self::BOOL, $required, [], $hint);
    }

    /**
     * @param list<string> $values
     */
    public static function enum(array $values, bool $required = false, string $hint = ''): self
    {
        return new self(self::ENUM, $required, $values, $hint);
    }

    public static function any(bool $required = false, string $hint = ''): self
    {
        return new self(self::ANY, $required, [], $hint);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}
