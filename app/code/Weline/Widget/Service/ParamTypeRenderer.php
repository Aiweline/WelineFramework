<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Form\FormRenderer;
use Weline\Widget\Api\Param\ParamFormRendererInterface;
use Weline\Widget\Ui\ParamType\AbstractParamType;
use Weline\Widget\Ui\ParamType\ArrayType;
use Weline\Widget\Ui\ParamType\BoolType;
use Weline\Widget\Ui\ParamType\ColorType;
use Weline\Widget\Ui\ParamType\DatetimeType;
use Weline\Widget\Ui\ParamType\IconType;
use Weline\Widget\Ui\ParamType\ImageType;
use Weline\Widget\Ui\ParamType\MediaImageType;
use Weline\Widget\Ui\ParamType\NavTreeType;
use Weline\Widget\Ui\ParamType\NumberType;
use Weline\Widget\Ui\ParamType\RangeType;
use Weline\Widget\Ui\ParamType\QuerySelectType;
use Weline\Widget\Ui\ParamType\SelectType;
use Weline\Widget\Ui\ParamType\StringType;
use Weline\Widget\Ui\ParamType\TextareaType;
use Weline\Widget\Ui\ParamType\UrlType;
use Weline\Widget\Ui\ParamType\WidgetParamTypeInterface;

/**
 * Widget 参数类型渲染服务（类型归一：未识别的 type 视为 text）
 */
class ParamTypeRenderer implements ParamFormRendererInterface
{
    private array $typeRenderers = [];

    private const DEFAULT_TYPE_CLASSES = [
        'string'   => StringType::class,
        'text'     => StringType::class,
        'number'   => NumberType::class,
        'int'      => NumberType::class,
        'integer'  => NumberType::class,
        'float'    => NumberType::class,
        'bool'     => BoolType::class,
        'boolean'  => BoolType::class,
        'select'   => SelectType::class,
        'dropdown' => SelectType::class,
        'color'    => ColorType::class,
        'url'      => UrlType::class,
        'link'     => UrlType::class,
        'image'    => ImageType::class,
        'media_image' => MediaImageType::class,
        'file'     => ImageType::class,
        'array'    => ArrayType::class,
        'list'     => ArrayType::class,
        'nav_tree' => NavTreeType::class,
        'textarea' => TextareaType::class,
        'html'     => TextareaType::class,
        'richtext' => TextareaType::class,
        'datetime' => DatetimeType::class,
        'date'     => DatetimeType::class,
        'time'     => DatetimeType::class,
        'range'    => RangeType::class,
        'slider'   => RangeType::class,
        'icon'     => IconType::class,
        'query_select' => QuerySelectType::class,
    ];

    /**
     * Optional business types (module may be absent). Keep Widget free of hard Product require.
     *
     * @var array<string, class-string<WidgetParamTypeInterface>>
     */
    private const OPTIONAL_TYPE_CLASSES = [
        'product_picker' => 'Weline\\Product\\Ui\\ParamType\\ProductPickerType',
    ];

