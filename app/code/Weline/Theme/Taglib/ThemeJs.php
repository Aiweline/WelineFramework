<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Framework\View\Data\DataInterface;
use Weline\Taglib\TaglibInterface;

class ThemeJs implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'theme:js';
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
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            return match ($tag_key) {
                'tag' => "<script {$tag_data[1]} src='{$template->fetchTagSource(DataInterface::dir_type_THEME, trim($tag_data[2]))}'></script>",
                default => "<script src='{$template->fetchTagSource(DataInterface::dir_type_THEME, trim($tag_data[1]))}'></script>"
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

    /**
     * @inheritDoc
     */
    public static function parent(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public static function document(): string
    {
        return '主题JavaScript文件标签，用于加载theme目录下的JS文件';
    }
}

