<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Api\Param\FileImagePreviewResolverInterface;
use Weline\Widget\Api\Param\ParamDefinition;

/**
 * Widget 参数类型抽象基类
 *
 * 提供通用的 HTML 渲染辅助方法和默认实现。
 * 所有可翻译字段的多语言 UI 由 renderTranslatableWrap() 统一包装，
 * 各子类无需关心 i18n 实现。
 */
abstract class AbstractParamType implements WidgetParamTypeInterface
{
    protected const CSS_PREFIX = 'w-param-';

    /**
     * 推断字段是否可翻译：显式 i18n / translate / translatable 优先，
     * 否则文本类与图片 UI（media_image 等）默认 true。关闭：'i18n' => false
     */
    public static function isTranslatable(array $param): bool
    {
        return ParamDefinition::isTranslatable($param);
    }

    protected function generateFieldId(string $key, int|string $layoutId): string
    {
        return 'config_' . $layoutId . '_' . str_replace('.', '_', $key);
    }

    /**
     * Hex identity is node_uid only; keep data-layout-id for non-hex legacy keys.
     *
     * @return array<string, string>
     */
    public static function widgetIdentityAttrMap(int|string $layoutId): array
    {
        $raw = \trim((string)$layoutId);
        $nodeUid = \strtolower($raw);
        if ($nodeUid !== '' && \preg_match('/^[a-f0-9]{32}$/D', $nodeUid) === 1) {
            return ['data-node-uid' => $nodeUid];
        }
        if ($raw !== '' && $raw !== '0') {
            return ['data-layout-id' => $raw];
        }

        return [];
    }

