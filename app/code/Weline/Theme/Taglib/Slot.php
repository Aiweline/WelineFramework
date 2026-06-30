<?php

declare(strict_types=1);

/**
 * w:slot 主题插槽标签
 * 
 * 用于在布局模板中定义可填充的插槽区域。
 * 编译后生成带 data-wslot 属性的 HTML 元素，与现有 SlotRendererService 兼容。
 * 
 * @author Weline
 * @since 1.0.0
 */

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

/**
 * 插槽标签
 * 
 * 使用示例：
 * <w:slot id="content" name="主内容区">默认内容</w:slot>
 * <w:slot id="logo" accept="logo" exclusive="true"/>
 * <w:slot id="sidebar" accept="sidebar-*" reject="header" max="5"/>
 */
class Slot implements TaglibInterface
{
    /**
     * 运行时追踪已注册的 slot ID
     * 格式: ['slot-id' => 'file:line', ...]
     */
    private static array $registeredSlots = [];
    
    /**
     * 允许的 position 值
     */
    private const VALID_POSITIONS = ['header', 'content', 'footer', 'sidebar'];
    
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'slot';
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
     * 
     * 属性定义：
     * - 值 1 表示必填
     * - 值 0 表示可选
     */
    public static function attr(): array
    {
        return [
            'id' => 1,           // 必填：插槽唯一标识
            'name' => 0,         // 可选：显示名称（编辑器用）
            'accept' => 0,       // 可选：接受的部件类型，逗号分隔
            'reject' => 0,       // 可选：拒绝的部件类型，逗号分隔
            'exclusive' => 0,    // 可选：独占模式（部件替换整个内容）
            'multiple' => 0,     // 可选：允许多个部件
            'max' => 0,          // 可选：最大部件数量，-1 表示无限制
            'min' => 0,          // 可选：最小部件数量
            'position' => 0,     // 可选：位置类型：header/content/footer/sidebar
            'required' => 0,     // 可选：是否必须填充部件（DEV 警告）
            'append' => 0,       // 可选：部件追加到默认内容后
            'prepend' => 0,      // 可选：部件插入到默认内容前
            'wrapper' => 0,      // 可选：包裹元素标签
            'class' => 0,        // 可选：添加到包裹元素的 CSS 类
            'style' => 0,        // 可选：添加到包裹元素的内联样式
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 获取模板文件信息（用于错误提示）
            $file = $config['file'] ?? 'unknown';
            $line = $config['line'] ?? 0;
            
            // 根据标签类型处理
            if ($tag_key === 'tag-start') {
                // 验证并生成开始标签
                return self::processTagStart($attributes, $file, $line);
            }
            
            if ($tag_key === 'tag-end') {
                // 生成结束标签
                return self::processTagEnd($attributes);
            }
            
            // 完整标签处理（自闭合或成对标签）
            if ($tag_key === 'tag') {
                $content = $tag_data[2] ?? '';
                return self::processFullTag($attributes, $content, $file, $line);
            }
            
            return '';
        };
    }
    
    /**
     * 处理开始标签
     */
    private static function processTagStart(array $attrs, string $file, int $line): string
    {
        // 验证属性
        SlotValidator::validate($attrs, $file, $line);
        
        // 注册 slot ID（用于重复检测）
        $id = $attrs['id'];
        self::registerSlot($id, $file, $line);
        
        // 构建 HTML 属性
        $htmlAttrs = self::buildHtmlAttributes($attrs);
        
        // 获取包裹元素标签
        $wrapper = $attrs['wrapper'] ?? 'div';
        $wrapper = htmlspecialchars($wrapper, ENT_QUOTES, 'UTF-8');
        
        return "<{$wrapper}{$htmlAttrs}>";
    }
    
    /**
     * 处理结束标签
     */
    private static function processTagEnd(array $attrs): string
    {
        $wrapper = $attrs['wrapper'] ?? 'div';
        $wrapper = htmlspecialchars($wrapper, ENT_QUOTES, 'UTF-8');
        
        return "</{$wrapper}>";
    }
    
