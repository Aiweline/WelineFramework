<?php

declare(strict_types=1);

namespace Weline\FileManager\Taglib;

use Weline\FileManager\Service\FileImageRenderer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\CompileTimeStaticMirror;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\Taglib\TaglibInterface;

final class Image implements TaglibInterface, StaticMirrorCapableInterface
{
    public static function name(): string
    {
        return 'file:image';
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
            'usage' => false,
            'asset' => false,
            'alt' => false,
            'decorative' => false,
            'locale' => false,
            'class' => false,
            'complement' => false,
            // UI 占位尺寸（HTML width/height，防 CLS）；与 CSS height:auto 组成 Google 响应式写法
            'width' => false,
            'height' => false,
            'aspect_ratio' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $attrs = is_array($attributes) ? $attributes : [];
            $mirrored = self::tryStaticMirror((string)$tagKey, is_array($tagData) ? $tagData : [], $attrs);
            if ($mirrored !== null) {
                return $mirrored;
            }

            $code = AttributeCodeCompiler::attributes($attrs);

            return '<?php ' . $code
                . ' echo \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\FileManager\\Service\\FileImageRenderer::class)'
                . '->renderFromMixed('
                . '$Taglib__usage ?? null, '
                . '(string)($Taglib__asset ?? \'\'), '
                . '(string)($Taglib__alt ?? \'\'), '
                . 'filter_var($Taglib__decorative ?? false, FILTER_VALIDATE_BOOL), '
                . '(string)($Taglib__locale ?? \'\'), '
                . '(string)($Taglib__class ?? \'\'), '
                . 'isset($Taglib__complement) ? filter_var($Taglib__complement, FILTER_VALIDATE_BOOL) : null, '
                . '$Taglib__width ?? null, '
                . '$Taglib__height ?? null, '
                . '(string)($Taglib__aspect_ratio ?? \'\')'
                . '); ?>';
        };
    }

    /**
     * Bake final &lt;img&gt; only when every attribute is a compile-time literal
     * and FileImageRenderer can resolve under the current RequestContext.
     * Any failure falls through to the runtime PHP emit path.
     */
    public static function tryStaticMirror(string $tagKey, array $tagData, array $attributes): ?string
    {
        if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs' && $tagKey !== 'tag') {
            return null;
        }

        $keys = array_keys(self::attr());
        if (!CompileTimeStaticMirror::attributesAreLiteral($attributes, $keys)) {
            return null;
        }

        $usage = $attributes['usage'] ?? null;
        $asset = CompileTimeStaticMirror::literalString($attributes['asset'] ?? '');
        $usageString = is_scalar($usage) || $usage === null
            ? CompileTimeStaticMirror::literalString($usage)
            : '';
        if ($usageString === '' && $asset === '') {
            return null;
        }

        $hasLayout = CompileTimeStaticMirror::literalString($attributes['width'] ?? '') !== ''
            || CompileTimeStaticMirror::literalString($attributes['height'] ?? '') !== ''
            || CompileTimeStaticMirror::literalString($attributes['aspect_ratio'] ?? '') !== '';
        if (!$hasLayout) {
            // CLS contract: refuse to mirror without layout hint (same as storefront guidance).
            return null;
        }

        try {
            /** @var FileImageRenderer $renderer */
            $renderer = ObjectManager::getInstance(FileImageRenderer::class);
            $html = $renderer->renderFromMixed(
                $usageString !== '' ? $usageString : null,
                $asset,
                CompileTimeStaticMirror::literalString($attributes['alt'] ?? ''),
                filter_var($attributes['decorative'] ?? false, FILTER_VALIDATE_BOOL),
                CompileTimeStaticMirror::literalString($attributes['locale'] ?? ''),
                CompileTimeStaticMirror::literalString($attributes['class'] ?? ''),
                array_key_exists('complement', $attributes)
                    ? filter_var($attributes['complement'], FILTER_VALIDATE_BOOL)
                    : null,
                $attributes['width'] ?? null,
                $attributes['height'] ?? null,
                CompileTimeStaticMirror::literalString($attributes['aspect_ratio'] ?? ''),
            );
        } catch (\Throwable) {
            return null;
        }

        $html = trim((string)$html);

        return $html !== '' ? $html : null;
    }

    public static function document(): string
    {
        return '<w:file:image usage="imageUsage" width="16" height="9" />'
            . ' or <w:file:image asset="assetId" alt="已确认的替代文本" aspect_ratio="16/9" complement="true" />'
            . ' — <strong>媒体出图</strong>（把已存 file-image / asset / path / asset:// 归一后渲成 img），不是选图器。'
            . '读侧兼容：ImageUsage、{type:file-image,usage}、裸 UUID、asset://UUID、相对媒体 path（按 object_key；失败空串）。'
            . '字面量属性且 RequestContext 可解析时编译期直出 &lt;img&gt;；否则仍吐运行期 PHP。'
            . '选图请用 &lt;w:file-manager /&gt; 或 WelineMedia（value_mode=file-image）。'
            . '须设 UI 宽高或 aspect_ratio（HTML width/height 防 CLS），主题 CSS max-width:100%;height:auto。'
            . '分工：app/code/Weline/FileManager/doc/file-manager-选图与file-image出图.md';
    }
}
