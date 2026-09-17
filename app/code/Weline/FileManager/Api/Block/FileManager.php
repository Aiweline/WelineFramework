<?php

namespace Weline\FileManager\Api\Block;

use Weline\FileManager\Api\Image;
use Weline\Framework\View\Block;

class FileManager extends Block
{
    public function render(): string
    {
        $value = $this->getParseVarsParams('value');
        $this->assign('value', $value ?: $this->getData('value'));
        // preview 默认为 '1'，只要不是显式设置为 false/'0'/0 就渲染预览容器
        $preview = $this->getData('preview');
        $showPreview = ($preview !== false && $preview !== '0' && $preview !== 0) ? '1' : '';
        $this->assign('preview', $showPreview);
        $size_alias = Image::getSize($this->getData('size'));
        $value = $this->getData('value') ?: '';
        $this->assign('value_items', Image::processImagesValuePreviewData($value, $this->getData('width'), $this->getData('height')));
        $this->assign('size_alias', $size_alias);
        $this->assignMediaConstraintHints();
        $this->assign('params', $this->getParams());
        return parent::render();
    }

    /**
     * Surface media_options constraints next to the picker button
     * (aspect_ratio / recommend size), matching Widget/Theme tag docs.
     */
    protected function assignMediaConstraintHints(): void
    {
        $aspectRatio = $this->resolveAspectRatioDisplay();
        $recommendW = trim((string)($this->getData('recommend_width') ?? ''));
        $recommendH = trim((string)($this->getData('recommend_height') ?? ''));
        $recommendSize = '';
        if ($recommendW !== '' && $recommendH !== ''
            && (int)$recommendW > 0 && (int)$recommendH > 0) {
            $recommendSize = (string)__('建议尺寸：%{1} × %{2} px', [$recommendW, $recommendH]);
        }
        $this->assign('aspect_ratio_display', $aspectRatio);
        $this->assign(
            'aspect_ratio_hint',
            $aspectRatio !== '' ? (string)__('推荐比例：%{1}', [$aspectRatio]) : ''
        );
        $this->assign('recommend_size_display', $recommendSize);
    }

    /**
     * Prefer explicit aspect_ratio; otherwise derive from recommend_width/height.
     * Display form always uses colon (16:5).
     */
    protected function resolveAspectRatioDisplay(): string
    {
        $explicit = trim((string)($this->getData('aspect_ratio') ?? ''));
        if ($explicit !== '' && preg_match('/^\d+(?:\.\d+)?\s*[:xX×\/]\s*\d+(?:\.\d+)?$/', $explicit) === 1) {
            $normalized = preg_replace('/\s+/', '', $explicit) ?? $explicit;
            $parts = preg_split('/[:xX×\/]/', $normalized) ?: [];
            if (count($parts) === 2) {
                $w = (float)$parts[0];
                $h = (float)$parts[1];
                if ($w > 0 && $h > 0) {
                    $wi = (int)round($w);
                    $hi = (int)round($h);
                    $g = $this->gcdPositiveInt($wi, $hi);

                    return ($wi / $g) . ':' . ($hi / $g);
                }
            }
        }
        $width = (int)trim((string)($this->getData('recommend_width') ?? ''));
        $height = (int)trim((string)($this->getData('recommend_height') ?? ''));
        if ($width > 0 && $height > 0) {
            $g = $this->gcdPositiveInt($width, $height);

            return ($width / $g) . ':' . ($height / $g);
        }

        return '';
    }

    protected function gcdPositiveInt(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);
        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return $a > 0 ? $a : 1;
    }

    public function getParams()
    {
        $params = [
            'isIframe' => true,
            'target' => $this->getData('target'),
            'preview' => $this->getData('preview'),
            'setAttr' => $this->getData('setAttr'),
            'close' => $this->getData('close'),
            'startPath' => $this->getData('path'),
            'lockPath' => $this->getData('lockPath'),
            'lockRoot' => $this->getData('lockRoot'),
            'multi' => $this->getData('multi'),
            'ext' => $this->getData('ext'),
            'size' => $this->getData('size'),
            'aspect_ratio' => $this->getData('aspect_ratio'),
            'aspect_ratio_tolerance' => $this->getData('aspect_ratio_tolerance'),
            'recommend_width' => $this->getData('recommend_width'),
            'recommend_height' => $this->getData('recommend_height'),
        ];
        foreach (['usage', 'locale_code'] as $optionalKey) {
            $optionalValue = $this->getData($optionalKey);
            if ($optionalValue !== null && $optionalValue !== '') {
                $params[$optionalKey] = $optionalValue;
            }
        }
        foreach ([
            'identity', 'identity_root', 'identity_code', 'identity_scope',
            'identity_kind', 'identity_field', 'identity_component', 'identity_locale',
            'identity_instance', 'identity_path', 'ref_mode', 'owner_type', 'owner_id',
            'owner_version', 'strong_ref',
        ] as $key) {
            $value = $this->getData($key);
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    public function doc()
    {
        return \Weline\FileManager\Taglib\FileManager::document();
    }
}
