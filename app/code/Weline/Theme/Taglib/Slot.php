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

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Theme\Service\SlotBoundaryMarkers;

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
    private const REQUEST_REGISTERED_SLOTS_KEY = 'theme.taglib.registered_slots.v1';
    
    /**
     * 允许的 position 值
     */
    private const VALID_POSITIONS = [
        'header',
        'content',
        'footer',
        'sidebar',
        'dashboard-summary',
        'dashboard-analysis',
        'dashboard-side',
        'dashboard-detail',
    ];
    
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
            'position' => 0,     // 可选：位置类型：header/content/footer/sidebar/dashboard-*
            'required' => 0,     // 可选：是否必须填充部件（DEV 警告）
            'append' => 0,       // 可选：部件追加到默认内容后
            'prepend' => 0,      // 可选：部件插入到默认内容前
            'wrapper' => 0,      // 可选：包裹元素标签
            'class' => 0,        // 可选：添加到包裹元素的 CSS 类
            'style' => 0,        // 可选：添加到包裹元素的内联样式
            'layout' => 0,       // 可选：部件配置来源布局类型（如 mini-cart）
            'weline-code' => 0,  // 可选：事件溯源区块 code（wrapper=section 时由 Validator 强制）
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

    /** Dynamic attributes are resolved after template execution: return HTML, not PHP source. */
    public static function runtimeCallback(): callable
    {
        return static function (
            \Weline\Framework\View\Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            SlotValidator::validate($attributes, 'unknown', 0);
            $id = (string)$attributes['id'];
            self::registerSlot($id, 'unknown', 0);
            $wrapper = htmlspecialchars((string)($attributes['wrapper'] ?? 'div'), ENT_QUOTES, 'UTF-8');

            if (\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers()) {
                return SlotBoundaryMarkers::open($id)
                    . '<' . $wrapper . self::buildHtmlAttributes($attributes) . '>'
                    . $content . '</' . $wrapper . '>' . SlotBoundaryMarkers::close($id);
            }

            $class = htmlspecialchars(trim('theme-published-slot ' . (string)($attributes['class'] ?? '')), ENT_QUOTES, 'UTF-8');
            $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            return '<' . $wrapper . ' class="' . $class . '" data-slot-id="' . $safeId . '">'
                . \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::publishedInner($id, $content)
                . '</' . $wrapper . '>';
        };
    }
    
    /**
     * 处理开始标签
     *
     * wave8-8s: published storefront emits bake via PublishedSlotHost (no data-wslot);
     * editor/preview/backend keep reactive markers for fill / processSlots.
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
        $safeId = htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8');
        $extraClass = \trim((string)($attrs['class'] ?? ''));
        $publishedClass = \htmlspecialchars(
            \trim('theme-published-slot' . ($extraClass !== '' ? ' ' . $extraClass : '')),
            ENT_QUOTES,
            'UTF-8',
        );

        // Published: open wrapper first, then Fiber-local body capture (never stringify into quotes).
        // Native ob_start is process-global and unsafe under WLS Fiber concurrency (align Form 2.5.101).
        return '<?php if (\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers()): ?>'
            . SlotBoundaryMarkers::open($id) . "<{$wrapper}{$htmlAttrs}>"
            . '<?php else: ?>'
            . "<{$wrapper} class=\"{$publishedClass}\" data-slot-id=\"{$safeId}\">"
            . '<?php \\Weline\\Framework\\Runtime\\FiberOutputBuffer::beginCapture(); ?>'
            . '<?php endif; ?>';
    }
    
    /**
     * 处理结束标签
     */
    private static function processTagEnd(array $attrs): string
    {
        $wrapper = $attrs['wrapper'] ?? 'div';
        $wrapper = htmlspecialchars($wrapper, ENT_QUOTES, 'UTF-8');
        $id = (string) ($attrs['id'] ?? '');
        $idExport = \var_export($id, true);

        return '<?php if (\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers()): ?>'
            . "</{$wrapper}>" . SlotBoundaryMarkers::close($id)
            . '<?php else: '
            . '$__welinePublishedSlotDefault = (string)\\Weline\\Framework\\Runtime\\FiberOutputBuffer::endCapture(); '
            . 'echo \\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::publishedInner('
            . $idExport . ', $__welinePublishedSlotDefault); '
            . '?>'
            . "</{$wrapper}>"
            . '<?php endif; ?>';
    }
    
    /**
     * 处理完整标签（自闭合或成对）
     *
     * Hotfix: never embed compiled default HTML/PHP inside a single-quoted
     * publishedInner(..., '...') literal — nested quotes / `UTF-8` / `<?=` cause ParseError.
     * Non-empty body → FiberOutputBuffer beginCapture/endCapture (discard on error); empty → var_export('').
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
        $idExport = \var_export((string)$id, true);
        $safeId = htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8');
        $extraClass = \trim((string)($attrs['class'] ?? ''));
        $publishedClass = \htmlspecialchars(
            \trim('theme-published-slot' . ($extraClass !== '' ? ' ' . $extraClass : '')),
            ENT_QUOTES,
            'UTF-8',
        );

        $reactive = SlotBoundaryMarkers::open($id)
            . "<{$wrapper}{$htmlAttrs}>{$content}</{$wrapper}>"
            . SlotBoundaryMarkers::close($id);

        if (\trim($content) === '') {
            return '<?php if (\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers()): ?>'
                . $reactive
                . '<?php else: ?>'
                . "<{$wrapper} class=\"{$publishedClass}\" data-slot-id=\"{$safeId}\">"
                . '<?php echo \\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::publishedInner('
                . $idExport . ', ' . \var_export('', true) . '); ?>'
                . "</{$wrapper}>"
                . '<?php endif; ?>';
        }

        return '<?php if (\\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers()): ?>'
            . $reactive
            . '<?php else: ?>'
            . "<{$wrapper} class=\"{$publishedClass}\" data-slot-id=\"{$safeId}\">"
            . '<?php \\Weline\\Framework\\Runtime\\FiberOutputBuffer::beginCapture(); try { ?>'
            . $content
            . '<?php $__welinePublishedSlotDefault = (string)\\Weline\\Framework\\Runtime\\FiberOutputBuffer::endCapture(); '
            . 'echo \\Weline\\Theme\\Service\\LayoutEntity\\ThemeLayoutEntityPublishedSlotHost::publishedInner('
            . $idExport . ', $__welinePublishedSlotDefault); '
            . '} catch (\\Throwable $__weline_slot_e) { '
            . '\\Weline\\Framework\\Runtime\\FiberOutputBuffer::discardCapture(); '
            . 'throw $__weline_slot_e; } ?>'
            . "</{$wrapper}>"
            . '<?php endif; ?>';
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
            'layout' => 'data-wslot-layout',
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

        if (isset($attrs['weline-code']) && $attrs['weline-code'] !== '') {
            $welineCode = htmlspecialchars((string)$attrs['weline-code'], ENT_QUOTES, 'UTF-8');
            $htmlAttrs[] = "weline-code=\"{$welineCode}\"";
        }
        
        return $htmlAttrs ? ' ' . implode(' ', $htmlAttrs) : '';
    }
    
    /**
     * 注册 slot ID（用于重复检测）
     */
    private static function registerSlot(string $id, string $file, int $line): void
    {
        $location = "{$file}:{$line}";
        $registeredSlots = self::getRegisteredSlots();
        
        // DEV 模式下检测重复
        if (defined('DEV') && DEV) {
            if (isset($registeredSlots[$id])) {
                $existingLocation = $registeredSlots[$id];
                SlotValidator::throwDuplicateError($id, $existingLocation, $location);
            }
        }
        
        $registeredSlots[$id] = $location;
        RequestContext::set(self::REQUEST_REGISTERED_SLOTS_KEY, $registeredSlots);
    }
    
    /**
     * 清除已注册的 slots（用于测试或重新编译）
     */
    public static function clearRegisteredSlots(): void
    {
        RequestContext::remove(self::REQUEST_REGISTERED_SLOTS_KEY);
    }
    
    /**
     * 获取已注册的 slots
     */
    public static function getRegisteredSlots(): array
    {
        $registeredSlots = RequestContext::get(self::REQUEST_REGISTERED_SLOTS_KEY, []);
        return is_array($registeredSlots) ? $registeredSlots : [];
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
- position: 位置类型：header/content/footer/sidebar/dashboard-summary/dashboard-analysis/dashboard-side/dashboard-detail
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
<w:slot id="dashboard-summary" accept="dashboard-stat,dashboard-kpi" max="4" position="dashboard-summary"/>
<w:slot id="user-area" accept="account,mini-cart-icon" multiple="true">
  <w:widget type="header" name="account" />
</w:slot>

说明：slot 内可直接嵌套 w:widget 作为默认展示；未改动不写布局，用户改过才按 template_ref copy-on-write 物化。
DOC;
    }
}