    public static function widgetIdentityAttrHtml(int|string $layoutId): string
    {
        $parts = [];
        foreach (self::widgetIdentityAttrMap($layoutId) as $name => $value) {
            $parts[] = $name . '="' . \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return \implode(' ', $parts);
    }

    protected function buildAttrString(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === true) {
                $parts[] = htmlspecialchars($name);
            } elseif ($value !== false && $value !== null) {
                $parts[] = htmlspecialchars($name) . '="' . htmlspecialchars((string)$value) . '"';
            }
        }
        return implode(' ', $parts);
    }

    /** @return array{type:string,usage:array<string,mixed>}|null */
    protected function normalizeFileImageNode(mixed $value): ?array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed[0] !== '{') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return null;
            }
            $value = $decoded;
        }
        if (!is_array($value) || ($value['type'] ?? null) !== 'file-image' || !is_array($value['usage'] ?? null)) {
            return null;
        }
        $usage = $value['usage'];
        if ((int)($usage['version'] ?? 0) !== 1
            || trim((string)($usage['asset_id'] ?? '')) === ''
            || trim((string)($usage['locale_code'] ?? '')) === ''
        ) {
            return null;
        }

        return ['type' => 'file-image', 'usage' => $usage];
    }

    protected function serializeImageFormValue(mixed $value): string
    {
        $node = $this->normalizeFileImageNode($value);
        if ($node !== null) {
            return json_encode(
                $node,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?: '';
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    protected function imagePreviewUrl(mixed $value): string
    {
        $legacy = $this->legacyImagePreviewUrl($value);
        if ($legacy !== '') {
            return $legacy;
        }

        $node = $this->normalizeFileImageNode($value);
        if ($node === null) {
            return '';
        }

        try {
            $resolver = ObjectManager::getInstance(FileImagePreviewResolverInterface::class);
            return trim($resolver->resolvePreviewUrl($node));
        } catch (\Throwable) {
            return '';
        }
    }

    protected function buildImageHiddenInputExtraAttrs(mixed $currentValue): string
    {
        $previewUrl = $this->imagePreviewUrl($currentValue);
        if ($previewUrl === '') {
            return '';
        }

        return ' data-preview-url="' . htmlspecialchars($previewUrl) . '"';
    }

    /**
     * Prefer WelineMedia / file-picker (typed file-image). Fallback keeps the legacy
     * overlay button so array item editors and degraded hosts still work.
     *
     * @param array{
     *     array_field?: string,
     *     omit_name?: bool,
     *     input_class?: string
     * } $options array_field set → item editor: data-field + w-param-array-item-input, omit name
     */
    protected function renderMediaLibraryPickerHtml(
        string $fieldId,
        string $key,
        array $param,
        mixed $currentValue,
        string $selectButtonClass = 'w-param-media-image-select',
        array $options = [],
    ): string {
        $hasImage = !empty($currentValue);
        $storedValue = $this->serializeImageFormValue($currentValue);
        $previewUrl = $this->imagePreviewUrl($currentValue);
        $mediaKind = $this->resolveMediaPickerKind($param);
        $isAudioMedia = $mediaKind === 'audio';
        $placeholder = $param['placeholder']
            ?? ($isAudioMedia ? __('从媒体库选择曲目') : __('从媒体库选择图片'));
        $defaultDir = $this->mediaOptionValue($param, 'default_directory');
        if ($defaultDir === '') {
            $defaultDir = $isAudioMedia ? 'store-music' : 'banner';
        }
        $ext = $this->resolveMediaPickerExt($param, $isAudioMedia);
        $size = $this->resolveMediaPickerSize($param, $isAudioMedia);
        $valueMode = $this->resolveMediaPickerValueMode($param, $isAudioMedia);
        $usage = $this->resolveMediaPickerUsage($param, $isAudioMedia);
        $pickerTitle = $this->mediaOptionValue($param, 'picker_title');
        if ($pickerTitle === '') {
            $pickerTitle = $isAudioMedia ? (string)__('选择曲目') : (string)__('从图库选择');
        }
        $clearLabel = $isAudioMedia ? (string)__('清除曲目') : (string)__('清除图片');
        $aspectRatio = $this->resolveMediaAspectRatio($param);
        $recommendW = $this->mediaOptionValue($param, 'recommend_width');
        $recommendH = $this->mediaOptionValue($param, 'recommend_height');
        $aspectTolerance = $this->mediaOptionValue($param, 'aspect_ratio_tolerance');

        $arrayField = trim((string)($options['array_field'] ?? ''));
        $omitName = $arrayField !== '' || !empty($options['omit_name']);
        $inputClass = trim((string)($options['input_class'] ?? ''));
        if ($arrayField !== '' && $inputClass === '') {
            $inputClass = 'w-param-array-item-input';
        }

        $inputHtml = '<div class="w-param-media-image" data-w-param-media="file-picker"'
            . ($isAudioMedia ? ' data-media-kind="audio"' : '') . '>';
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '"';
        if (!$omitName) {
            $inputHtml .= ' name="' . htmlspecialchars($key) . '"';
        }
        if ($inputClass !== '') {
            $inputHtml .= ' class="' . htmlspecialchars($inputClass) . '"';
        }
        if ($arrayField !== '') {
            $inputHtml .= ' data-field="' . htmlspecialchars($arrayField) . '"';
        }
        $inputHtml .= ' value="' . htmlspecialchars($storedValue) . '" data-preview="' . htmlspecialchars($fieldId)
            . '_preview" data-clear-label="' . htmlspecialchars($clearLabel) . '"'
            . $this->buildImageHiddenInputExtraAttrs($currentValue) . '>';

        $canUseFilePicker = class_exists(\Weline\MediaManager\Block\WelineMedia::class)
            && \function_exists('framework_view_process_block');
        if ($canUseFilePicker) {
            $block = [
                'class' => \Weline\MediaManager\Block\WelineMedia::class,
                'target' => $fieldId,
                'picker_title' => $pickerTitle,
                'path' => $defaultDir,
                'value' => $storedValue !== '' ? $storedValue : '',
                'preview' => '1',
                'multi' => '0',
                'ext' => $ext,
                'size' => $size,
                'width' => 96,
                'height' => 96,
                'usage' => $usage,
                'value_mode' => $valueMode,
                'lockPath' => '0',
            ];
            if ($aspectRatio !== '') {
                $block['aspect_ratio'] = $aspectRatio;
            }
            if ($recommendW !== '') {
                $block['recommend_width'] = $recommendW;
            }
            if ($recommendH !== '') {
                $block['recommend_height'] = $recommendH;
            }
            if ($aspectTolerance !== '') {
                $block['aspect_ratio_tolerance'] = $aspectTolerance;
            }
            $obLevel = ob_get_level();
            try {
                $pickerHtml = (string)framework_view_process_block($block);
            } catch (\Throwable) {
                while (ob_get_level() > $obLevel) {
                    ob_end_clean();
                }
                $pickerHtml = '';
            }
            if ($pickerHtml !== '' && str_contains($pickerHtml, 'w-file-picker')) {
                $inputHtml .= $pickerHtml;
                // Keep a hidden select trigger so i18n media panels can still detect media fields.
                $inputHtml .= '<button type="button" class="w-button ' . htmlspecialchars($selectButtonClass)
                    . '" hidden data-tone="primary" data-variant="outline" data-size="sm" data-target="'
                    . htmlspecialchars($fieldId) . '"' . $this->mediaImageSelectDataAttrs($param)
                    . ' title="' . htmlspecialchars($pickerTitle) . '">'
                    . htmlspecialchars($isAudioMedia ? (string)__('选择曲目') : (string)__('选择')) . '</button>';
                $inputHtml .= '</div>';

                return $inputHtml;
            }
        }

        $previewAttrs = $this->mediaImagePreviewShellAttrs($param);
        $inputHtml .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="'
            . htmlspecialchars($fieldId) . '_preview"' . $previewAttrs . '>';
        $inputHtml .= $this->mediaImageAspectBadgeHtml($param);
        if ($previewUrl !== '') {
            $inputHtml .= '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . htmlspecialchars((string)__('预览')) . '">';
        }
        $inputHtml .= '<div class="w-param-image-placeholder"' . ($hasImage ? ' hidden' : '') . '>'
            . htmlspecialchars((string)$placeholder) . '</div>';
        $inputHtml .= '<div class="w-param-image-actions">';
        $inputHtml .= '<button type="button" class="w-button ' . htmlspecialchars($selectButtonClass)
            . '" data-tone="primary" data-variant="outline" data-size="sm" data-target="'
            . htmlspecialchars($fieldId) . '"' . $this->mediaImageSelectDataAttrs($param)
            . ' title="' . htmlspecialchars($pickerTitle) . '">'
            . htmlspecialchars($isAudioMedia ? (string)__('选择曲目') : (string)__('选择')) . '</button>';
        if ($hasImage) {
            $inputHtml .= '<button type="button" class="w-button w-param-image-clear" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-target="'
                . htmlspecialchars($fieldId) . '" aria-label="' . htmlspecialchars($clearLabel) . '">×</button>';
        }
        $inputHtml .= '</div></div></div>';

        return $inputHtml;
    }

    protected function resolveMediaPickerKind(array $param): string
    {
        $explicit = strtolower($this->mediaOptionValue($param, 'kind'));
        if (in_array($explicit, ['audio', 'image', 'file'], true)) {
            return $explicit;
        }
        $ext = strtolower($this->mediaOptionValue($param, 'ext'));
        if ($ext === '') {
            return 'image';
        }
        $audioExt = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'wma', 'weba'];
        foreach (preg_split('/\s*,\s*/', $ext) ?: [] as $part) {
            if (in_array(ltrim((string)$part, '.'), $audioExt, true)) {
                return 'audio';
            }
        }

        return 'image';
    }

    protected function resolveMediaPickerExt(array $param, bool $isAudioMedia): string
    {
        $ext = $this->mediaOptionValue($param, 'ext');
        if ($ext !== '') {
            return $ext;
        }

        return $isAudioMedia
            ? 'mp3,wav,ogg,oga,m4a,aac,flac,opus,wma,weba'
            : 'jpg,jpeg,png,gif,webp';
    }

    protected function resolveMediaPickerSize(array $param, bool $isAudioMedia): int
    {
        $sizeRaw = $this->mediaOptionValue($param, 'size');
        if ($sizeRaw !== '' && ctype_digit($sizeRaw)) {
            return max(1, (int)$sizeRaw);
        }

        return $isAudioMedia ? 20971520 : 5242880;
    }

    protected function resolveMediaPickerValueMode(array $param, bool $isAudioMedia): string
    {
        $mode = $this->mediaOptionValue($param, 'value_mode');
        if ($mode !== '') {
            return $mode;
        }

        // Audio tracks persist as media paths; file-image usage is image-only.
        return $isAudioMedia ? '' : 'file-image';
    }

    protected function resolveMediaPickerUsage(array $param, bool $isAudioMedia): string
    {
        $usage = $this->mediaOptionValue($param, 'usage');
        if ($usage !== '') {
            return $usage;
        }

        return $isAudioMedia ? '0' : '1';
    }

    protected function mediaOptionValue(array $param, string $key): string
    {
        $mediaOptions = is_array($param['media_options'] ?? null) ? $param['media_options'] : [];
        $value = $mediaOptions[$key] ?? $param[$key] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
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

    /**
     * Resolve hard aspect-ratio constraint for media pickers.
     * Prefer explicit aspect_ratio; otherwise derive from both recommend dimensions.
     */
    protected function resolveMediaAspectRatio(array $param): string
    {
        $explicit = $this->mediaOptionValue($param, 'aspect_ratio');
        if ($explicit !== '' && preg_match('/^\d+(?:\.\d+)?\s*[:xX×\/]\s*\d+(?:\.\d+)?$/', $explicit) === 1) {
            return preg_replace('/\s+/', '', $explicit) ?? $explicit;
        }
        $width = (int)$this->mediaOptionValue($param, 'recommend_width');
        $height = (int)$this->mediaOptionValue($param, 'recommend_height');
        if ($width > 0 && $height > 0) {
            $g = $this->gcdPositiveInt($width, $height);

            return ($width / $g) . ':' . ($height / $g);
        }

        return '';
    }

    protected function mediaImageSelectDataAttrs(array $param): string
    {
        $isAudioMedia = $this->resolveMediaPickerKind($param) === 'audio';
        $defaultDir = $this->mediaOptionValue($param, 'default_directory')
            ?: ($isAudioMedia ? 'store-music' : 'banner');
        $recommendW = $this->mediaOptionValue($param, 'recommend_width');
        $recommendH = $this->mediaOptionValue($param, 'recommend_height');
        $aspectRatio = $this->resolveMediaAspectRatio($param);
        $aspectTolerance = $this->mediaOptionValue($param, 'aspect_ratio_tolerance');
        $ext = $this->resolveMediaPickerExt($param, $isAudioMedia);
        $size = (string)$this->resolveMediaPickerSize($param, $isAudioMedia);
        $usage = $this->resolveMediaPickerUsage($param, $isAudioMedia);
        $valueMode = $this->resolveMediaPickerValueMode($param, $isAudioMedia);
        $pickerTitle = $this->mediaOptionValue($param, 'picker_title');
        if ($pickerTitle === '') {
            $pickerTitle = $isAudioMedia ? (string)__('选择曲目') : (string)__('选择媒体');
        }
        $attrs = ' data-default-dir="' . htmlspecialchars($defaultDir) . '"'
            . ' data-ext="' . htmlspecialchars($ext) . '"'
            . ' data-size="' . htmlspecialchars($size) . '"'
            . ' data-usage="' . htmlspecialchars($usage) . '"'
            . ' data-value-mode="' . htmlspecialchars($valueMode) . '"'
            . ' data-picker-title="' . htmlspecialchars($pickerTitle) . '"'
            . ($isAudioMedia ? ' data-media-kind="audio"' : '');
        if ($recommendW !== '') {
            $attrs .= ' data-recommend-w="' . htmlspecialchars($recommendW) . '"';
        }
        if ($recommendH !== '') {
            $attrs .= ' data-recommend-h="' . htmlspecialchars($recommendH) . '"';
        }
        if ($aspectRatio !== '') {
            $attrs .= ' data-aspect-ratio="' . htmlspecialchars($aspectRatio) . '"';
        }
        if ($aspectTolerance !== '') {
            $attrs .= ' data-aspect-ratio-tolerance="' . htmlspecialchars($aspectTolerance) . '"';
        }

        return $attrs;
    }

    protected function mediaImagePreviewShellAttrs(array $param): string
    {
        $aspectRatio = $this->resolveMediaAspectRatio($param);
        if ($aspectRatio === '') {
            return '';
        }
        $cssRatio = str_replace(':', ' / ', $aspectRatio);

        return ' data-aspect-ratio="' . htmlspecialchars($aspectRatio) . '"'
            . ' style="aspect-ratio:' . htmlspecialchars($cssRatio) . ';"';
    }

    protected function mediaImageAspectBadgeHtml(array $param): string
    {
        $aspectRatio = $this->resolveMediaAspectRatio($param);
        if ($aspectRatio === '') {
            return '';
        }

        return '<span class="w-param-image-aspect-badge">'
            . htmlspecialchars((string)__('比例 %{1}', $aspectRatio))
            . '</span>';
    }

    protected function legacyImagePreviewUrl(mixed $value): string
    {
        if ($this->normalizeFileImageNode($value) !== null || !is_scalar($value)) {
            return '';
        }
        $url = trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === ''
            || strlen($url) > 8192
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1
            || str_starts_with($url, '//')
        ) {
            return '';
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && (!in_array($scheme, ['http', 'https'], true)
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass']))) {
            return '';
        }
        return isset($parts['host']) && $scheme === '' ? '' : $url;
    }

    /**
     * Accept only a single CSS colour scalar suitable for an escaped custom
     * property value. Delimiters that could terminate the declaration are
     * rejected; URLs, variables and arbitrary CSS functions are not allowed.
     */
    protected function normalizeCssColorScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $color = trim((string)$value);
        if ($color === '' || strlen($color) > 96 || preg_match('/[;\"\'\\\\{}<>]/', $color)) {
            return null;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            return $color;
        }
        if (preg_match('/^[a-z]+$/i', $color)) {
            return strtolower($color);
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch)\([0-9a-z.+\-%,\/\s]+\)$/i', $color)) {
            return $color;
        }
        return null;
    }

    protected function renderFieldStart(string $key, array $param, int|string $layoutId): string
    {
        $p = self::CSS_PREFIX;
        $translatable = self::isTranslatable($param);
        $fieldClass = $translatable ? $p . 'field ' . $p . 'translatable' : $p . 'field';
        return '<div class="' . $fieldClass . '" data-field-key="' . htmlspecialchars($key) . '" data-translatable="' . ($translatable ? 'true' : 'false') . '">';
    }

    /**
     * 统一多语言包装入口
     *
     * translatable=true  → 字段头（label + 多语言按钮）+ 输入区 + i18n 弹窗容器
     * translatable=false → 字段头（仅 label）+ 输入区
     *
     * $context 可含 array_key / array_index，用于数组子字段的 data 属性。
     */
    public function renderTranslatableWrap(
        string $key,
        array $param,
        int|string $layoutId,
        string $inputHtml,
        array $context = []
    ): string {
        $p = self::CSS_PREFIX;
        $translatable = self::isTranslatable($param);
        $label = $param['label'] ?? $param['name'] ?? $key;
        $fieldId = $this->generateFieldId($key, $layoutId);
        $required = $param['required'] ?? false;

        $html = '<div class="' . $p . 'field-header">';
        $html .= '<label class="' . $p . 'label" for="' . htmlspecialchars($fieldId) . '">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="' . $p . 'required">*</span>';
        }
        $html .= '</label>';

        if ($translatable) {
            $identityHtml = self::widgetIdentityAttrHtml($layoutId);
            $dataAttrs = 'data-field="' . htmlspecialchars($key) . '"'
                . ($identityHtml !== '' ? ' ' . $identityHtml : '');
            if (!empty($context['array_key'])) {
                $dataAttrs .= ' data-array-key="' . htmlspecialchars($context['array_key']) . '"';
            }
            if (isset($context['array_index'])) {
                $dataAttrs .= ' data-array-index="' . htmlspecialchars((string)$context['array_index']) . '"';
            }
            $html .= '<button type="button" class="w-button w-param-btn-i18n" data-tone="neutral" data-variant="outline" data-size="sm" aria-expanded="false" ' . $dataAttrs . ' title="' . __('编辑多语言') . '">';
            $html .= '<span>' . __('多语言') . '</span>';
            $html .= '</button>';
        }

        $html .= '</div>';

        $html .= '<div class="' . $p . 'field-input">' . $inputHtml . '</div>';

        if ($translatable) {
            $panelContext = $context;
            $panelContext['ui_type'] = ParamDefinition::resolveUiType($param);
            $panelContext['is_image'] = ParamDefinition::isImageUiType($param);
            $html .= $this->renderI18nPanel($key, $layoutId, $panelContext);
        }

        return $html;
    }

    /**
     * 统一 i18n 弹窗：空容器，由前端动态填充语言列表；经 Weline.UI.dialog 打开
     */
    protected function renderI18nPanel(string $key, int|string $layoutId, array $context = []): string
    {
        $p = self::CSS_PREFIX;
        $panelId = 'i18n_panel_' . $layoutId . '_' . str_replace('.', '_', $key);
        $uiType = (string)($context['ui_type'] ?? '');
        $isImage = !empty($context['is_image']) || ParamDefinition::isImageUiType(['ui_type' => $uiType]);
        $titleId = $panelId . '_title';

        $identityHtml = self::widgetIdentityAttrHtml($layoutId);
        $dataAttrs = 'data-field="' . htmlspecialchars($key) . '"'
            . ($identityHtml !== '' ? ' ' . $identityHtml : '');
        if ($uiType !== '') {
            $dataAttrs .= ' data-ui-type="' . htmlspecialchars($uiType) . '"';
        }
        if ($isImage) {
            $dataAttrs .= ' data-i18n-media="1"';
        }
        if (!empty($context['array_key'])) {
            $dataAttrs .= ' data-array-key="' . htmlspecialchars($context['array_key']) . '"';
        }
        if (isset($context['array_index'])) {
            $dataAttrs .= ' data-array-index="' . htmlspecialchars((string)$context['array_index']) . '"';
        }

        $html = '<dialog class="w-dialog ' . $p . 'i18n-panel ' . $p . 'i18n-dialog" id="'
            . htmlspecialchars($panelId) . '" ' . $dataAttrs
            . ' data-w-component="dialog" data-w-closable="false" data-w-backdrop="static"'
            . ' data-state="closed" aria-hidden="true" aria-labelledby="'
            . htmlspecialchars($titleId) . '" hidden>';
        $html .= '<div class="w-dialog__surface">';
        $html .= '<header class="w-dialog__header ' . $p . 'i18n-header">';
        $html .= '<h2 class="w-dialog__title" id="' . htmlspecialchars($titleId) . '">' . __('多语言配置') . '</h2>';
        $html .= '<div class="' . $p . 'i18n-header-actions">';
        if (!$isImage) {
            $html .= '<button type="button" class="w-button w-param-btn-ai-i18n" data-tone="neutral" data-variant="outline" data-size="sm" data-ai-i18n ' . $dataAttrs . '>' . __('AI翻译') . '</button>';
        }
        $html .= '<button type="button" class="w-button" data-tone="quiet" data-size="sm" data-icon-only="true" data-close-i18n data-field="' . htmlspecialchars($key) . '" aria-label="' . htmlspecialchars((string)__('关闭多语言配置'), ENT_QUOTES, 'UTF-8') . '"><w-icon name="close" size="sm"></w-icon></button>';
        $html .= '</div>';
        $html .= '</header>';
        // 空 body，由前端 fetchInstalledLocales() 后动态填充
        $html .= '<div class="w-dialog__body ' . $p . 'i18n-body"></div>';
        $html .= '<footer class="w-dialog__footer ' . $p . 'i18n-footer">';
        $html .= '<button type="button" class="w-button" data-tone="primary" data-size="sm" data-save-i18n ' . $dataAttrs . '>' . __('保存多语言') . '</button>';
        $html .= '</footer></div></dialog>';
        return $html;
    }

    protected function renderFieldDescription(array $param): string
    {
        $description = $param['description'] ?? '';
        if (empty($description)) {
            return '';
        }
        return '<div class="' . self::CSS_PREFIX . 'field-desc">' . htmlspecialchars($description) . '</div>';
    }

    protected function renderFieldEnd(): string
    {
        return '</div>';
    }

    /**
     * 完整字段包装：外层 div + renderTranslatableWrap + description + 关闭 div
     */
    protected function wrapField(string $key, array $param, string $inputHtml, int|string $layoutId): string
    {
        $html = $this->renderFieldStart($key, $param, $layoutId);
        $html .= $this->renderTranslatableWrap($key, $param, $layoutId, $inputHtml);
        $html .= $this->renderFieldDescription($param);
        $html .= $this->renderFieldEnd();
        return $html;
    }

    public function validate(mixed $value, array $param): bool
    {
        $required = $param['required'] ?? false;
        if ($required && ($value === null || $value === '')) {
            return false;
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return $value;
    }

    public function getDefaultValue(array $param): mixed
    {
        return $param['default'] ?? null;
    }
}
