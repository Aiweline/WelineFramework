<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\App\Exception;
use Weline\Framework\Taglib\TaglibInterface;

final class Field implements TaglibInterface
{
    public static function name(): string
    {
        return 'field';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [
            'name' => true,
            'belong' => false,
            'sortable' => false,
            'width' => false,
            'visible' => false,
            'editable' => false,
            'searchable' => false,
            'type' => false,
            'label' => false,
            'placeholder' => false,
            'options' => false,
            'class' => false,
            'default' => false,
            'required' => false,
            'readonly' => false,
            'disabled' => false,
            'min' => false,
            'max' => false,
            'maxlength' => false,
            'step' => false,
            'multiple' => false,
            'max-size' => false,
        ];
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
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $name = trim((string)($attributes['name'] ?? ''));
            if ($name === '') {
                throw new Exception(__('field标签必须指定name属性！'));
            }

            $belong = trim((string)($attributes['belong'] ?? ''));
            if ($belong === '') {
                $stack = TableContext::getRenderStack();
                $current = is_array($stack) ? end($stack) : null;
                $belong = is_array($current) ? (string)($current['type'] ?? '') : '';
            }
            if (!in_array($belong, ['t-header', 't-filter', 'd-form'], true)) {
                throw new Exception(__('field标签的belong必须是t-header、t-filter或d-form。'));
            }

            $context = TableContext::getRenderStack($belong);
            $accessContext = is_array($context['attributes'] ?? null) ? $context['attributes'] : [];
            if (!FrontendAccess::isAllowed($attributes, $accessContext)) {
                return FrontendAccess::deniedComment('field:' . $name);
            }

            $content = trim((string)($tagData[2] ?? ''));
            $field = self::normalizeField($name, $belong, $content, $attributes, $context);
            $scope = (string)($context['scope'] ?? $accessContext['scope'] ?? 'datatable');
            TableContext::recordTemplateField($scope, $belong, $name, $field);

            return match ($belong) {
                't-header' => self::renderHeader($field),
                't-filter' => self::renderFilter($field),
                'd-form' => self::renderForm($field, $context),
            };
        };
    }

    /** @return array<string,mixed> */
    private static function normalizeField(string $name, string $belong, string $content, array $attributes, array $context): array
    {
        $type = strtolower(trim((string)($attributes['type'] ?? '')));
        if ($type === '') {
            $type = self::inferType($name);
        }
        $allowedTypes = [
            'text', 'search', 'email', 'tel', 'url', 'password', 'number', 'date', 'datetime',
            'time', 'textarea', 'select', 'checkbox', 'radio', 'switch', 'range', 'color',
            'file', 'image', 'hidden',
        ];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $label = trim((string)($attributes['label'] ?? $content));
        if ($label === '') {
            $label = self::humanize($name);
        }
        $placeholder = trim((string)($attributes['placeholder'] ?? ''));
        if ($placeholder === '' && !in_array($type, ['select', 'checkbox', 'radio', 'switch', 'file', 'image', 'hidden'], true)) {
            $placeholder = (string)__('请输入%{1}', [$label]);
        }
        $width = self::cssLength((string)($attributes['width'] ?? ''));
        $defaultVisible = $belong !== 't-filter';

        return [
            'name' => $name,
            'belong' => $belong,
            'label' => $label,
            'content' => $content,
            'type' => $type,
            'placeholder' => $placeholder,
            'options' => self::options($attributes['options'] ?? ''),
            'class' => self::welineClasses((string)($attributes['class'] ?? '')),
            'sortable' => self::toBool($attributes['sortable'] ?? false),
            'visible' => array_key_exists('visible', $attributes) ? self::toBool($attributes['visible']) : $defaultVisible,
            'editable' => self::toBool($attributes['editable'] ?? false),
            'searchable' => array_key_exists('searchable', $attributes) ? self::toBool($attributes['searchable']) : true,
            'required' => self::toBool($attributes['required'] ?? false),
            'readonly' => self::toBool($attributes['readonly'] ?? false),
            'disabled' => self::toBool($attributes['disabled'] ?? false),
            'multiple' => self::toBool($attributes['multiple'] ?? false),
            'default' => (string)($attributes['default'] ?? ''),
            'min' => self::scalar((string)($attributes['min'] ?? '')),
            'max' => self::scalar((string)($attributes['max'] ?? '')),
            'maxlength' => self::positiveInteger((string)($attributes['maxlength'] ?? '')),
            'step' => self::scalar((string)($attributes['step'] ?? '')),
            'width' => $width,
            'maxSize' => self::fileSize((string)($attributes['max-size'] ?? '')),
            'formId' => self::normalizeId((string)($context['attributes']['id'] ?? $context['scope'] ?? 'form')),
        ];
    }

    private static function renderHeader(array $field): string
    {
        $name = self::escape((string)$field['name']);
        $label = self::escape((string)$field['label']);
        $config = self::jsonAttribute($field);
        $style = $field['width'] !== ''
            ? ' style="--w-column-width:' . self::escape((string)$field['width']) . '"'
            : '';
        $hidden = $field['visible'] ? '' : ' hidden';
        if ($field['sortable']) {
            $label = '<button type="button" class="w-datatable__sort" data-w-datatable-action="sort" data-field="'
                . $name . '" aria-label="' . self::escape((string)__('按%{1}排序', [$field['label']])) . '">'
                . '<span>' . $label . '</span><w-icon name="sort" size="xs"></w-icon></button>';
        }
        return '<th data-field="' . $name . '" data-sortable="' . ($field['sortable'] ? 'true' : 'false')
            . '" data-w-field="' . $config . '"' . $style . $hidden . '>' . $label . '</th>';
    }

    private static function renderFilter(array $field): string
    {
        $name = (string)$field['name'];
        $id = 'w-filter-' . self::normalizeId($name);
        $idHtml = self::escape($id);
        $nameHtml = self::escape($name);
        $label = self::escape((string)$field['label']);
        $config = self::jsonAttribute($field);
        $class = trim('w-datatable__filter-field ' . (string)$field['class']);
        $common = ' id="' . $idHtml . '" name="filter[' . $nameHtml . ']" data-field="' . $nameHtml . '"';

        if ($field['type'] === 'select') {
            $control = '<select class="w-select"' . $common . '><option value="">' . self::escape((string)__('全部')) . '</option>';
            foreach ($field['options'] as $option) {
                $control .= '<option value="' . self::escape((string)$option['value']) . '">' . self::escape((string)$option['label']) . '</option>';
            }
            $control .= '</select>';
        } elseif (in_array($field['type'], ['checkbox', 'switch'], true)) {
            $control = '<label class="w-check"><input type="checkbox"' . $common . ' value="1"><span>' . $label . '</span></label>';
            $label = '';
        } else {
            $type = in_array($field['type'], ['text', 'search', 'email', 'tel', 'url', 'number', 'date', 'time'], true)
                ? $field['type']
                : 'text';
            $control = '<input type="' . self::escape((string)$type) . '" class="w-input"' . $common
                . ' placeholder="' . self::escape((string)$field['placeholder']) . '">';
        }

        $labelHtml = $label === '' ? '' : '<label class="w-visually-hidden" for="' . $idHtml . '">' . $label . '</label>';
        return '<div class="' . self::escape($class) . '" data-field="' . $nameHtml . '" data-w-field="' . $config . '">'
            . $labelHtml . $control . '</div>';
    }

    private static function renderForm(array $field, array $context): string
    {
        $name = (string)$field['name'];
        $id = 'w-field-' . self::normalizeId((string)$field['formId'] . '-' . $name);
        $idHtml = self::escape($id);
        $nameHtml = self::escape($name);
        $label = self::escape((string)$field['label']);
        $config = self::jsonAttribute($field);
        $class = trim('w-field w-datatable-form__field ' . (string)$field['class']);
        $attributes = self::controlAttributes($field);
        $required = $field['required'] ? '<span class="w-datatable-form__required" aria-hidden="true">*</span>' : '';

        if ($field['type'] === 'hidden') {
            return '<input type="hidden" id="' . $idHtml . '" name="' . $nameHtml . '" value="'
                . self::escape((string)$field['default']) . '" data-w-field="' . $config . '">';
        }

        if (self::containsTrustedMarkup((string)$field['content'])) {
            return '<div class="' . self::escape($class) . '" data-field="' . $nameHtml . '" data-w-field="'
                . $config . '">' . (string)$field['content'] . '</div>';
        }

        $control = self::formControl($field, $idHtml, $nameHtml, $attributes);
        return '<div class="' . self::escape($class) . '" data-field="' . $nameHtml . '" data-type="'
            . self::escape((string)$field['type']) . '" data-w-field="' . $config . '">'
            . '<label class="w-field__label" for="' . $idHtml . '">' . $label . $required . '</label>'
            . $control . '<div class="w-field__error" data-w-field-error hidden></div></div>';
    }

    private static function formControl(array $field, string $id, string $name, string $attributes): string
    {
        $type = (string)$field['type'];
        $placeholder = self::escape((string)$field['placeholder']);
        $value = self::escape((string)$field['default']);
        if ($type === 'textarea') {
            return '<textarea class="w-input" id="' . $id . '" name="' . $name . '" rows="4" placeholder="'
                . $placeholder . '"' . $attributes . '>' . $value . '</textarea>';
        }
        if ($type === 'select') {
            $html = '<select class="w-select" id="' . $id . '" name="' . $name . '"' . $attributes . '><option value="">'
                . self::escape((string)__('请选择')) . '</option>';
            foreach ($field['options'] as $option) {
                $selected = (string)$field['default'] === (string)$option['value'] ? ' selected' : '';
                $html .= '<option value="' . self::escape((string)$option['value']) . '"' . $selected . '>'
                    . self::escape((string)$option['label']) . '</option>';
            }
            return $html . '</select>';
        }
        if (in_array($type, ['checkbox', 'switch'], true)) {
            $checked = self::toBool($field['default']) ? ' checked' : '';
            return '<label class="w-check"><input type="checkbox" id="' . $id . '" name="' . $name
                . '" value="1"' . $checked . $attributes . '><span>' . self::escape((string)$field['label']) . '</span></label>';
        }
        if ($type === 'radio') {
            $html = '<div class="w-cluster" role="radiogroup">';
            foreach ($field['options'] as $index => $option) {
                $optionId = $id . '-' . $index;
                $checked = (string)$field['default'] === (string)$option['value'] ? ' checked' : '';
                $html .= '<label class="w-check"><input type="radio" id="' . self::escape($optionId) . '" name="'
                    . $name . '" value="' . self::escape((string)$option['value']) . '"' . $checked . $attributes . '><span>'
                    . self::escape((string)$option['label']) . '</span></label>';
            }
            return $html . '</div>';
        }
        if (in_array($type, ['file', 'image'], true)) {
            $accept = self::escape(self::accept((string)($field['options'][0]['value'] ?? '')));
            $acceptAttr = $accept === '' ? '' : ' accept="' . $accept . '"';
            $icon = $type === 'image' ? 'image' : 'upload';
            $label = $type === 'image' ? (string)__('选择图片') : (string)__('选择文件');
            return '<input type="file" id="' . $id . '" name="' . $name . '" hidden' . $acceptAttr . $attributes
                . ' data-max-size="' . self::escape((string)$field['maxSize']) . '">'
                . '<div class="w-datatable-form__file"><button type="button" class="w-button" data-tone="neutral"'
                . ' data-w-datatable-form-action="file.choose" data-w-target="#' . $id . '"><w-icon name="' . $icon
                . '" size="sm"></w-icon><span>' . self::escape($label) . '</span></button>'
                . '<div class="w-datatable-form__file-preview" data-w-file-preview aria-live="polite"></div></div>';
        }

        $inputType = in_array($type, ['text', 'search', 'email', 'tel', 'url', 'password', 'number', 'date', 'time', 'range', 'color'], true)
            ? $type
            : ($type === 'datetime' ? 'datetime-local' : 'text');
        return '<input type="' . self::escape($inputType) . '" class="w-input" id="' . $id . '" name="' . $name
            . '" value="' . $value . '" placeholder="' . $placeholder . '"' . $attributes . '>';
    }

    private static function controlAttributes(array $field): string
    {
        $parts = [];
        foreach (['required', 'readonly', 'disabled', 'multiple'] as $name) {
            if (!empty($field[$name])) {
                $parts[] = $name;
            }
        }
        foreach (['min', 'max', 'maxlength', 'step'] as $name) {
            if (($field[$name] ?? '') !== '') {
                $parts[] = $name . '="' . self::escape((string)$field[$name]) . '"';
            }
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /** @return list<array{value:string,label:string}> */
    private static function options(mixed $value): array
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $label) {
                if (is_array($label)) {
                    $result[] = [
                        'value' => (string)($label['value'] ?? $key),
                        'label' => (string)($label['label'] ?? $label['value'] ?? $key),
                    ];
                } else {
                    $result[] = ['value' => (string)$key, 'label' => (string)$label];
                }
            }
            return $result;
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }
        $result = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));
            $result[] = ['value' => $parts[0], 'label' => $parts[1] ?? $parts[0]];
        }
        return $result;
    }

    private static function inferType(string $name): string
    {
        $name = strtolower($name);
        return match (true) {
            str_contains($name, 'email') => 'email',
            str_contains($name, 'phone'), str_contains($name, 'mobile') => 'tel',
            str_contains($name, 'password') => 'password',
            str_contains($name, 'date'), str_contains($name, 'time') => 'datetime',
            str_contains($name, 'price'), str_contains($name, 'amount'), str_ends_with($name, '_id'), $name === 'id' => 'number',
            str_contains($name, 'status'), str_contains($name, 'type') => 'select',
            default => 'text',
        };
    }

    private static function humanize(string $name): string
    {
        $name = str_replace(['.', '_', '-'], ' ', $name);
        return ucwords(trim($name));
    }

    private static function containsTrustedMarkup(string $content): bool
    {
        return $content !== '' && strip_tags($content) !== $content;
    }

    private static function accept(string $value): string
    {
        return preg_match('/^[A-Za-z0-9*+.,_\/-]+$/', $value) ? $value : '';
    }

    private static function fileSize(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^\d+(?:\.\d+)?(?:KB|MB|GB)$/', $value) ? $value : '';
    }

    private static function positiveInteger(string $value): string
    {
        return ctype_digit($value) && (int)$value > 0 ? $value : '';
    }

    private static function scalar(string $value): string
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', trim($value)) ? trim($value) : '';
    }

    private static function cssLength(string $value): string
    {
        $value = trim($value);
        if (ctype_digit($value)) {
            return $value . 'px';
        }
        return preg_match('/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|ch))$/', $value) ? $value : '';
    }

    private static function normalizeId(string $id): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($id)) ?: 'field';
        return trim($id, '-') ?: 'field';
    }

    private static function welineClasses(string $classes): string
    {
        return implode(' ', array_values(array_filter(
            preg_split('/\s+/', trim($classes)) ?: [],
            static fn (string $class): bool => preg_match('/^w-[a-z0-9_-]+$/', $class) === 1
        )));
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function jsonAttribute(array $value): string
    {
        unset($value['content']);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return self::escape($json === false ? '{}' : $json);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function resetRequestState(): void
    {
        TableContext::clearAll();
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<'DOC'
<w:field belong="t-header" name="name" sortable="true">名称</w:field>
<w:field belong="t-filter" name="name" type="search"></w:field>
<w:field belong="d-form" name="name" type="text" required="true">名称</w:field>
DOC;
    }
}
