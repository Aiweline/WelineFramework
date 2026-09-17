<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\SystemConfig\Service\ConfigEmbedRenderer;

/**
 * Embed declared SystemConfig fields anywhere in backend templates.
 *
 * <w:config:embed module="Weline_Payment" field="payment/method/paypal/enabled" />
 */
final class ConfigEmbed implements TaglibInterface
{
    public static function name(): string
    {
        return 'config:embed';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function attr(): array
    {
        return [
            'module' => true,
            'area' => false,
            'field' => false,
            'fields' => false,
            'group' => false,
            'layout' => false,
            'template' => false,
            'locale' => false,
            'class' => false,
            // 实体页（网站/店/渠编辑）可强制写目标；有值时优先于 URL
            'target_scope' => false,
            'website_code' => false,
            'store_code' => false,
            'channel_code' => false,
            'scope_kind' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $code = AttributeCodeCompiler::attributes($attributes);

            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance('
                . '\\Weline\\SystemConfig\\Service\\ConfigEmbedRenderer::class)'
                . '->renderFromAttributes(['
                . '\'module\' => (string)($Taglib__module ?? \'\'),'
                . '\'area\' => (string)($Taglib__area ?? \'backend\'),'
                . '\'field\' => (string)($Taglib__field ?? \'\'),'
                . '\'fields\' => (string)($Taglib__fields ?? \'\'),'
                . '\'group\' => (string)($Taglib__group ?? \'\'),'
                . '\'layout\' => (string)($Taglib__layout ?? \'vertical\'),'
                . '\'template\' => (string)($Taglib__template ?? \'\'),'
                . '\'locale\' => (string)($Taglib__locale ?? \'default\'),'
                . '\'class\' => (string)($Taglib__class ?? \'\'),'
                . '\'target_scope\' => (string)($Taglib__target_scope ?? \'\'),'
                . '\'website_code\' => (string)($Taglib__website_code ?? \'\'),'
                . '\'store_code\' => (string)($Taglib__store_code ?? \'\'),'
                . '\'channel_code\' => (string)($Taglib__channel_code ?? \'\'),'
                . '\'scope_kind\' => (string)($Taglib__scope_kind ?? \'\'),'
                . ']); ?>';
        };
    }

    /**
     * 动态属性（如 field="<?= $enabledField ?>"）走 renderRuntimeTag，必须直接返回 HTML，
     * 不能再吐 PHP 源码（否则会原样显示在页面上）。
     */
    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            /** @var ConfigEmbedRenderer $renderer */
            $renderer = ObjectManager::getInstance(ConfigEmbedRenderer::class);

            return $renderer->renderFromAttributes([
                'module' => self::runtimeAttr($attributes, 'module'),
                'area' => self::runtimeAttr($attributes, 'area', 'backend'),
                'field' => self::runtimeAttr($attributes, 'field'),
                'fields' => self::runtimeAttr($attributes, 'fields'),
                'group' => self::runtimeAttr($attributes, 'group'),
                'layout' => self::runtimeAttr($attributes, 'layout', 'vertical'),
                'template' => self::runtimeAttr($attributes, 'template'),
                'locale' => self::runtimeAttr($attributes, 'locale', 'default'),
                'class' => self::runtimeAttr($attributes, 'class'),
                'target_scope' => self::runtimeAttr($attributes, 'target_scope'),
                'website_code' => self::runtimeAttr($attributes, 'website_code'),
                'store_code' => self::runtimeAttr($attributes, 'store_code'),
                'channel_code' => self::runtimeAttr($attributes, 'channel_code'),
                'scope_kind' => self::runtimeAttr($attributes, 'scope_kind'),
            ]);
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function runtimeAttr(array $attributes, string $key, string $default = ''): string
    {
        $value = $attributes[$key] ?? $default;
        if (\is_scalar($value) || $value === null) {
            $string = trim((string)$value);

            return $string !== '' ? $string : $default;
        }

        return $default;
    }

    public static function document(): string
    {
        return '<h3><code>&lt;w:config:embed&gt;</code> 使用文档</h3>'
            . '<p><strong>用途</strong>：在任意后台模板嵌入 <em>已声明</em> 的 SystemConfig 字段/分组/模块；'
            . '不替代配置中心整页，不在此声明新 key。</p>'
            . '<p><strong>属性</strong>：'
            . '<code>module</code>（必填）、'
            . '<code>area</code>（默认 backend）、'
            . '<code>field</code> / <code>fields</code> / <code>group</code>（选择优先级：field(s) → group → 整模块）、'
            . '<code>layout</code>（vertical｜horizontal｜inline）、'
            . '<code>template</code>（外壳 phtml）、'
            . '<code>locale</code>（默认 default）、'
            . '<code>class</code>（预留，默认壳暂未应用）、'
            . '<code>target_scope</code> / <code>website_code</code> / <code>store_code</code> / '
            . '<code>channel_code</code> / <code>scope_kind</code>（实体页强制写目标，优先于 URL）。</p>'
            . '<p><strong>行为</strong>：默认 Scope 只信 URL（无 Session）；实体页可用属性锁定范围；变更即时 '
            . '<code>system_config.setScopedConfig</code> + toast；无 UPDATE 灰显；'
            . '未声明红标不阻断同级；敏感只读深链配置中心。</p>'
            . '<p><strong>变量绑定</strong>：列表循环可用 '
            . '<code>module="embedModule" field="embedField"</code>（无 $ 的变量名）；'
            . '含 <code>&lt;?= ?&gt;</code> 的动态属性走 <code>runtimeCallback</code>，直接返回 HTML。</p>'
            . '<pre>&lt;w:config:embed module="Weline_Payment" field="payment/method/paypal/enabled" layout="inline" /&gt;</pre>'
            . '<p>完整指南：'
            . '<code>app/code/Weline/SystemConfig/doc/config-embed标签使用指南.md</code></p>';
    }
}
