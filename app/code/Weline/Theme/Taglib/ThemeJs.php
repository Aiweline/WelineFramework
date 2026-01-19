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
            
            // 对于成对标签，内容在 $tag_data[2]，属性在 $tag_data[1]
            // 对于 @tag() 或 @tag{} 格式，内容在 $tag_data[1]
            $content = '';
            $attrs = '';
            
            if ($tag_key === 'tag') {
                // 成对标签：<theme:js>content</theme:js>
                $attrs = $tag_data[1] ?? '';
                $content = trim($tag_data[2] ?? '');
            } else {
                // @tag() 或 @tag{} 格式
                $content = trim($tag_data[1] ?? '');
            }
            
            if (empty($content)) {
                return '';
            }
            
            try {
                $src = $template->fetchTagSource(DataInterface::dir_type_THEME, $content);
                
                $attrsStr = $attrs ? ' ' . trim($attrs) : '';
                $result = "<script{$attrsStr} src='{$src}'></script>";
                
                return $result;
            } catch (\Exception $e) {
                throw $e;
            }
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

