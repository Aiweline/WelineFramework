<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Api\Param\ParamFormRendererInterface;
use Weline\Widget\Ui\ParamType\ArrayType;
use Weline\Widget\Ui\ParamType\BoolType;
use Weline\Widget\Ui\ParamType\ColorType;
use Weline\Widget\Ui\ParamType\DatetimeType;
use Weline\Widget\Ui\ParamType\IconType;
use Weline\Widget\Ui\ParamType\ImageType;
use Weline\Widget\Ui\ParamType\MediaImageType;
use Weline\Widget\Ui\ParamType\NumberType;
use Weline\Widget\Ui\ParamType\RangeType;
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
        'textarea' => TextareaType::class,
        'html'     => TextareaType::class,
        'richtext' => TextareaType::class,
        'datetime' => DatetimeType::class,
        'date'     => DatetimeType::class,
        'time'     => DatetimeType::class,
        'range'    => RangeType::class,
        'slider'   => RangeType::class,
        'icon'     => IconType::class,
    ];

    /**
     * 类型归一：未在支持列表中的 type 视为 text
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return isset(self::DEFAULT_TYPE_CLASSES[$type]) ? $type : 'text';
    }

    public function getRenderer(string $type): WidgetParamTypeInterface
    {
        $type = $this->normalizeType($type);
        if (isset($this->typeRenderers[$type])) {
            return $this->typeRenderers[$type];
        }
        $rendererClass = self::DEFAULT_TYPE_CLASSES[$type];
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

        $groups = $this->groupFields($params);
        $groupsHtml = '';
        foreach ($groups as $groupKey => $groupData) {
            $fieldsHtml = '';
            foreach ($groupData['fields'] as $key => $param) {
                $value = $config[$key] ?? null;
                $fieldsHtml .= $this->renderField($key, $param, $value, $layoutId);
            }
            $collapsed = $groupData['collapsed'] ?? false;
            $groupClass = 'w-param-group' . ($collapsed ? ' w-param-collapsed' : '');
            $groupsHtml .= '
                <div class="' . $groupClass . '">
                    <h5 class="w-param-group-title">
                        ' . htmlspecialchars($groupData['label']) . '
                        <span class="w-param-toggle">▾</span>
                    </h5>
                    <div class="w-param-fields">' . $fieldsHtml . '</div>
                </div>
            ';
        }
        return '
            <form class="w-param-form" data-layout-id="' . htmlspecialchars((string)$layoutId) . '" data-auto-save="1">
                ' . $groupsHtml . '
                <div class="w-param-actions">
                    <button type="button" class="w-param-btn w-param-btn-outline-danger w-param-btn-delete-widget" data-layout-id="' . htmlspecialchars((string)$layoutId) . '">' . __('删除') . '</button>
                </div>
            </form>
        ';
    }

    private function renderFormWithOptions(int|string $layoutId, array $params, array $config, array $options): string
    {
        $formClass = trim((string)($options['class'] ?? 'w-param-form')) ?: 'w-param-form';
        $autoSave = array_key_exists('auto_save', $options) ? (bool)$options['auto_save'] : true;
        $showDeleteButton = array_key_exists('delete_button', $options) ? (bool)$options['delete_button'] : true;
        $actionsHtml = (string)($options['actions_html'] ?? '');

        $groups = $this->groupFields($params);
        $groupsHtml = '';
        foreach ($groups as $groupData) {
            $fieldsHtml = '';
            foreach ($groupData['fields'] as $key => $param) {
                $fieldsHtml .= $this->renderField($key, $param, $config[$key] ?? null, $layoutId);
            }

            $collapsed = $groupData['collapsed'] ?? false;
            $groupClass = 'w-param-group' . ($collapsed ? ' w-param-collapsed' : '');
            $groupsHtml .= '
                <div class="' . $groupClass . '">
                    <h5 class="w-param-group-title">
                        ' . htmlspecialchars($groupData['label']) . '
                        <span class="w-param-toggle">&#9662;</span>
                    </h5>
                    <div class="w-param-fields">' . $fieldsHtml . '</div>
                </div>
            ';
        }

        if ($showDeleteButton) {
            $actionsHtml = '<button type="button" class="w-param-btn w-param-btn-outline-danger w-param-btn-delete-widget" data-layout-id="' . htmlspecialchars((string)$layoutId) . '">' . __('鍒犻櫎') . '</button>' . $actionsHtml;
        }

        $actionsBlock = $actionsHtml !== '' ? '<div class="w-param-actions">' . $actionsHtml . '</div>' : '';

        return '
            <form class="' . htmlspecialchars($formClass) . '" data-layout-id="' . htmlspecialchars((string)$layoutId) . '" data-auto-save="' . ($autoSave ? '1' : '0') . '">
                ' . $groupsHtml . '
                ' . $actionsBlock . '
            </form>
        ';
    }

    private function groupFields(array $params): array
    {
        $groups = [
            'basic'   => ['label' => __('基本信息'), 'icon' => 'ri-information-line', 'collapsed' => false, 'fields' => []],
            'style'   => ['label' => __('样式设置'), 'icon' => 'ri-palette-line', 'collapsed' => false, 'fields' => []],
            'link'    => ['label' => __('链接配置'), 'icon' => 'ri-links-line', 'collapsed' => true, 'fields' => []],
            'advanced'=> ['label' => __('高级设置'), 'icon' => 'ri-settings-4-line', 'collapsed' => true, 'fields' => []],
        ];
        $socialKeys = ['facebook', 'twitter', 'instagram', 'youtube', 'linkedin', 'pinterest', 'tiktok', 'weibo', 'wechat', 'github', 'telegram', 'whatsapp', 'discord', 'reddit', 'snapchat'];
        foreach ($params as $key => $param) {
            if (isset($param['group']) && isset($groups[$param['group']])) {
                $groups[$param['group']]['fields'][$key] = $param;
                continue;
            }
            $keyLower = strtolower($key);
            if (in_array($keyLower, $socialKeys) || str_contains($keyLower, 'url') || str_contains($keyLower, 'link') || str_contains($keyLower, 'http')) {
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
        return array_keys(self::DEFAULT_TYPE_CLASSES);
    }

    private function resolveUiType(array $param): string
    {
        foreach (['ui_type', 'input', 'ui', 'type'] as $key) {
            $value = trim((string)($param[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'string';
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
}