    /**
     * 类型归一：未在支持列表中的 type 视为 text
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (isset(self::DEFAULT_TYPE_CLASSES[$type])) {
            return $type;
        }
        if (isset(self::OPTIONAL_TYPE_CLASSES[$type]) && class_exists(self::OPTIONAL_TYPE_CLASSES[$type])) {
            return $type;
        }

        return 'text';
    }

    public function getRenderer(string $type): WidgetParamTypeInterface
    {
        $type = $this->normalizeType($type);
        if (isset($this->typeRenderers[$type])) {
            return $this->typeRenderers[$type];
        }
        $rendererClass = self::DEFAULT_TYPE_CLASSES[$type]
            ?? ((isset(self::OPTIONAL_TYPE_CLASSES[$type]) && class_exists(self::OPTIONAL_TYPE_CLASSES[$type]))
                ? self::OPTIONAL_TYPE_CLASSES[$type]
                : self::DEFAULT_TYPE_CLASSES['text']);
        $this->typeRenderers[$type] = ObjectManager::getInstance($rendererClass);
        return $this->typeRenderers[$type];
    }

    public function registerRenderer(string $type, WidgetParamTypeInterface $renderer): self
    {
        $this->typeRenderers[strtolower($type)] = $renderer;
        return $this;
    }

    public function renderField(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $dataType = (string)($param['type'] ?? 'string');
        $type = $this->normalizeType($this->resolveUiType($param));
        $param = array_merge($param, [
            'type' => $type,
            'data_type' => $dataType,
            'ui_type' => $type,
            'input' => $type,
        ]);
        $value = $this->normalizeValueForRender($param, $value);
        return $this->getRenderer($type)->getHtml($key, $param, $value, $layoutId, $attrs);
    }

    public function renderForm(int|string $layoutId, array $params, array $config = [], array $options = []): string
    {
        $config = $this->materializeConfigPaths($config);
        if (empty($params)) {
            return $this->renderEmptyState((string)($options['empty_message'] ?? ''));
        }
        if (!empty($options)) {
            return $this->renderFormWithOptions($layoutId, $params, $config, $options);
        }
        $formClass = trim((string)($options['class'] ?? 'w-param-form')) ?: 'w-param-form';
        $autoSave = array_key_exists('auto_save', $options) ? (bool)$options['auto_save'] : true;
        $showDeleteButton = array_key_exists('delete_button', $options) ? (bool)$options['delete_button'] : true;
        $actionsHtml = (string)($options['actions_html'] ?? '');

        $groupsHtml = $this->renderGroups($layoutId, $params, $config);
        $identityAttrs = AbstractParamType::widgetIdentityAttrMap($layoutId);
        $deleteIdentityHtml = AbstractParamType::widgetIdentityAttrHtml($layoutId);
        $deleteIdentitySuffix = $deleteIdentityHtml !== '' ? ' ' . $deleteIdentityHtml : '';
        return FormRenderer::open(\array_merge([
                'class' => 'w-param-form',
                'method' => 'post',
                'data-auto-save' => '1',
                'intent' => 'widget.parameters',
            ], $identityAttrs)) . '
                ' . $groupsHtml . '
                <div class="w-param-actions">
                    <span class="w-theme-editor-autosave-status" data-widget-autosave-status="1" data-state="idle" hidden></span>
                    <button type="submit" class="w-button w-param-btn-save-widget" data-tone="neutral" data-variant="outline">' . __('立即保存') . '</button>
                    <button type="button" class="w-button w-param-btn-delete-widget" data-tone="danger" data-variant="outline"' . $deleteIdentitySuffix . '>' . __('删除') . '</button>
                </div>
            ' . FormRenderer::close();
    }

    private function renderFormWithOptions(int|string $layoutId, array $params, array $config, array $options): string
    {
        $formClass = trim((string)($options['class'] ?? 'w-param-form')) ?: 'w-param-form';
        $autoSave = array_key_exists('auto_save', $options) ? (bool)$options['auto_save'] : true;
        $showSaveButton = array_key_exists('save_button', $options) ? (bool)$options['save_button'] : true;
        $showDeleteButton = array_key_exists('delete_button', $options) ? (bool)$options['delete_button'] : true;
        $actionsHtml = (string)($options['actions_html'] ?? '');

        $groupsHtml = $this->renderGroups($layoutId, $params, $config);
        $deleteIdentityHtml = AbstractParamType::widgetIdentityAttrHtml($layoutId);
        $deleteIdentitySuffix = $deleteIdentityHtml !== '' ? ' ' . $deleteIdentityHtml : '';

        if ($showDeleteButton) {
            $actionsHtml = '<button type="button" class="w-button w-param-btn-delete-widget" data-tone="danger" data-variant="outline"' . $deleteIdentitySuffix . '>' . __('删除') . '</button>' . $actionsHtml;
        }
        if ($showSaveButton) {
            $actionsHtml = '<span class="w-theme-editor-autosave-status" data-widget-autosave-status="1" data-state="idle" hidden></span>'
                . '<button type="submit" class="w-button w-param-btn-save-widget" data-tone="neutral" data-variant="outline">' . __('立即保存') . '</button>'
                . $actionsHtml;
        }

        $actionsBlock = $actionsHtml !== '' ? '<div class="w-param-actions">' . $actionsHtml . '</div>' : '';
        $identityAttrs = AbstractParamType::widgetIdentityAttrMap($layoutId);

        return FormRenderer::open(\array_merge([
                'class' => $formClass,
                'method' => 'post',
                'data-auto-save' => $autoSave ? '1' : '0',
                'intent' => 'widget.parameters',
            ], $identityAttrs)) . '
                ' . $groupsHtml . '
                ' . $actionsBlock . '
            ' . FormRenderer::close();
    }

    private function renderGroups(int|string $layoutId, array $params, array $config): string
    {
        $groupsHtml = '';
        foreach ($this->groupFields($params) as $groupKey => $groupData) {
            $fieldsHtml = '';
            foreach ($groupData['fields'] as $key => $param) {
                $fieldsHtml .= $this->renderField($key, $param, $config[$key] ?? null, $layoutId);
            }

            $collapsed = (bool)($groupData['collapsed'] ?? false);
            $groupClass = 'w-param-group' . ($collapsed ? ' w-param-collapsed' : '');
            $groupState = $collapsed ? 'closed' : 'open';
            $expanded = $collapsed ? 'false' : 'true';
            $fieldsId = 'w_param_group_' . substr(sha1((string)$layoutId . ':' . (string)$groupKey), 0, 12);
            $hidden = $collapsed ? ' hidden' : '';
            $groupsHtml .= '
                <div class="' . $groupClass . '" data-state="' . $groupState . '">
                    <button type="button" class="w-param-group-title" data-w-param-group-toggle aria-expanded="' . $expanded . '" aria-controls="' . $fieldsId . '">
                        <span>' . htmlspecialchars((string)$groupData['label'], ENT_QUOTES, 'UTF-8') . '</span>
                        <span class="w-param-toggle" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="w-param-fields" id="' . $fieldsId . '"' . $hidden . '>' . $fieldsHtml . '</div>
                </div>
            ';
        }
        return $groupsHtml;
    }

    private function groupFields(array $params): array
    {
        $groups = [
            'basic'   => ['label' => __('基本信息'), 'icon' => 'info', 'collapsed' => false, 'fields' => []],
            'style'   => ['label' => __('样式设置'), 'icon' => 'palette', 'collapsed' => false, 'fields' => []],
            'link'    => ['label' => __('链接配置'), 'icon' => 'link', 'collapsed' => true, 'fields' => []],
            'advanced'=> ['label' => __('高级设置'), 'icon' => 'settings', 'collapsed' => true, 'fields' => []],
        ];
        $socialKeys = ['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'tiktok', 'weibo', 'wechat', 'github', 'telegram', 'whatsapp', 'discord', 'reddit', 'snapchat'];
        // Media/source URLs stay in 基本信息 — burying video_url in collapsed 链接配置 hid the only field YouTube needs.
        $mediaSourceKeyExact = ['src', 'source', 'poster', 'file', 'path'];
        $mediaSourceKeyPrefixes = ['video_', 'audio_', 'media_', 'stream_', 'file_', 'poster_', 'image_src', 'cover_'];
        foreach ($params as $key => $param) {
            if (isset($param['group']) && isset($groups[$param['group']])) {
                $groups[$param['group']]['fields'][$key] = $param;
                continue;
            }
            $keyLower = strtolower((string)$key);
            $isMediaSourceKey = in_array($keyLower, $mediaSourceKeyExact, true);
            if (!$isMediaSourceKey) {
                foreach ($mediaSourceKeyPrefixes as $prefix) {
                    if (str_starts_with($keyLower, $prefix)) {
                        $isMediaSourceKey = true;
                        break;
                    }
                }
            }
            if ($isMediaSourceKey) {
                $groups['basic']['fields'][$key] = $param;
            } elseif (in_array($keyLower, $socialKeys, true) || str_contains($keyLower, 'url') || str_contains($keyLower, 'link') || str_contains($keyLower, 'http')) {
                $groups['link']['fields'][$key] = $param;
            } elseif (str_contains($keyLower, 'style') || str_contains($keyLower, 'size') || str_contains($keyLower, 'color') || str_contains($keyLower, 'align') || str_contains($keyLower, 'gap') || str_contains($keyLower, 'margin') || str_contains($keyLower, 'padding')) {
                $groups['style']['fields'][$key] = $param;
            } else {
                $groups['basic']['fields'][$key] = $param;
            }
        }
        return array_filter($groups, fn($group) => !empty($group['fields']));
    }

    private function renderEmptyState(string $message = ''): string
    {
        if ($message !== '') {
            return '<div class="w-param-empty-state"><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></div>';
        }
        return '<div class="w-param-empty-state"><p>' . __('该部件无可配置项') . '</p></div>';
    }

    public function validateConfig(array $params, array $values): array
    {
        $errors = [];
        foreach ($params as $key => $param) {
            $value = $values[$key] ?? null;
            $type = $this->normalizeType($this->resolveUiType($param));
            $param = array_merge($param, ['type' => $type, 'ui_type' => $type, 'input' => $type]);
            $renderer = $this->getRenderer($type);
            if (!$renderer->validate($value, $param)) {
                $errors[$key] = sprintf(__('字段 "%s" 的值无效'), $param['label'] ?? $key);
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function processConfig(array $params, array $values): array
    {
        $processed = [];
        foreach ($params as $key => $param) {
            $hasValue = array_key_exists($key, $values);
            $value = $hasValue ? $values[$key] : null;
            $type = $this->normalizeType($this->resolveUiType($param));
            $param = array_merge($param, ['type' => $type, 'ui_type' => $type, 'input' => $type]);
            $renderer = $this->getRenderer($type);
            if (!$hasValue) {
                $processed[$key] = $renderer->getDefaultValue($param);
            } else {
                $processed[$key] = $renderer->processValue($value, $param);
            }
        }
        return $processed;
    }

    public function getRegisteredTypes(): array
    {
        $types = array_keys(self::DEFAULT_TYPE_CLASSES);
        foreach (self::OPTIONAL_TYPE_CLASSES as $code => $class) {
            if (class_exists($class)) {
                $types[] = $code;
            }
        }

        return $types;
    }

    private function resolveUiType(array $param): string
    {
        foreach (['ui_type', 'input', 'ui'] as $key) {
            $value = trim((string)($param[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $semanticType = trim((string)($param['type'] ?? ''));
        return $semanticType !== '' ? $semanticType : 'string';
    }

    private function normalizeValueForRender(array $param, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $param['type'] ?? 'string';

        if ($type === 'array' || $type === 'list') {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return [];
                }
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
            return $value;
        }

        if ($type === 'select' && ($param['multiple'] ?? false)) {
            if (is_array($value)) {
                return $value;
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return [];
                }
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
                if (str_contains($trimmed, ',')) {
                    return array_values(array_filter(array_map('trim', explode(',', $trimmed)), static fn($item) => $item !== ''));
                }
                return [$trimmed];
            }
            return [$value];
        }

        return $value;
    }

    /** @param array<string|int,mixed> $configData @return array<string|int,mixed> */
    private function materializeConfigPaths(array $configData, array $base = []): array
    {
        foreach ($configData as $key => $value) {
            $key = (string)$key;
            if ($key === '' || !str_contains($key, '.')) {
                if ($key !== '') {
                    $base[$key] = $value;
                }
                continue;
            }
            $segments = explode('.', $key);
            if (in_array('', $segments, true)) {
                continue;
            }
            $cursor =& $base;
            $last = array_pop($segments);
            foreach ($segments as $segment) {
                $segment = preg_match('/^(?:0|[1-9][0-9]*)$/D', $segment) === 1
                    ? (int)$segment
                    : $segment;
                if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }
                $cursor =& $cursor[$segment];
            }
            $last = preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$last) === 1
                ? (int)$last
                : (string)$last;
            $cursor[$last] = $value;
            unset($cursor);
        }

        return $base;
    }
}
