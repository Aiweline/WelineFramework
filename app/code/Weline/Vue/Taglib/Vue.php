<?php

namespace Weline\Vue\Taglib;

use Weline\Taglib\TaglibInterface;

class Vue implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'v';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            return match ($tag_key) {
                'tag' => '{{ ' . $tag_data[2] . ' }}',
                '@tag()', '@tag{}' => '{{ ' . $tag_data[1] . ' }}',
                default => '',
            };
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function document(): string
    {
        return '<v>demo</v>.解析到vue: {{ demo }}';
    }
}
