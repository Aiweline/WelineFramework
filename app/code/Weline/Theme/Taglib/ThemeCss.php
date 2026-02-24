<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Framework\View\Data\DataInterface;
use Weline\Taglib\TaglibInterface;

/**
 * theme:css 标签：仅处理主题样式（THEME，如 Weline_Theme::theme/...）。
 * 与框架内置的 css 标签（STATICS，Module::statics/...）是不同标签，不可混用。
 */
class ThemeCss implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'theme:css';
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
            
            // 框架约定：tag_data[0]=rawTag, [1]=rawAttributes/内联内容, [2]=子内容
            // 运行期 tag-start 时内容在 [2]，编译期 tag 时内容在 [2]、属性在 [1]
            // 若 [1] 被误当作路径（含 :: 或 /），不得作为 HTML 属性输出，并优先当作 content
            $raw1 = trim((string)($tag_data[1] ?? ''));
            $raw2 = trim((string)($tag_data[2] ?? ''));
            $looksLikePath = $raw1 !== '' && (str_contains($raw1, '::') || str_contains($raw1, '/'));
            
            if ($tag_key === 'tag') {
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = (!$looksLikePath && $raw1 !== '') ? $raw1 : '';
            } elseif ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                $content = $raw1;
                $attrs = '';
            } else {
                // tag-start 等：运行期内容在 [2]
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = '';
            }
            if (empty($content)) {
                return '';
            }
            
            try {
                $href = $template->fetchTagSource(DataInterface::dir_type_THEME, $content);
                $attrsStr = $attrs ? ' ' . trim($attrs) : '';
                $result = "<link{$attrsStr} href='{$href}' rel=\"stylesheet\" type=\"text/css\"/>";
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
        return '主题CSS文件标签，用于加载theme目录下的CSS文件';
    }
}

