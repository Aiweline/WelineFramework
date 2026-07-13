<?php

declare(strict_types=1);

namespace Weline\Framework\Taglib;

/**
 * Framework-owned contract for declarative template tags.
 *
 * The implementation and discovery runtime may live in an optional module;
 * modules implementing a tag must not depend on that concrete runtime merely
 * to satisfy the public contract.
 */
interface TaglibInterface
{
    public static function name(): string;

    public static function tag(): bool;

    public static function attr(): array;

    public static function tag_start(): bool;

    public static function tag_end(): bool;

    public static function callback(): callable;

    public static function tag_self_close(): bool;

    public static function tag_self_close_with_attrs(): bool;

    public static function parent(): ?string;

    public static function document(): string;
}
