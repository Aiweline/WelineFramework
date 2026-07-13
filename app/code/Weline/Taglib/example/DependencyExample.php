<?php

namespace Weline\Taglib\example;

// 此文件为纯示例文件，不参与反射编译和自动加载
// 标签依赖管理示例 - 仅供文档参考
if (defined('TAGLIB_EXAMPLE_LOADED')) {
    return;
}
define('TAGLIB_EXAMPLE_LOADED', true);

use Weline\Framework\Taglib\TaglibInterface;

/**
 * 标签依赖管理示例
 * 
 * 这个示例展示了如何创建具有依赖关系的标签
 */

/**
 * 父标签示例
 */
class ParentTag implements TaglibInterface
{
    public static function name(): string
    {
        return 'parent-tag';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['title' => false];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $title = $attributes['title'] ?? '父标签';
            $content = $tag_data[2] ?? '';
            
            return <<<HTML
<div class="parent-container">
    <h3>{$title}</h3>
    <div class="parent-content">
        {$content}
    </div>
</div>
HTML;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // 父标签没有依赖
    }

    public static function document(): string
    {
        return '父标签示例：<w:parent-tag title="标题">内容</w:parent-tag>';
    }
}

/**
 * 子标签示例
 */
class ChildTag implements TaglibInterface
{
    public static function name(): string
    {
        return 'child-tag';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['name' => false];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $name = $attributes['name'] ?? '子标签';
            $content = $tag_data[2] ?? '';
            
            return <<<HTML
<div class="child-container">
    <h4>{$name}</h4>
    <div class="child-content">
        {$content}
    </div>
</div>
HTML;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * 确保parent-tag在child-tag之前渲染
     */
    public static function parent(): ?string
    {
        return 'parent-tag';
    }

    public static function document(): string
    {
        return '子标签示例：<w:child-tag name="名称">内容</w:child-tag>';
    }
}

/**
 * 孙标签示例（多层依赖）
 */
class GrandChildTag implements TaglibInterface
{
    public static function name(): string
    {
        return 'grandchild-tag';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['type' => false];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $type = $attributes['type'] ?? '默认';
            $content = $tag_data[2] ?? '';
            
            return <<<HTML
<div class="grandchild-container">
    <span class="type">{$type}</span>
    <div class="grandchild-content">
        {$content}
    </div>
</div>
HTML;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，形成依赖链：grandchild-tag -> child-tag -> parent-tag
     */
    public static function parent(): ?string
    {
        return 'child-tag';
    }

    public static function document(): string
    {
        return '孙标签示例：<w:grandchild-tag type="类型">内容</w:grandchild-tag>';
    }
}

/**
 * 多父标签示例
 */
class MultiParentTag implements TaglibInterface
{
    public static function name(): string
    {
        return 'multi-parent-tag';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['type' => false];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $type = $attributes['type'] ?? '多父标签';
            $content = $tag_data[2] ?? '';
            
            return <<<HTML
<div class="multi-parent-container">
    <span class="type">{$type}</span>
    <div class="multi-parent-content">
        {$content}
    </div>
</div>
HTML;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定多个父标签，形成依赖关系：multi-parent-tag -> child-tag, parent-tag
     */
    public static function parent(): ?string
    {
        return 'child-tag,parent-tag';
    }

    public static function document(): string
    {
        return '多父标签示例：<w:multi-parent-tag type="类型">内容</w:multi-parent-tag>';
    }
}

/**
 * 独立标签示例（无依赖）
 */
class IndependentTag implements TaglibInterface
{
    public static function name(): string
    {
        return 'independent-tag';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['message' => false];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $message = $attributes['message'] ?? '独立标签';
            
            return <<<HTML
<div class="independent-container">
    <p>{$message}</p>
</div>
HTML;
        };
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // 独立标签没有依赖
    }

    public static function document(): string
    {
        return '独立标签示例：<w:independent-tag message="消息"></w:independent-tag>';
    }
} 