    /**
     * 处理完整标签（自闭合或成对）
     */
    private static function processFullTag(array $attrs, string $content, string $file, int $line): string
    {
        // 验证属性
        SlotValidator::validate($attrs, $file, $line);
        
        // 注册 slot ID
        $id = $attrs['id'];
        self::registerSlot($id, $file, $line);
        
        // 构建 HTML 属性
        $htmlAttrs = self::buildHtmlAttributes($attrs);
        
        // 获取包裹元素标签
        $wrapper = $attrs['wrapper'] ?? 'div';
        $wrapper = htmlspecialchars($wrapper, ENT_QUOTES, 'UTF-8');
        
        return "<{$wrapper}{$htmlAttrs}>{$content}</{$wrapper}>";
    }

    /**
     * Build HTML attributes.
     */
    private static function buildHtmlAttributes(array $attrs): string
    {
        $htmlAttrs = [];
        
        // 必填属性：id -> data-wslot
        $id = htmlspecialchars($attrs['id'], ENT_QUOTES, 'UTF-8');
        $htmlAttrs[] = "data-wslot=\"{$id}\"";
        
        // 可选属性映射
        $attrMapping = [
            'name' => 'data-wslot-name',
            'accept' => 'data-wslot-accept',
            'reject' => 'data-wslot-reject',
            'exclusive' => 'data-wslot-exclusive',
            'multiple' => 'data-wslot-multiple',
            'max' => 'data-wslot-max',
            'min' => 'data-wslot-min',
            'position' => 'data-wslot-position',
            'required' => 'data-wslot-required',
            'append' => 'data-wslot-append',
            'prepend' => 'data-wslot-prepend',
        ];
        
        foreach ($attrMapping as $key => $dataAttr) {
            if (isset($attrs[$key]) && $attrs[$key] !== '') {
                $value = $attrs[$key];
                // 布尔值转换
                if ($value === true || $value === 'true' || $value === '1') {
                    $value = 'true';
                } elseif ($value === false || $value === 'false' || $value === '0') {
                    $value = 'false';
                }
                $value = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                $htmlAttrs[] = "{$dataAttr}=\"{$value}\"";
            }
        }
        
        // 直接传递的 HTML 属性
        if (isset($attrs['class']) && $attrs['class'] !== '') {
            $class = htmlspecialchars($attrs['class'], ENT_QUOTES, 'UTF-8');
            $htmlAttrs[] = "class=\"{$class}\"";
        }
        
        if (isset($attrs['style']) && $attrs['style'] !== '') {
            $style = htmlspecialchars($attrs['style'], ENT_QUOTES, 'UTF-8');
            $htmlAttrs[] = "style=\"{$style}\"";
        }
        
        return $htmlAttrs ? ' ' . implode(' ', $htmlAttrs) : '';
    }
    
    /**
     * 注册 slot ID（用于重复检测）
     */
    private static function registerSlot(string $id, string $file, int $line): void
    {
        $location = "{$file}:{$line}";
        
        // DEV 模式下检测重复
        if (defined('DEV') && DEV) {
            if (isset(self::$registeredSlots[$id])) {
                $existingLocation = self::$registeredSlots[$id];
                SlotValidator::throwDuplicateError($id, $existingLocation, $location);
            }
        }
        
        self::$registeredSlots[$id] = $location;
    }
    
    /**
     * 清除已注册的 slots（用于测试或重新编译）
     */
    public static function clearRegisteredSlots(): void
    {
        self::$registeredSlots = [];
    }
    
    /**
     * 获取已注册的 slots
     */
    public static function getRegisteredSlots(): array
    {
        return self::$registeredSlots;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
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
        return <<<'DOC'
w:slot 主题插槽标签

用于在布局模板中定义可填充的插槽区域。编译后生成带 data-wslot 属性的 HTML 元素。

属性说明：
- id (必填): 插槽唯一标识
- name: 显示名称（编辑器用）
- accept: 接受的部件类型，逗号分隔，支持通配符 *
- reject: 拒绝的部件类型，逗号分隔
- exclusive: 独占模式（部件替换整个内容）
- multiple: 允许多个部件
- max: 最大部件数量，-1 表示无限制
- min: 最小部件数量
- position: 位置类型：header/content/footer/sidebar
- required: 是否必须填充部件（DEV 警告）
- append: 部件追加到默认内容后
- prepend: 部件插入到默认内容前
- wrapper: 包裹元素标签（默认 div）
- class: CSS 类
- style: 内联样式

使用示例：
<w:slot id="content" name="主内容区">默认内容</w:slot>
<w:slot id="logo" accept="logo" exclusive="true"/>
<w:slot id="sidebar" accept="sidebar-*" max="5" position="sidebar"/>
DOC;
    }
}
